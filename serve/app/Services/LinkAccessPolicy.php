<?php

namespace App\Services;

use App\DTO\AccessDecision;
use App\Enums\LinkType;
use App\Exceptions\BusinessRuleException;
use App\Models\Link;
use App\Models\User;
use App\Support\LinkError;
use Carbon\CarbonImmutable;

final class LinkAccessPolicy
{
    public function __construct(private readonly EntitlementService $entitlements) {}

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

        try {
            // The foundation service is the only source of current membership
            // state and rolling-period entitlements.
            $snapshot = $this->entitlements->assertActive($owner, $at);
        } catch (BusinessRuleException) {
            return AccessDecision::deny(LinkError::MEMBERSHIP_EXPIRED);
        }

        $rawType = $link->getRawOriginal('type');
        $type = is_numeric($rawType) ? LinkType::tryFrom((int) $rawType) : null;
        if (! $type) {
            return AccessDecision::deny(LinkError::LINK_TYPE_UNSUPPORTED);
        }

        if (! in_array('*', $snapshot->allowTypes, true) && ! in_array($type->name, $snapshot->allowTypes, true)) {
            return AccessDecision::deny(LinkError::LINK_TYPE_FORBIDDEN);
        }

        return AccessDecision::allow();
    }
}
