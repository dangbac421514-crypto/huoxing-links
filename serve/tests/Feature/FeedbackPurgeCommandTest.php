<?php

namespace Tests\Feature;

use App\Enums\FeedbackTicketStatus;
use App\Models\FeedbackEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToDeleteFile;
use Mockery;
use Tests\Concerns\CreatesFeedbackFixtures;
use Tests\TestCase;

final class FeedbackPurgeCommandTest extends TestCase
{
    use CreatesFeedbackFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('feedback_private');
    }

    public function test_purge_removes_private_evidence_and_personal_text_but_keeps_anonymous_counts(): void
    {
        $ticket = $this->expiredFeedbackTicket(retentionDays: 30);
        $submittedAt = $ticket->submitted_at;
        $resolvedAt = CarbonImmutable::now('Asia/Shanghai')->subDays(20);
        $ticket->forceFill([
            'contact' => 'contact-secret',
            'content' => 'content-secret',
            'visitor_hash' => str_repeat('ab', 32),
            'category' => '产品问题',
            'status' => FeedbackTicketStatus::RESOLVED,
            'resolved_at' => $resolvedAt,
        ])->save();
        $event = FeedbackEvent::query()->create([
            'feedback_ticket_id' => $ticket->id,
            'user_id' => $ticket->user_id,
            'actor_user_id' => $ticket->user_id,
            'event' => 'note_added',
            'note' => 'internal-secret-note',
            'created_at' => CarbonImmutable::now('Asia/Shanghai'),
        ]);
        $attachment = $this->attachPrivateFixture($ticket);

        $this->artisan('app:feedback-purge')->assertExitCode(0);

        Storage::disk('feedback_private')->assertMissing($attachment->path);
        $this->assertDatabaseMissing('feedback_attachments', ['id' => $attachment->id]);
        $ticket->refresh();
        $this->assertNull($ticket->contact);
        $this->assertNull($ticket->content);
        $this->assertNull($ticket->visitor_hash);
        $this->assertNotNull($ticket->anonymized_at);
        $this->assertDatabaseHas('feedback_tickets', ['id' => $ticket->id, 'public_no' => $ticket->public_no]);
        $this->assertSame('产品问题', $ticket->category);
        $this->assertSame(FeedbackTicketStatus::RESOLVED, $ticket->status);
        $this->assertSame($submittedAt->format('Y-m-d H:i:s'), $ticket->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame($resolvedAt->format('Y-m-d H:i:s'), $ticket->resolved_at->format('Y-m-d H:i:s'));
        $this->assertNull($event->refresh()->note);
        $this->assertDatabaseHas('feedback_events', ['id' => $event->id, 'event' => 'note_added']);
    }

    public function test_visitor_hash_is_nulled_after_30_days_without_anonymizing_in_retention(): void
    {
        $user = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($user, ['retention_days' => 180]);
        $expiredHash = $this->feedbackTicketFor($user, [
            'feedback_channel_id' => $channel->id,
            'submitted_at' => CarbonImmutable::now('Asia/Shanghai')->subDays(31),
            'visitor_hash' => str_repeat('ab', 32),
            'contact' => 'keep-contact',
            'content' => 'keep-content',
        ]);
        $freshHash = $this->feedbackTicketFor($user, [
            'feedback_channel_id' => $channel->id,
            'submitted_at' => CarbonImmutable::now('Asia/Shanghai')->subDays(29),
            'visitor_hash' => str_repeat('cd', 32),
            'contact' => 'fresh-contact',
            'content' => 'fresh-content',
        ]);

        $this->artisan('app:feedback-purge')->assertExitCode(0);

        $expiredHash->refresh();
        $this->assertNull($expiredHash->visitor_hash);
        $this->assertSame('keep-contact', $expiredHash->contact);
        $this->assertSame('keep-content', $expiredHash->content);
        $this->assertNull($expiredHash->anonymized_at);

        $freshHash->refresh();
        $this->assertSame(str_repeat('cd', 32), $freshHash->visitor_hash);
        $this->assertSame('fresh-contact', $freshHash->contact);
        $this->assertNull($freshHash->anonymized_at);
    }

    public function test_fresh_tickets_are_untouched(): void
    {
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser(), [
            'visitor_hash' => str_repeat('cd', 32),
            'contact' => 'fresh-contact',
            'content' => 'fresh-content',
        ]);
        $attachment = $this->attachPrivateFixture($ticket);

        $this->artisan('app:feedback-purge')->assertExitCode(0);

        $ticket->refresh();
        $this->assertSame('fresh-contact', $ticket->contact);
        $this->assertSame('fresh-content', $ticket->content);
        $this->assertSame(str_repeat('cd', 32), $ticket->visitor_hash);
        $this->assertNull($ticket->anonymized_at);
        Storage::disk('feedback_private')->assertExists($attachment->path);
        $this->assertDatabaseHas('feedback_attachments', ['id' => $attachment->id, 'path' => $attachment->path]);
    }

    public function test_repeated_command_is_idempotent(): void
    {
        $ticket = $this->expiredFeedbackTicket(retentionDays: 30);
        $ticket->forceFill([
            'contact' => 'contact-secret',
            'content' => 'content-secret',
            'visitor_hash' => str_repeat('ab', 32),
        ])->save();
        $attachment = $this->attachPrivateFixture($ticket);

        $this->artisan('app:feedback-purge')->assertExitCode(0);
        $first = $ticket->fresh();
        $anonymizedAt = $first->anonymized_at;
        $this->assertNotNull($anonymizedAt);

        $this->artisan('app:feedback-purge')->assertExitCode(0);
        $second = $ticket->fresh();
        $this->assertNull($second->contact);
        $this->assertNull($second->content);
        $this->assertNull($second->visitor_hash);
        $this->assertSame($anonymizedAt->format('Y-m-d H:i:s'), $second->anonymized_at->format('Y-m-d H:i:s'));
        Storage::disk('feedback_private')->assertMissing($attachment->path);
        $this->assertDatabaseMissing('feedback_attachments', ['id' => $attachment->id]);
        $this->assertDatabaseHas('feedback_tickets', ['id' => $ticket->id, 'public_no' => $ticket->public_no]);
    }

    public function test_storage_delete_failure_leaves_personal_row_for_retry_instead_of_claiming_success(): void
    {
        $failing = $this->expiredFeedbackTicket(retentionDays: 30);
        $failing->forceFill([
            'contact' => 'retry-contact',
            'content' => 'retry-content',
            'visitor_hash' => str_repeat('ef', 32),
        ])->save();
        $failingAttachment = $this->attachPrivateFixture($failing);
        $failingNote = FeedbackEvent::query()->create([
            'feedback_ticket_id' => $failing->id,
            'user_id' => $failing->user_id,
            'actor_user_id' => $failing->user_id,
            'event' => 'note_added',
            'note' => 'keep-note-on-failure',
            'created_at' => CarbonImmutable::now('Asia/Shanghai'),
        ]);

        $ok = $this->expiredFeedbackTicket(retentionDays: 30);
        $ok->forceFill([
            'contact' => 'ok-contact',
            'content' => 'ok-content',
        ])->save();
        $okAttachment = $this->attachPrivateFixture($ok);

        $real = Storage::disk('feedback_private');
        $mock = Mockery::mock(Filesystem::class);
        $mock->shouldReceive('delete')->andReturnUsing(function ($path) use ($real, $failingAttachment) {
            if ($path === $failingAttachment->path) {
                throw UnableToDeleteFile::atLocation((string) $path, 'simulated-delete-failure');
            }

            return $real->delete($path);
        });
        Storage::set('feedback_private', $mock);

        $this->artisan('app:feedback-purge')->assertExitCode(1);

        $failing->refresh();
        $this->assertSame('retry-contact', $failing->contact);
        $this->assertSame('retry-content', $failing->content);
        $this->assertNull($failing->anonymized_at);
        $this->assertSame('keep-note-on-failure', $failingNote->refresh()->note);
        $this->assertDatabaseHas('feedback_attachments', [
            'id' => $failingAttachment->id,
            'path' => $failingAttachment->path,
        ]);
        $real->assertExists($failingAttachment->path);

        $ok->refresh();
        $this->assertNull($ok->contact);
        $this->assertNull($ok->content);
        $this->assertNotNull($ok->anonymized_at);
        $this->assertDatabaseMissing('feedback_attachments', ['id' => $okAttachment->id]);
        $real->assertMissing($okAttachment->path);
    }

    public function test_purge_is_scheduled_daily_at_0230_shanghai_without_overlapping(): void
    {
        $events = app(Schedule::class)->events();
        $purge = collect($events)->first(
            fn ($event): bool => str_contains((string) $event->command, 'app:feedback-purge'),
        );
        $this->assertNotNull($purge);
        $this->assertSame('30 2 * * *', $purge->getExpression());
        $this->assertTrue($purge->withoutOverlapping);
        $this->assertSame(120, $purge->expiresAt);
        $this->assertSame('Asia/Shanghai', $purge->timezone);

        $vip = collect($events)->first(
            fn ($event): bool => str_contains((string) $event->command, 'app:vip-expired'),
        );
        $health = collect($events)->first(
            fn ($event): bool => str_contains((string) $event->command, 'app:links-health-check'),
        );
        $this->assertNotNull($vip);
        $this->assertSame('0 * * * *', $vip->getExpression());
        $this->assertTrue($vip->withoutOverlapping);
        $this->assertNotNull($health);
        $this->assertSame('*/10 * * * *', $health->getExpression());
        $this->assertTrue($health->withoutOverlapping);
        $this->assertSame(9, $health->expiresAt);

        $this->artisan('schedule:list')
            ->expectsOutputToContain('php artisan app:feedback-purge')
            ->expectsOutputToContain('php artisan app:vip-expired')
            ->expectsOutputToContain('php artisan app:links-health-check')
            ->assertExitCode(0);
    }
}
