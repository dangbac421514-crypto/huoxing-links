<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\FeedbackChannel;
use App\Models\FeedbackTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FeedbackSchemaTest extends TestCase
{
    public function test_feedback_schema_has_tenant_keys_and_idempotency_constraints(): void
    {
        $this->assertTrue(Schema::hasColumns('feedback_channels', ['user_id', 'domain_id', 'code', 'webhook_url']));
        $this->assertTrue(Schema::hasColumns('feedback_tickets', ['user_id', 'feedback_channel_id', 'public_no', 'idempotency_key', 'anonymized_at']));
        $this->assertTrue(Schema::hasColumns('feedback_attachments', ['user_id', 'feedback_ticket_id', 'original_name', 'sha256']));
        $this->assertTrue(Schema::hasColumns('feedback_events', ['feedback_ticket_id', 'actor_user_id', 'note']));
        $this->assertTrue(Schema::hasColumns('feedback_deliveries', ['feedback_channel_id', 'feedback_ticket_id', 'kind', 'idempotency_key']));

        $this->assertHasUniqueIndex('feedback_channels', ['code']);
        $this->assertHasUniqueIndex('feedback_tickets', ['public_no']);
        $this->assertHasUniqueIndex('feedback_tickets', ['feedback_channel_id', 'idempotency_key']);
        $this->assertHasUniqueIndex('feedback_deliveries', ['idempotency_key']);
    }

    public function test_feedback_personal_fields_are_encrypted_and_hidden(): void
    {
        $ticket = $this->feedbackTicketWithSecrets('contact-secret', 'content-secret');
        $raw = DB::table('feedback_tickets')->where('id', $ticket->id)->first();
        $this->assertNotSame('contact-secret', $raw->contact);
        $this->assertNotSame('content-secret', $raw->content);
        $this->assertStringNotContainsString('contact-secret', $ticket->toJson());
    }

    public function test_feedback_private_disk_defaults_to_storage_private_feedback_when_root_is_blank(): void
    {
        $this->assertSame('', (string) env('FEEDBACK_PRIVATE_ROOT'));
        $this->assertSame(
            storage_path('app/private/feedback'),
            config('filesystems.disks.feedback_private.root'),
        );
        $this->assertTrue((bool) config('filesystems.disks.feedback_private.throw'));
        $this->assertGreaterThanOrEqual(32, strlen((string) config('app.feedback_hash_key')));
    }

    private function feedbackTicketWithSecrets(string $contact, string $content): FeedbackTicket
    {
        $user = User::factory()->create(['status' => true]);
        $domain = Domain::query()->create([
            'url' => 'https://feedback.example',
            'title' => 'feedback',
            'enable' => true,
        ]);
        $channel = FeedbackChannel::query()->create([
            'user_id' => $user->id,
            'domain_id' => $domain->id,
            'code' => str_repeat('a', 24),
            'status' => true,
            'name' => '售后反馈',
            'operator_name' => '测试运营主体',
            'intro' => '',
            'sla_text' => '2小时内响应',
            'categories' => ['其他'],
            'contact_required' => false,
            'retention_days' => 180,
        ]);

        return FeedbackTicket::query()->create([
            'user_id' => $user->id,
            'feedback_channel_id' => $channel->id,
            'public_no' => 'FB-ABCDEFGH12345678',
            'category' => '其他',
            'status' => 'pending',
            'contact' => $contact,
            'content' => $content,
            'idempotency_key' => '00000000-0000-4000-8000-000000000001',
            'submitted_at' => now(),
        ])->fresh();
    }

    private function assertHasUniqueIndex(string $table, array $columns): void
    {
        $indexes = Schema::getIndexes($table);

        $this->assertTrue(collect($indexes)->contains(
            fn (array $index): bool => $index['unique'] && $index['columns'] === $columns,
        ), "Missing unique index on {$table} (".implode(', ', $columns).')');
    }
}
