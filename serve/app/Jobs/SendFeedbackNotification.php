<?php

namespace App\Jobs;

use App\Enums\FeedbackDeliveryKind;
use App\Enums\FeedbackDeliveryStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\FeedbackChannel;
use App\Models\FeedbackDelivery;
use App\Services\FeedbackNotificationPayload;
use App\Services\WeComWebhookClient;
use App\Services\WeComWebhookPolicy;
use App\Support\FeedbackError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendFeedbackNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $deliveryId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200];
    }

    public function handle(
        WeComWebhookClient $client,
        FeedbackNotificationPayload $payloads,
        WeComWebhookPolicy $policy,
    ): void {
        $delivery = FeedbackDelivery::query()->with(['channel', 'ticket'])->find($this->deliveryId);
        if (! $delivery instanceof FeedbackDelivery || $delivery->status === FeedbackDeliveryStatus::SENT) {
            return;
        }

        // The `channel` attribute is the provider name; the relation shares that name.
        $channel = $delivery->getRelation('channel');
        $url = $channel instanceof FeedbackChannel ? $channel->webhook_url : null;
        if (! is_string($url) || $url === '') {
            $this->recordAttemptFailure($delivery, WeComWebhookClient::REQUEST_FAILED, true);

            return;
        }

        try {
            $url = $policy->assertValid($url);
        } catch (BusinessRuleException) {
            $this->recordAttemptFailure($delivery, WeComWebhookClient::REQUEST_FAILED, true);

            return;
        }

        if ($delivery->kind === FeedbackDeliveryKind::TEST) {
            if (! $channel instanceof FeedbackChannel) {
                $this->recordAttemptFailure($delivery, WeComWebhookClient::REQUEST_FAILED, true);

                return;
            }
            $payload = $payloads->forTest($channel);
        } else {
            $ticket = $delivery->ticket;
            if ($ticket === null) {
                $this->recordAttemptFailure($delivery, WeComWebhookClient::REQUEST_FAILED, true);

                return;
            }
            $payload = $payloads->forTicket($ticket);
        }

        $delivery->forceFill([
            'attempts' => (int) $delivery->attempts + 1,
        ])->save();

        try {
            $client->send($url, $payload);
        } catch (BusinessRuleException $exception) {
            if ($exception->errorCode !== FeedbackError::NOTIFICATION_FAILED) {
                throw $exception;
            }
            $this->recordAttemptFailure($delivery, $client->lastFailureCode());
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordAttemptFailure($delivery, WeComWebhookClient::REQUEST_FAILED);
            throw $exception;
        }

        $delivery->forceFill([
            'status' => FeedbackDeliveryStatus::SENT,
            'sent_at' => now(),
            'next_attempt_at' => null,
            'last_error_code' => null,
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = FeedbackDelivery::query()->find($this->deliveryId);
        if (! $delivery instanceof FeedbackDelivery || $delivery->status === FeedbackDeliveryStatus::SENT) {
            return;
        }
        if ((int) $delivery->attempts < $this->tries) {
            return;
        }

        $delivery->forceFill([
            'status' => FeedbackDeliveryStatus::FAILED,
            'next_attempt_at' => null,
            'last_error_code' => $delivery->last_error_code ?: WeComWebhookClient::REQUEST_FAILED,
        ])->save();
    }

    private function recordAttemptFailure(FeedbackDelivery $delivery, string $code, bool $exhausted = false): void
    {
        $attempts = (int) $delivery->attempts;
        $done = $exhausted || $attempts >= $this->tries;
        $next = null;
        if (! $done && $attempts > 0) {
            $backoffs = $this->backoff();
            $delay = $backoffs[$attempts - 1] ?? $backoffs[array_key_last($backoffs)];
            $next = now()->addSeconds($delay);
        }

        $delivery->forceFill([
            'status' => $done ? FeedbackDeliveryStatus::FAILED : FeedbackDeliveryStatus::PENDING,
            'next_attempt_at' => $next,
            'last_error_code' => $code,
        ])->save();
    }
}
