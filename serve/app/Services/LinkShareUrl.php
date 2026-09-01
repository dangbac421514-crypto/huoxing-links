<?php

namespace App\Services;

use App\Exceptions\LinkResolutionException;
use App\Models\Domain;
use App\Models\Link;
use App\Support\LinkError;

final class LinkShareUrl
{
    public function for(Link $link): string
    {
        $domainId = data_get($link->getAttribute('config'), 'domain_id');
        $selected = $domainId ? Domain::query()->find($domainId) : null;
        $origin = null;
        if ($selected && (bool) $selected->getAttribute('enable')) {
            $origin = $this->validatedOrigin((string) $selected->getAttribute('url'));
        }

        $origin ??= $this->validatedOrigin((string) config('app.public_origin', ''));
        if ($origin === null) {
            throw new LinkResolutionException(LinkError::SHARE_ORIGIN_UNAVAILABLE, '分享地址暂不可用', 503);
        }

        return $origin.'/?code='.$link->getAttribute('code');
    }

    private function validatedOrigin(string $raw): ?string
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
