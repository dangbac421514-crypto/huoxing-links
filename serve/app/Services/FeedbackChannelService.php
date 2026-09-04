<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\FeedbackChannel;
use App\Models\User;
use App\Support\FeedbackError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class FeedbackChannelService
{
    private const CODE_LENGTH = 24;

    private const MAX_CODE_ATTEMPTS = 8;

    /** @var list<string> */
    private const PUBLIC_ATTRIBUTES = [
        'domain_id',
        'name',
        'operator_name',
        'intro',
        'service_phone',
        'sla_text',
        'categories',
        'contact_required',
        'retention_days',
    ];

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly WeComWebhookPolicy $webhooks,
    ) {}

    /** @param array<string, mixed> $payload */
    public function create(User $actor, array $payload): FeedbackChannel
    {
        $this->entitlements->assertActive($actor);
        $attributes = array_merge($this->publicAttributes($payload), $this->webhookAttributes($payload));
        $attributes['user_id'] = $actor->id;
        $attributes['status'] = true;

        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++) {
            $attributes['code'] = Str::random(self::CODE_LENGTH);
            try {
                return FeedbackChannel::query()->create($attributes);
            } catch (QueryException $exception) {
                if (! $this->isDuplicateCode($exception) || $attempt === self::MAX_CODE_ATTEMPTS - 1) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('客诉渠道创建失败');
    }

    /** @param array<string, mixed> $payload */
    public function update(FeedbackChannel $channel, array $payload): FeedbackChannel
    {
        $channel->fill(array_merge($this->publicAttributes($payload), $this->webhookAttributes($payload)))->save();

        return $channel->fresh(['domain']);
    }

    public function setStatus(FeedbackChannel $channel, bool $status): FeedbackChannel
    {
        $channel->forceFill(['status' => $status])->save();

        return $channel->fresh(['domain']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function publicAttributes(array $payload): array
    {
        $attributes = [];
        foreach (self::PUBLIC_ATTRIBUTES as $key) {
            if ($key === 'intro') {
                $attributes['intro'] = (string) ($payload['intro'] ?? '');

                continue;
            }
            if (array_key_exists($key, $payload)) {
                $attributes[$key] = $payload[$key];
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function webhookAttributes(array $payload): array
    {
        if (! array_key_exists('webhook_url', $payload)) {
            return [];
        }

        $url = $payload['webhook_url'];
        if ($url === null) {
            return [
                'webhook_url' => null,
                'webhook_configured_at' => null,
            ];
        }
        if (! is_string($url)) {
            throw new BusinessRuleException(FeedbackError::WEBHOOK_INVALID, '企业微信机器人地址无效', 422);
        }

        return [
            'webhook_url' => $this->webhooks->assertValid($url),
            'webhook_configured_at' => now(),
        ];
    }

    private function isDuplicateCode(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains((string) ($exception->errorInfo[2] ?? ''), 'feedback_channels_code_unique');
    }
}
