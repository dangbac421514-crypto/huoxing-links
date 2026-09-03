<?php

namespace App\Services;

use App\Exceptions\UnsafeUrl;
use GuzzleHttp\Psr7\Uri;
use Throwable;

final class WeixinSchemePolicy
{
    private const MAX_LENGTH = 2048;

    public function assert(string $scheme): string
    {
        $decoded = rawurldecode($scheme);
        if ($scheme === '' || strlen($scheme) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $scheme) === 1 || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1 || str_contains($scheme, '#') || str_contains($scheme, '\\') || str_contains($decoded, '\\') || preg_match('/%(?![0-9a-fA-F]{2})/', $scheme) === 1) {
            throw new UnsafeUrl;
        }

        try {
            $uri = new Uri($scheme);
        } catch (Throwable) {
            throw new UnsafeUrl;
        }

        parse_str($uri->getQuery(), $queryParameters);
        $hasEmptyQueryKey = is_array($queryParameters) && array_key_exists('', $queryParameters);
        $hasQueryValue = is_array($queryParameters) && collect($queryParameters)->contains(
            static fn (mixed $value): bool => is_scalar($value) && (string) $value !== '',
        );

        if (
            strtolower($uri->getScheme()) !== 'weixin'
            || strtolower($uri->getHost()) !== 'dl'
            || $uri->getUserInfo() !== ''
            || $uri->getPort() !== null
            || $uri->getFragment() !== ''
            || $uri->getPath() === ''
            || ! str_starts_with($uri->getPath(), '/')
            || $uri->getQuery() === ''
            || $hasEmptyQueryKey
            || ! $hasQueryValue
        ) {
            throw new UnsafeUrl;
        }

        $authority = $this->rawAuthority($scheme);
        if ($authority !== 'dl') {
            throw new UnsafeUrl;
        }

        return $scheme;
    }

    public function validate(string $scheme): bool
    {
        try {
            $this->assert($scheme);

            return true;
        } catch (UnsafeUrl) {
            return false;
        }
    }

    private function rawAuthority(string $scheme): ?string
    {
        if (preg_match('~^weixin://([^/?#]*)~iD', $scheme, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }
}
