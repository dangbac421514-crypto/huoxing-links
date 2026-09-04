<?php

namespace Tests\Feature;

use App\Enums\FeedbackDeliveryKind;
use App\Enums\FeedbackDeliveryStatus;
use App\Jobs\SendFeedbackNotification;
use App\Models\FeedbackChannel;
use App\Models\FeedbackDelivery;
use App\Models\ProtectedSecretAudit;
use App\Services\FeedbackDeliveryService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesFeedbackFixtures;
use Tests\TestCase;

final class FeedbackNotificationTest extends TestCase
{
    use CreatesFeedbackFixtures;

    private const DUMMY_WEBHOOK = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=12345678-abcd-1234-abcd-123456789012';

    private const DUMMY_KEY = '12345678-abcd-1234-abcd-123456789012';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_notification_excludes_personal_data_and_secret(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://qyapi.weixin.qq.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser(), ['contact' => 'contact-secret', 'content' => 'content-secret']);
        $ticket->channel->forceFill(['webhook_url' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=12345678-abcd-1234-abcd-123456789012'])->save();
        app(FeedbackDeliveryService::class)->queueTicket($ticket);
        Http::assertSent(fn (Request $request) => ! str_contains($this->messageContent($request), 'contact-secret') && ! str_contains($this->messageContent($request), 'content-secret'));
    }

    public function test_ticket_submission_stays_created_when_webhook_fails_and_delivery_stays_retryable(): void
    {
        Http::fake(['https://qyapi.weixin.qq.com/*' => Http::failedConnection()]);
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'webhook_url' => self::DUMMY_WEBHOOK,
        ]);
        $response = $this->postToChannelHost($channel, $this->validSubmission([
            'category' => $channel->categories[0],
        ]));
        $response->assertCreated();
        $this->assertStringNotContainsString(self::DUMMY_KEY, $response->getContent());

        $delivery = FeedbackDelivery::query()->firstOrFail();
        $this->assertSame(FeedbackDeliveryKind::TICKET, $delivery->kind);
        $this->assertSame(FeedbackDeliveryStatus::PENDING, $delivery->status);
        $this->assertSame('ticket:'.$delivery->feedback_ticket_id.':wecom', $delivery->idempotency_key);
        $this->assertSame('WECOM_REQUEST_FAILED', $delivery->last_error_code);
        $this->assertSame(1, (int) $delivery->attempts);
        $this->assertNotNull($delivery->next_attempt_at);
        $this->assertNull($delivery->sent_at);
        $this->assertStringNotContainsString(self::DUMMY_KEY, $delivery->toJson());
    }

    public function test_rejected_wecom_json_stores_response_rejected_without_leaking_secret(): void
    {
        Http::fake(['https://qyapi.weixin.qq.com/*' => Http::response(['errcode' => 93000, 'errmsg' => 'invalid webhook'])]);
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser(), [
            'contact' => 'contact-secret',
            'content' => 'content-secret',
        ]);
        $ticket->channel->forceFill(['webhook_url' => self::DUMMY_WEBHOOK])->save();
        app(FeedbackDeliveryService::class)->queueTicket($ticket);

        $delivery = FeedbackDelivery::query()->firstOrFail();
        $this->assertSame(FeedbackDeliveryStatus::PENDING, $delivery->status);
        $this->assertSame('WECOM_RESPONSE_REJECTED', $delivery->last_error_code);
        $this->assertStringNotContainsString(self::DUMMY_KEY, $delivery->toJson());
        $this->assertStringNotContainsString('contact-secret', $delivery->toJson());
        $this->assertStringNotContainsString(self::DUMMY_KEY, $ticket->channel->toJson());
        $this->assertArrayNotHasKey('webhook_url', $ticket->channel->toArray());
    }

    public function test_payload_contains_only_operator_ticket_category_time_and_admin_url(): void
    {
        Http::fake(['https://qyapi.weixin.qq.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);
        $user = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($user, [
            'operator_name' => '测试运营主体',
            'webhook_url' => self::DUMMY_WEBHOOK,
        ]);
        $ticket = $this->feedbackTicketFor($user, [
            'channel' => $channel,
            'category' => '产品问题',
            'contact' => 'contact-secret',
            'content' => 'content-secret',
        ]);
        app(FeedbackDeliveryService::class)->queueTicket($ticket);

        Http::assertSent(function (Request $request) use ($ticket): bool {
            $content = $this->messageContent($request);
            $this->assertSame('https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key='.self::DUMMY_KEY, $request->url());
            $this->assertStringContainsString('测试运营主体', $content);
            $this->assertStringContainsString($ticket->public_no, $content);
            $this->assertStringContainsString('产品问题', $content);
            $this->assertStringContainsString((string) $ticket->id, $content);
            $this->assertStringNotContainsString('contact-secret', $content);
            $this->assertStringNotContainsString('content-secret', $content);
            $this->assertStringNotContainsString(self::DUMMY_KEY, $content);
            $this->assertStringNotContainsString('webhook', $content);

            return true;
        });

        $delivery = FeedbackDelivery::query()->firstOrFail();
        $this->assertSame(FeedbackDeliveryStatus::SENT, $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertNull($delivery->last_error_code);
    }

    public function test_unconfigured_channel_creates_no_delivery_and_sends_nothing(): void
    {
        Http::fake();
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser());
        $this->assertNull(app(FeedbackDeliveryService::class)->queueTicket($ticket));
        $this->assertDatabaseCount('feedback_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_duplicate_ticket_delivery_is_unique_and_not_resent_when_already_sent(): void
    {
        Http::fake(['https://qyapi.weixin.qq.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser());
        $ticket->channel->forceFill(['webhook_url' => self::DUMMY_WEBHOOK])->save();
        $first = app(FeedbackDeliveryService::class)->queueTicket($ticket);
        $second = app(FeedbackDeliveryService::class)->queueTicket($ticket);
        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertDatabaseCount('feedback_deliveries', 1);
        Http::assertSentCount(1);
    }

    public function test_webhook_is_write_only_audited_and_missing_preserves_explicit_null_clears(): void
    {
        $owner = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($owner);
        Sanctum::actingAs($owner, ['*'], 'api');

        $payload = $this->validChannelPayload($channel->domain_id);
        $payload['webhook_url'] = self::DUMMY_WEBHOOK;
        $set = $this->putJson('/api/feedback-channels/'.$channel->id, $payload)
            ->assertOk()
            ->assertJsonPath('webhook_configured', true)
            ->assertJsonMissingPath('webhook_url');
        $this->assertNotNull($set->json('webhook_configured_at'));
        $this->assertStringNotContainsString(self::DUMMY_KEY, $set->getContent());

        $stored = $channel->fresh();
        $this->assertSame(self::DUMMY_WEBHOOK, $stored->webhook_url);
        $this->assertNotNull($stored->webhook_configured_at);
        $this->assertArrayNotHasKey('webhook_url', $stored->toArray());
        $raw = DB::table('feedback_channels')->where('id', $stored->id)->first();
        $this->assertNotSame(self::DUMMY_WEBHOOK, $raw->webhook_url);
        $this->assertStringNotContainsString(self::DUMMY_KEY, (string) $raw->webhook_url);

        unset($payload['webhook_url']);
        $this->putJson('/api/feedback-channels/'.$channel->id, $payload)
            ->assertOk()
            ->assertJsonPath('webhook_configured', true);
        $this->assertSame(self::DUMMY_WEBHOOK, $channel->fresh()->webhook_url);

        $payload['webhook_url'] = null;
        $cleared = $this->putJson('/api/feedback-channels/'.$channel->id, $payload)
            ->assertOk()
            ->assertJsonPath('webhook_configured', false)
            ->assertJsonPath('webhook_configured_at', null)
            ->assertJsonMissingPath('webhook_url');
        $this->assertStringNotContainsString(self::DUMMY_KEY, $cleared->getContent());
        $this->assertNull($channel->fresh()->webhook_url);
        $this->assertNull($channel->fresh()->webhook_configured_at);

        $audits = ProtectedSecretAudit::query()
            ->where('key_identifier', 'feedback_channel:'.$channel->id.':webhook_url')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $audits);
        $this->assertSame('protected_secret.set', $audits[0]->event_name);
        $this->assertSame('protected_secret.invalidated', $audits[1]->event_name);
        $this->assertSame($owner->id, $audits[0]->actor_user_id);
        $this->assertSame($owner->id, $audits[1]->actor_user_id);
        $this->assertStringNotContainsString(self::DUMMY_KEY, $audits->toJson());
        $this->assertStringNotContainsString(self::DUMMY_KEY, DB::table('protected_secret_audits')->get()->toJson());
    }

    public function test_invalid_webhook_is_rejected_without_storing_the_url(): void
    {
        $owner = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($owner);
        Sanctum::actingAs($owner, ['*'], 'api');
        $payload = $this->validChannelPayload($channel->domain_id);
        $payload['webhook_url'] = 'https://evil.example/cgi-bin/webhook/send?key='.self::DUMMY_KEY;
        $this->putJson('/api/feedback-channels/'.$channel->id, $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'WEBHOOK_INVALID');
        $this->assertNull($channel->fresh()->webhook_url);
        $this->assertDatabaseCount('protected_secret_audits', 0);
        $this->assertDatabaseCount('feedback_deliveries', 0);
    }

    public function test_create_accepts_valid_webhook_and_never_echoes_it(): void
    {
        $user = $this->activeFeedbackUser();
        Sanctum::actingAs($user, ['*'], 'api');
        $domain = $this->enabledShareDomain('feedback.example');
        $payload = $this->validChannelPayload($domain->id);
        $payload['webhook_url'] = self::DUMMY_WEBHOOK;
        $response = $this->postJson('/api/feedback-channels', $payload)
            ->assertCreated()
            ->assertJsonPath('webhook_configured', true)
            ->assertJsonMissingPath('webhook_url');
        $this->assertStringNotContainsString(self::DUMMY_KEY, $response->getContent());
        $channel = FeedbackChannel::query()->findOrFail($response->json('id'));
        $this->assertSame(self::DUMMY_WEBHOOK, $channel->webhook_url);
        $this->assertNotNull($channel->webhook_configured_at);
        $this->assertDatabaseHas('protected_secret_audits', [
            'event_name' => 'protected_secret.set',
            'key_identifier' => 'feedback_channel:'.$channel->id.':webhook_url',
        ]);
    }

    public function test_test_notification_uses_marker_and_nullable_ticket(): void
    {
        Http::fake(['https://qyapi.weixin.qq.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);
        $owner = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($owner, ['webhook_url' => self::DUMMY_WEBHOOK]);
        Sanctum::actingAs($owner, ['*'], 'api');

        $this->postJson('/api/feedback-channels/'.$channel->id.'/test-notification')
            ->assertSuccessful()
            ->assertJsonMissingPath('webhook_url');

        $delivery = FeedbackDelivery::query()->firstOrFail();
        $this->assertSame(FeedbackDeliveryKind::TEST, $delivery->kind);
        $this->assertNull($delivery->feedback_ticket_id);
        $this->assertMatchesRegularExpression('/^test:[0-9a-f-]{36}$/', $delivery->idempotency_key);
        $this->assertSame(FeedbackDeliveryStatus::SENT, $delivery->status);

        Http::assertSent(function (Request $request): bool {
            $content = $this->messageContent($request);
            $this->assertStringContainsString('测试通知', $content);
            $this->assertStringNotContainsString(self::DUMMY_KEY, $content);

            return true;
        });
    }

    public function test_test_notification_without_webhook_is_unconfigured(): void
    {
        $owner = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($owner);
        Sanctum::actingAs($owner, ['*'], 'api');
        $this->postJson('/api/feedback-channels/'.$channel->id.'/test-notification')
            ->assertStatus(422)
            ->assertJsonPath('code', 'NOTIFICATION_UNCONFIGURED');
        $this->assertDatabaseCount('feedback_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_job_retries_five_times_with_matching_backoff_then_marks_failed(): void
    {
        $job = new SendFeedbackNotification(1);
        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 1800, 7200], $job->backoff());

        Http::fake(['https://qyapi.weixin.qq.com/*' => Http::response('nope', 500)]);
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser());
        $ticket->channel->forceFill(['webhook_url' => self::DUMMY_WEBHOOK])->save();
        $delivery = app(FeedbackDeliveryService::class)->queueTicket($ticket);
        $this->assertNotNull($delivery);
        $this->assertSame(1, (int) $delivery->fresh()->attempts);
        $this->assertSame(FeedbackDeliveryStatus::PENDING, $delivery->fresh()->status);

        for ($attempt = 2; $attempt <= 5; $attempt++) {
            try {
                app()->call([new SendFeedbackNotification((int) $delivery->id), 'handle']);
            } catch (\Throwable) {
            }
            $fresh = $delivery->fresh();
            $this->assertSame($attempt, (int) $fresh->attempts);
            $this->assertSame('WECOM_REQUEST_FAILED', $fresh->last_error_code);
            if ($attempt < 5) {
                $this->assertSame(FeedbackDeliveryStatus::PENDING, $fresh->status);
                $this->assertNotNull($fresh->next_attempt_at);
            } else {
                $this->assertSame(FeedbackDeliveryStatus::FAILED, $fresh->status);
                $this->assertNull($fresh->next_attempt_at);
            }
        }
    }

    public function test_client_does_not_follow_redirects_off_the_allowlist(): void
    {
        Http::fake([
            'https://qyapi.weixin.qq.com/*' => Http::response('moved', 302, [
                'Location' => 'https://evil.example/steal',
            ]),
        ]);
        $ticket = $this->feedbackTicketFor($this->activeFeedbackUser());
        $ticket->channel->forceFill(['webhook_url' => self::DUMMY_WEBHOOK])->save();
        app(FeedbackDeliveryService::class)->queueTicket($ticket);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://qyapi.weixin.qq.com/'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example'));
        $this->assertSame('WECOM_REQUEST_FAILED', FeedbackDelivery::query()->firstOrFail()->last_error_code);
    }

    private function messageContent(Request $request): string
    {
        $decoded = json_decode($request->body(), true);

        return is_array($decoded) ? (string) data_get($decoded, 'text.content', '') : $request->body();
    }
}
