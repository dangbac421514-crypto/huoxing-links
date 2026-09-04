<?php

namespace App\Services;

use App\Enums\FeedbackDeliveryKind;
use App\Enums\FeedbackDeliveryStatus;
use App\Exceptions\BusinessRuleException;
use App\Jobs\SendFeedbackNotification;
use App\Models\FeedbackChannel;
use App\Models\FeedbackDelivery;
use App\Models\FeedbackTicket;
use App\Support\FeedbackError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class FeedbackDeliveryService
{
    private const CHANNEL = 'wecom';

    private const DUPLICATE_INDEX = 'feedback_deliveries_idempotency_key_unique';

    public function queueTicket(FeedbackTicket $ticket): ?FeedbackDelivery
    {
        $ticket->loadMissing('channel');
        $channel = $ticket->channel;
        if (! $channel instanceof FeedbackChannel || ! filled($channel->webhook_url)) {
            return null;
        }

        $key = 'ticket:'.$ticket->id.':wecom';
        $created = false;
        try {
            $delivery = FeedbackDelivery::query()->create([
                'user_id' => $ticket->user_id,
                'feedback_channel_id' => $channel->id,
                'feedback_ticket_id' => $ticket->id,
                'kind' => FeedbackDeliveryKind::TICKET,
                'channel' => self::CHANNEL,
                'status' => FeedbackDeliveryStatus::PENDING,
                'attempts' => 0,
                'idempotency_key' => $key,
            ]);
            $created = true;
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }
            $delivery = FeedbackDelivery::query()->where('idempotency_key', $key)->first();
            if (! $delivery instanceof FeedbackDelivery) {
                throw $exception;
            }
        }

        if ($created) {
            $this->dispatchSafely($delivery);
        }

        return $delivery;
    }

    public function queueTest(FeedbackChannel $channel): FeedbackDelivery
    {
        if (! filled($channel->webhook_url)) {
            throw new BusinessRuleException(FeedbackError::NOTIFICATION_UNCONFIGURED, '企业微信通知未配置', 422);
        }

        $delivery = FeedbackDelivery::query()->create([
            'user_id' => $channel->user_id,
            'feedback_channel_id' => $channel->id,
            'feedback_ticket_id' => null,
            'kind' => FeedbackDeliveryKind::TEST,
            'channel' => self::CHANNEL,
            'status' => FeedbackDeliveryStatus::PENDING,
            'attempts' => 0,
            'idempotency_key' => 'test:'.(string) Str::uuid(),
        ]);
        $this->dispatchSafely($delivery);

        return $delivery->fresh() ?? $delivery;
    }

    public function dispatchSafely(FeedbackDelivery $delivery): void
    {
        $deliveryId = (int) $delivery->id;
        DB::afterCommit(function () use ($deliveryId): void {
            try {
                SendFeedbackNotification::dispatch($deliveryId);
            } catch (Throwable) {
            }
        });
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains((string) ($exception->errorInfo[2] ?? $exception->getMessage()), self::DUPLICATE_INDEX);
    }
}
