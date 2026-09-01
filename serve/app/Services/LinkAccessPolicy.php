<?php

namespace App\Services;

use App\DTO\AccessDecision;
use App\Models\Link;
use App\Models\User;
use App\Support\LinkError;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;

final class LinkAccessPolicy
{
    public function check(?Link $link, CarbonImmutable $at): AccessDecision
    {
        if (! $link) {
            return AccessDecision::deny(LinkError::LINK_NOT_FOUND);
        }

        if (! (bool) $link->getAttribute('manual_status') || ! (bool) $link->getAttribute('health_status')) {
            return AccessDecision::deny(LinkError::LINK_DISABLED);
        }

        $owner = User::query()->find($link->getAttribute('user_id'));
        if (! $owner || ! (bool) $owner->getAttribute('status')) {
            return AccessDecision::deny(LinkError::USER_DISABLED);
        }

        $type = LinkTypeParser::parse($link->getRawOriginal('type'));
        if (! $type) {
            return AccessDecision::deny(LinkError::LINK_TYPE_UNSUPPORTED);
        }

        return AccessDecision::allow();
    }
}
