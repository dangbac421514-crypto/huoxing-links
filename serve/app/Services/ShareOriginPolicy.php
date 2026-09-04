<?php

namespace App\Services;

use App\Models\Domain;

final class ShareOriginPolicy
{
    public function forDomain(Domain $domain): ?string
    {
        return (bool) $domain->enable ? $this->normalize((string) $domain->url) : null;
    }

    public function publicOrigin(): ?string
    {
        return $this->normalize((string) config('app.public_origin', ''));
    }

    public function hostFor(Domain $domain): ?string
    {
        $origin = $this->forDomain($domain);

        return $origin === null ? null : parse_url($origin, PHP_URL_HOST);
    }

    private function normalize(string $raw): ?string
    {
        $parts = parse_url($raw);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $port = $parts['port'] ?? null;
        $allowedHosts = config('app.allowed_share_hosts', []);
        if (is_string($allowedHosts)) {
            $allowedHosts = explode(',', $allowedHosts);
        }
        $allowedHosts = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), (array) $allowedHosts);

        if (
            $scheme !== 'https'
            || $host === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || ($port !== null && (int) $port !== 443)
            || ! in_array($host, $allowedHosts, true)
        ) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            return null;
        }

        return 'https://'.$host;
    }
}
