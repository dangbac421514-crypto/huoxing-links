<?php

namespace Tests\Feature;

use App\Enums\FeedbackDeliveryKind;
use App\Enums\FeedbackDeliveryStatus;
use App\Enums\FeedbackTicketStatus;
use App\Enums\UserType;
use App\Models\FeedbackDelivery;
use App\Models\FeedbackEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesFeedbackFixtures;
use Tests\TestCase;

final class FeedbackTicketApiTest extends TestCase
{
    use CreatesFeedbackFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('feedback_private');
    }

    public function test_owner_can_process_ticket_but_foreign_user_gets_404(): void
    {
        $owner = $this->activeFeedbackUser();
        $ticket = $this->feedbackTicketFor($owner);
        Sanctum::actingAs($owner, ['*'], 'api');
        $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'processing'])
            ->assertOk()->assertJsonPath('status', 'processing');
        Sanctum::actingAs($this->activeFeedbackUser(), ['*'], 'api');
        $this->getJson('/api/feedback-tickets/'.$ticket->id)->assertNotFound();
    }

    public function test_invalid_transition_is_rejected_without_event(): void
    {
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser(), ['status' => 'pending']);
        $this->actingAsFeedbackOwner($ticket);
        $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'closed'])->assertStatus(422);
        $this->assertDatabaseCount('feedback_events', 0);
    }

    public function test_allowed_transitions_write_event_and_resolved_at_in_one_transaction(): void
    {
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser());
        $this->actingAsFeedbackOwner($ticket);

        $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'processing'])
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('resolved_at', null);
        $this->assertSame(FeedbackTicketStatus::PROCESSING, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolved_at);
        $this->assertDatabaseHas('feedback_events', [
            'feedback_ticket_id' => $ticket->id,
            'event' => 'status_changed',
            'from_status' => 'pending',
            'to_status' => 'processing',
            'actor_user_id' => $ticket->user_id,
        ]);

        $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('status', 'resolved');
        $resolved = $ticket->fresh();
        $this->assertNotNull($resolved->resolved_at);
        $resolvedAt = $resolved->resolved_at->format('Y-m-d H:i:s');

        $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'processing'])
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('resolved_at', null);
        $this->assertNull($ticket->fresh()->resolved_at);

        $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'resolved'])->assertOk();
        $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('status', 'closed');
        $closed = $ticket->fresh();
        $this->assertSame(FeedbackTicketStatus::CLOSED, $closed->status);
        $this->assertNotNull($closed->resolved_at);
        $this->assertSame(5, FeedbackEvent::query()->where('feedback_ticket_id', $ticket->id)->count());
        $this->assertNotSame($resolvedAt, '');
    }

    public function test_disallowed_transitions_return_invalid_state_without_new_events(): void
    {
        $owner = $this->activeFeedbackUser();
        $pending = $this->feedbackTicketFor($owner, ['status' => 'pending']);
        $processing = $this->feedbackTicketFor($owner, [
            'feedback_channel_id' => $pending->feedback_channel_id,
            'status' => 'processing',
        ]);
        $closed = $this->feedbackTicketFor($owner, [
            'feedback_channel_id' => $pending->feedback_channel_id,
            'status' => 'closed',
        ]);
        $this->actingAsFeedbackOwner($pending);

        foreach ([
            [$pending, 'pending'],
            [$pending, 'resolved'],
            [$processing, 'pending'],
            [$processing, 'closed'],
            [$closed, 'processing'],
            [$closed, 'resolved'],
        ] as [$ticket, $status]) {
            $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => $status])
                ->assertStatus(422)
                ->assertJsonPath('code', 'INVALID_STATE');
            $this->assertSame($ticket->status, $ticket->fresh()->status);
        }
        $this->assertDatabaseCount('feedback_events', 0);
    }

    public function test_admin_and_foreign_accounts_cannot_read_or_mutate_another_owner(): void
    {
        $owner = $this->activeFeedbackUser();
        $ticket = $this->feedbackTicketFor($owner, [
            'contact' => 'contact-secret',
            'content' => 'content-secret',
        ]);
        $attachment = $this->attachPrivateFixture($ticket);
        $admin = User::factory()->create([
            'type' => UserType::Admin,
            'status' => true,
            'must_change_password' => false,
        ]);

        foreach ([$this->activeFeedbackUser(), $admin] as $actor) {
            Sanctum::actingAs($actor, ['*'], 'api');
            $this->getJson('/api/feedback-tickets/'.$ticket->id)->assertNotFound();
            $this->patchJson('/api/feedback-tickets/'.$ticket->id.'/status', ['status' => 'processing'])->assertNotFound();
            $this->postJson('/api/feedback-tickets/'.$ticket->id.'/notes', ['note' => 'secret-note'])->assertNotFound();
            $this->getJson('/api/feedback-attachments/'.$attachment->id.'/download')->assertNotFound();
            $list = $this->getJson('/api/feedback-tickets')->assertOk();
            $this->assertSame(0, $list->json('meta.total'));
            $this->assertStringNotContainsString('contact-secret', $list->getContent());
            $this->assertStringNotContainsString('content-secret', $list->getContent());
        }

        $this->assertSame(FeedbackTicketStatus::PENDING, $ticket->fresh()->status);
        $this->assertDatabaseCount('feedback_events', 0);
        Storage::disk('feedback_private')->assertExists($attachment->path);
    }

    public function test_list_supports_exact_filters_pagination_and_omits_personal_fields(): void
    {
        $owner = $this->activeFeedbackUser();
        $channelA = $this->feedbackChannelFor($owner, ['name' => '渠道A', 'categories' => ['售前承诺', '其他']]);
        $channelB = $this->feedbackChannelFor($owner, [
            'name' => '渠道B',
            'domain' => $channelA->domain,
            'categories' => ['产品问题'],
        ]);
        $day1 = CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Shanghai');
        $day2 = CarbonImmutable::parse('2026-09-02 11:00:00', 'Asia/Shanghai');
        $hash = str_repeat('ab', 32);
        $visible = $this->feedbackTicketFor($owner, [
            'channel' => $channelA,
            'category' => '售前承诺',
            'status' => 'pending',
            'submitted_at' => $day1,
            'contact' => 'contact-secret',
            'content' => 'content-secret',
            'visitor_hash' => $hash,
        ]);
        $later = $this->feedbackTicketFor($owner, [
            'channel' => $channelA,
            'category' => '其他',
            'status' => 'processing',
            'submitted_at' => $day2,
        ]);
        $otherChannel = $this->feedbackTicketFor($owner, [
            'channel' => $channelB,
            'category' => '产品问题',
            'status' => 'pending',
            'submitted_at' => $day1,
        ]);
        $this->feedbackTicketFor($this->activeFeedbackUser(), ['status' => 'pending']);
        FeedbackDelivery::query()->create([
            'user_id' => $owner->id,
            'feedback_channel_id' => $channelA->id,
            'feedback_ticket_id' => $visible->id,
            'kind' => FeedbackDeliveryKind::TICKET,
            'channel' => 'wecom',
            'status' => FeedbackDeliveryStatus::SENT,
            'attempts' => 1,
            'idempotency_key' => 'ticket:'.$visible->id.':wecom',
        ]);
        Sanctum::actingAs($owner, ['*'], 'api');

        $all = $this->getJson('/api/feedback-tickets')->assertOk();
        $this->assertSame(3, $all->json('meta.total'));
        $row = collect($all->json('data'))->firstWhere('id', $visible->id);
        $this->assertNotNull($row);
        $this->assertSame($visible->public_no, $row['public_no']);
        $this->assertSame('售前承诺', $row['category']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame($channelA->id, $row['channel_id']);
        $this->assertSame('渠道A', $row['channel_name']);
        $this->assertSame('sent', $row['notification_status']);
        $this->assertArrayNotHasKey('contact', $row);
        $this->assertArrayNotHasKey('content', $row);
        $this->assertArrayNotHasKey('visitor_hash', $row);
        $this->assertArrayNotHasKey('attachments', $row);
        $this->assertStringNotContainsString('contact-secret', $all->getContent());
        $this->assertStringNotContainsString('content-secret', $all->getContent());
        $this->assertStringNotContainsString($hash, $all->getContent());
        $this->assertNull(collect($all->json('data'))->firstWhere('id', $later->id)['notification_status'] ?? null);

        $this->assertSame(2, $this->json('GET', '/api/feedback-tickets', ['status' => 'pending'])->json('meta.total'));
        $this->assertSame(2, $this->json('GET', '/api/feedback-tickets', ['channel_id' => $channelA->id])->json('meta.total'));
        $this->assertSame(1, $this->json('GET', '/api/feedback-tickets', ['category' => '售前承诺'])->json('meta.total'));
        $this->assertSame(2, $this->json('GET', '/api/feedback-tickets', ['date' => '2026-09-01'])->json('meta.total'));
        $this->assertSame(
            [$visible->id],
            collect($this->json('GET', '/api/feedback-tickets', [
                'status' => 'pending',
                'channel_id' => $channelA->id,
                'category' => '售前承诺',
                'date' => '2026-09-01',
            ])->json('data'))->pluck('id')->all(),
        );

        $page = $this->getJson('/api/feedback-tickets?page=1&page_size=1')->assertOk();
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(1, $page->json('meta.current_page'));
        $this->assertContains($otherChannel->id, [$visible->id, $later->id, $otherChannel->id]);
    }

    public function test_detail_decrypts_owner_fields_and_returns_authorized_attachment_urls(): void
    {
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser(), [
            'contact' => 'contact-secret',
            'content' => 'content-secret',
            'visitor_hash' => str_repeat('cd', 32),
        ]);
        $attachment = $this->attachPrivateFixture($ticket);
        $this->actingAsFeedbackOwner($ticket);

        $detail = $this->getJson('/api/feedback-tickets/'.$ticket->id)->assertOk();
        $detail->assertJsonPath('contact', 'contact-secret')
            ->assertJsonPath('content', 'content-secret')
            ->assertJsonPath('public_no', $ticket->public_no)
            ->assertJsonPath('attachments.0.id', $attachment->id)
            ->assertJsonPath('attachments.0.original_name', 'evidence.png')
            ->assertJsonPath('attachments.0.mime', 'image/png')
            ->assertJsonPath('attachments.0.size', 13);
        $downloadUrl = $detail->json('attachments.0.download_url');
        $this->assertIsString($downloadUrl);
        $this->assertStringContainsString('/api/feedback-attachments/'.$attachment->id.'/download', $downloadUrl);
        $this->assertStringNotContainsString($attachment->path, $detail->getContent());
        $this->assertStringNotContainsString('/storage/', $detail->getContent());
        $this->assertArrayNotHasKey('path', $detail->json('attachments.0'));
        $this->assertArrayNotHasKey('disk', $detail->json('attachments.0'));
        $this->assertArrayNotHasKey('visitor_hash', $detail->json());
        $this->assertStringNotContainsString(str_repeat('cd', 32), $detail->getContent());
    }

    public function test_note_is_encrypted_plain_text_and_rejects_out_of_range(): void
    {
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser());
        $this->actingAsFeedbackOwner($ticket);
        $note = '<script>alert(1)</script>';

        $response = $this->postJson('/api/feedback-tickets/'.$ticket->id.'/notes', ['note' => $note])->assertOk();
        $stored = collect($response->json('events'))->firstWhere('event', 'note_added');
        $this->assertNotNull($stored);
        $this->assertSame($note, $stored['note']);
        $this->assertSame('note_added', $stored['event']);
        $this->assertStringNotContainsString('<html>', $response->getContent());

        $raw = DB::table('feedback_events')->where('feedback_ticket_id', $ticket->id)->first();
        $this->assertNotSame($note, $raw->note);
        $this->assertStringNotContainsString('<script>alert(1)</script>', (string) $raw->note);

        $this->postJson('/api/feedback-tickets/'.$ticket->id.'/notes', ['note' => ''])->assertStatus(422);
        $this->postJson('/api/feedback-tickets/'.$ticket->id.'/notes', ['note' => str_repeat('a', 1001)])->assertStatus(422);
        $this->postJson('/api/feedback-tickets/'.$ticket->id.'/notes', ['note' => str_repeat('b', 1000)])
            ->assertOk()
            ->assertJsonPath('events.1.note', str_repeat('b', 1000));
        $this->assertDatabaseCount('feedback_events', 2);
    }

    public function test_download_streams_private_file_with_sanitized_original_name(): void
    {
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser());
        $attachment = $this->attachPrivateFixture($ticket);
        $attachment->forceFill(['original_name' => "inv\r\n../x.png"])->save();
        $this->actingAsFeedbackOwner($ticket);

        $detailName = $this->getJson('/api/feedback-tickets/'.$ticket->id)
            ->assertOk()
            ->json('attachments.0.original_name');
        $this->assertSame('inv..x.png', $detailName);
        $this->assertStringNotContainsString("\r", $detailName);
        $this->assertStringNotContainsString("\n", $detailName);
        $this->assertStringNotContainsString('/', $detailName);

        $response = $this->get('/api/feedback-attachments/'.$attachment->id.'/download')->assertOk();
        $this->assertSame('fixture-bytes', $response->streamedContent());
        $this->assertSame('image/png', $response->headers->get('content-type'));
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('inv..x.png', $disposition);
        $this->assertSame($detailName, 'inv..x.png');
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString($attachment->path, $disposition);
        $this->assertStringNotContainsString(basename($attachment->path), $disposition);
        $this->assertStringNotContainsString('../', $disposition);
    }

    public function test_download_rejects_foreign_missing_and_non_private_disk(): void
    {
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser());
        $private = $this->attachPrivateFixture($ticket);
        $wrongDisk = $this->attachPrivateFixture($ticket);
        $wrongDisk->forceFill(['disk' => 'public'])->save();
        $missing = $this->attachPrivateFixture($ticket);
        Storage::disk('feedback_private')->delete($missing->path);

        Sanctum::actingAs($this->activeFeedbackUser(), ['*'], 'api');
        $this->getJson('/api/feedback-attachments/'.$private->id.'/download')->assertNotFound();

        $this->actingAsFeedbackOwner($ticket);
        $this->getJson('/api/feedback-attachments/'.$wrongDisk->id.'/download')
            ->assertStatus(404)
            ->assertJsonPath('code', 'ATTACHMENT_UNAVAILABLE');
        $this->getJson('/api/feedback-attachments/'.$missing->id.'/download')
            ->assertStatus(404)
            ->assertJsonPath('code', 'ATTACHMENT_UNAVAILABLE');
        Storage::disk('feedback_private')->assertExists($private->path);
    }
}
