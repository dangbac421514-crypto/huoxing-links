<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Support\FeedbackError;

final class WeComWebhookPolicy
{
    private const SCHEME = 'https';

    private const HOST = 'qyapi.weixin.qq.com';

    private const PATH = '/cgi-bin/webhook/send';

    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/D';

    private const MAX_URL_LENGTH = 2048;

    public function assertValid(string $url): string
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || $this->containsUnsafeBytes($url) || substr_count($url, '?') !== 1) {
            $this->reject();
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            $this->reject();
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $port = $parts['port'] ?? null;
        $query = (string) ($parts['query'] ?? '');

        if ($scheme !== self::SCHEME || $host !== self::HOST || $path !== self::PATH) {
            $this->reject();
        }
        if ($port !== null && (int) $port !== 443) {
            $this->reject();
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || str_contains($url, '#') || str_contains($url, '@')) {
            $this->reject();
        }

        $pairs = explode('&', $query);
        if ($query === '' || count($pairs) !== 1 || ! str_starts_with($pairs[0], 'key=')) {
            $this->reject();
        }
        $key = substr($pairs[0], 4);
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            $this->reject();
        }

        return $url;
    }

    private function containsUnsafeBytes(string $url): bool
    {
        return preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || str_contains($url, '\\');
    }

    private function reject(): never
    {
        throw new BusinessRuleException(FeedbackError::WEBHOOK_INVALID, '企业微信机器人地址无效', 422);
    }
}
