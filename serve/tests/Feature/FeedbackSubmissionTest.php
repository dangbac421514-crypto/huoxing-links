<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesFeedbackFixtures;
use Tests\TestCase;

final class FeedbackSubmissionTest extends TestCase
{
    use CreatesFeedbackFixtures;

    public function test_submission_encrypts_personal_data_and_is_idempotent(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $payload = [
            'category' => $channel->categories[0],
            'content' => 'content-secret-123',
            'contact' => 'contact-secret',
            'idempotency_key' => (string) Str::uuid(),
            'privacy_accepted' => true,
        ];
        $first = $this->postToChannelHost($channel, $payload)->assertCreated();
        $second = $this->postToChannelHost($channel, $payload)->assertOk();
        $this->assertSame($first->json('public_no'), $second->json('public_no'));
        $this->assertMatchesRegularExpression('/^FB-[A-Z0-9]{16}$/', (string) $first->json('public_no'));
        $this->assertDatabaseCount('feedback_tickets', 1);
        $this->assertDatabaseCount('feedback_events', 1);
        $raw = DB::table('feedback_tickets')->first();
        $this->assertStringNotContainsString('content-secret-123', $raw->content);
        $this->assertStringNotContainsString('contact-secret', $raw->contact);
        $this->assertSame('pending', $raw->status);
        $this->assertSame(64, strlen((string) $raw->visitor_hash));
        $event = DB::table('feedback_events')->first();
        $this->assertSame('submitted', $event->event);
        $this->assertNull($event->note);
        $this->assertStringNotContainsString('content-secret-123', (string) $event->note);
        foreach ([$first, $second] as $response) {
            $this->assertStringNotContainsString('content-secret-123', $response->getContent());
            $this->assertStringNotContainsString('contact-secret', $response->getContent());
            $response->assertJsonMissingPath('content')
                ->assertJsonMissingPath('contact')
                ->assertJsonMissingPath('id')
                ->assertJsonMissingPath('user_id');
        }
    }

    public function test_category_must_belong_to_the_channel(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'categories' => ['售前承诺', '其他'],
        ]);
        $this->postToChannelHost($channel, $this->validSubmission([
            'category' => '不存在的分类',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['category']);
        $this->assertDatabaseCount('feedback_tickets', 0);
    }

    public function test_contact_is_required_only_when_the_channel_requires_it(): void
    {
        $optional = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'contact_required' => false,
        ]);
        $optionalPayload = $this->validSubmission(['category' => $optional->categories[0]]);
        unset($optionalPayload['contact']);
        $this->postToChannelHost($optional, $optionalPayload)->assertCreated();

        $required = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'contact_required' => true,
            'domain' => $this->enabledShareDomain('feedback-required.example'),
        ]);
        $missing = $this->validSubmission(['category' => $required->categories[0]]);
        unset($missing['contact']);
        $this->postToChannelHost($required, $missing)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact']);
        $this->postToChannelHost($required, $this->validSubmission([
            'category' => $required->categories[0],
            'contact' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['contact']);
        $this->assertDatabaseCount('feedback_tickets', 1);
    }

    public function test_content_rejects_nine_and_two_thousand_one_characters(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $this->postToChannelHost($channel, $this->validSubmission([
            'content' => str_repeat('a', 9),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['content']);
        $this->postToChannelHost($channel, $this->validSubmission([
            'content' => str_repeat('a', 2001),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['content']);
        $this->assertDatabaseCount('feedback_tickets', 0);
    }

    public function test_missing_privacy_consent_is_rejected(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $missing = $this->validSubmission();
        unset($missing['privacy_accepted']);
        $this->postToChannelHost($channel, $missing)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['privacy_accepted']);
        $this->postToChannelHost($channel, $this->validSubmission([
            'privacy_accepted' => false,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['privacy_accepted']);
        $this->assertDatabaseCount('feedback_tickets', 0);
    }

    public function test_client_owned_user_status_and_public_no_are_rejected(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $this->postToChannelHost($channel, $this->validSubmission([
            'user_id' => 1,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
        $this->postToChannelHost($channel, $this->validSubmission([
            'status' => 'closed',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->postToChannelHost($channel, $this->validSubmission([
            'public_no' => 'FB-CLIENTOWNED1234',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['public_no']);
        $this->assertDatabaseCount('feedback_tickets', 0);
    }

    public function test_fourth_request_from_the_same_visitor_is_rate_limited(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postToChannelHost($channel, $this->validSubmission([
                'category' => $channel->categories[0],
            ]))->assertCreated();
        }

        $response = $this->postToChannelHost($channel, $this->validSubmission([
            'category' => $channel->categories[0],
        ]))->assertStatus(429)->assertJsonStructure(['message']);

        $body = $response->getContent();
        $this->assertStringNotContainsString('127.0.0.1', $body);
        $this->assertDoesNotMatchRegularExpression('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $body);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
        $this->assertDatabaseCount('feedback_tickets', 3);
    }

    public function test_public_form_script_issues_one_uuid_per_logical_submit(): void
    {
        $source = file_get_contents(resource_path('views/feedback/show.blade.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('crypto.randomUUID()', $source);
        $this->assertStringContainsString('new FormData', $source);
        $this->assertStringContainsString('idempotency_key', $source);
        $this->assertStringContainsString('button.disabled = true', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/<script[^>]+src\s*=\s*[\'"]https?:\/\//i',
            $source,
        );
    }
}
