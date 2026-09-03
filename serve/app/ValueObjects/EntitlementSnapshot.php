<?php

namespace App\ValueObjects;

use App\Enums\MembershipState;

/**
 * The immutable, request-time view of a user's membership entitlements.
 *
 * Keeping this value object free of Eloquent models makes it safe to pass to
 * controllers and usage accounting without re-reading package configuration.
 */
final readonly class EntitlementSnapshot
{
    /**
     * @param  array<int, string>  $allowTypes
     */
    public function __construct(
        public MembershipState $state,
        public ?int $packageId,
        public ?RollingPeriod $period,
        public int $linkLimit,
        public int $miniProgramLimit,
        public int $uvLimit,
        public array $allowTypes,
        public bool $preMin = false,
        public bool $curIndex = false,
    ) {
        $this->officialPool = $preMin;
        $this->officialMiniProgramPool = $preMin;
    }

    public readonly bool $officialPool;

    public readonly bool $officialMiniProgramPool;

    /**
     * Alias named after the business capability, for callers that should not
     * need to know the legacy package-config key.
     */
    public function allowsOfficialMiniProgramPool(): bool
    {
        return $this->preMin;
    }

    /**
     * Alias for integrations that refer to the official pool as an official
     * entitlement instead of the historical `pre_min` name.
     */
    public function officialPoolAllowed(): bool
    {
        return $this->preMin;
    }
}
