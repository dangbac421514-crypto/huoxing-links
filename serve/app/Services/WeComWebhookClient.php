<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Support\FeedbackError;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WeComWebhookClient
{
    public const REQUEST_FAILED = 'WECOM_REQUEST_FAILED';

    public const RESPONSE_REJECTED = 'WECOM_RESPONSE_REJECTED';

    private const CONNECT_TIMEOUT_SECONDS = 3;

    private const TIMEOUT_SECONDS = 5;

    private ?string $lastFailureCode = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $url, array $payload): void
    {
        $this->lastFailureCode = null;
        try {
            $response = Http::connectTimeout((int) config('services.wecom.connect_timeout', self::CONNECT_TIMEOUT_SECONDS))
                ->timeout((int) config('services.wecom.timeout', self::TIMEOUT_SECONDS))
                ->withoutRedirecting()
                ->withBody(
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'application/json',
                )
                ->post($url);
        } catch (Throwable) {
            $this->failSend(self::REQUEST_FAILED);
        }

        if (! $response instanceof Response || $response->status() < 200 || $response->status() >= 300) {
            $this->failSend(self::REQUEST_FAILED);
        }

        $decoded = $response->json();
        if (! is_array($decoded) || ! array_key_exists('errcode', $decoded) || $decoded['errcode'] !== 0) {
            $this->failSend(self::RESPONSE_REJECTED);
        }
    }

    public function lastFailureCode(): string
    {
        return $this->lastFailureCode ?? self::REQUEST_FAILED;
    }

    private function failSend(string $code): never
    {
        $this->lastFailureCode = $code;
        throw new BusinessRuleException(FeedbackError::NOTIFICATION_FAILED, '企业微信通知发送失败', 502);
    }
}
