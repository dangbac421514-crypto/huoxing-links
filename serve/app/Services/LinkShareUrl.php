<?php

namespace App\Services;

use App\Exceptions\LinkResolutionException;
use App\Models\Domain;
use App\Models\Link;
use App\Support\LinkError;

final class LinkShareUrl
{
    public function __construct(private readonly ShareOriginPolicy $origins) {}

    public function for(Link $link): string
    {
        return $this->originFor($link).'/?code='.$link->getAttribute('code');
    }

    public function qrLandingFor(Link $link, string $visitorToken): string
    {
        $code = $link->getAttribute('code');
        if (! is_string($code) || preg_match('/^[A-Za-z0-9]{8}$/D', $code) !== 1 || $visitorToken === '') {
            throw new LinkResolutionException(LinkError::SHARE_ORIGIN_UNAVAILABLE, '二维码落地地址暂不可用', 503);
        }

        return $this->originFor($link).'/qr/'.$code.'?visitor_token='.rawurlencode($visitorToken);
    }

    public function assertQrLandingTarget(Link $link, string $target): void
    {
        $parts = parse_url($target);
        $query = is_array($parts) ? ($parts['query'] ?? null) : null;
        if (
            ! is_string($query)
            || substr_count($query, '&') !== 0
            || preg_match('/^visitor_token=([^#]+)$/D', $query, $matches) !== 1
        ) {
            throw new LinkResolutionException(LinkError::UNSAFE_URL, '二维码落地地址暂不可用', 502);
        }

        $token = rawurldecode($matches[1]);
        if ($token === '' || strlen($token) > 4096 || rawurlencode($token) !== $matches[1]) {
            throw new LinkResolutionException(LinkError::UNSAFE_URL, '二维码落地地址暂不可用', 502);
        }

        if (! hash_equals($this->qrLandingFor($link, $token), $target)) {
            throw new LinkResolutionException(LinkError::UNSAFE_URL, '二维码落地地址暂不可用', 502);
        }
    }

    private function originFor(Link $link): string
    {
        $domainId = data_get($link->getAttribute('config'), 'domain_id');
        $selected = $domainId ? Domain::query()->find($domainId) : null;
        $origin = $selected ? $this->origins->forDomain($selected) : null;
        $origin ??= $this->origins->publicOrigin();
        if ($origin === null) {
            throw new LinkResolutionException(LinkError::SHARE_ORIGIN_UNAVAILABLE, '分享地址暂不可用', 503);
        }

        return $origin;
    }
}
