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
        $domainId = data_get($link->getAttribute('config'), 'domain_id');
        $selected = $domainId ? Domain::query()->find($domainId) : null;
        $origin = $selected ? $this->origins->forDomain($selected) : null;
        $origin ??= $this->origins->publicOrigin();
        if ($origin === null) {
            throw new LinkResolutionException(LinkError::SHARE_ORIGIN_UNAVAILABLE, '分享地址暂不可用', 503);
        }

        return $origin.'/?code='.$link->getAttribute('code');
    }
}
