<?php

namespace App\Services;

use App\Enums\LinkType;
use App\Enums\MembershipState;
use App\Enums\UserType;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\VipPackage;
use App\ValueObjects\EntitlementSnapshot;
use App\ValueObjects\RollingPeriod;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class EntitlementService
{
    private const TIMEZONE = 'Asia/Shanghai';

    public function resolve(User $user, ?CarbonImmutable $at = null): EntitlementSnapshot
    {
        $at ??= CarbonImmutable::now(self::TIMEZONE);
        $at = $at->setTimezone(self::TIMEZONE);

        if ($this->isAdmin($user)) {
            return new EntitlementSnapshot(
                MembershipState::ADMIN,
                null,
                null,
                PHP_INT_MAX,
                PHP_INT_MAX,
                PHP_INT_MAX,
                ['*'],
                true,
                true,
            );
        }

        // NONE describes an account without a complete membership tuple. It
        // is intentionally separate from EXPIRED, which has all three values.
        if (! $user->vip_id || ! $user->start_at || ! $user->end_at) {
            return $this->none();
        }

        $package = $user->relationLoaded('vipPackage')
            ? $user->getRelation('vipPackage')
            : VipPackage::query()->find($user->vip_id);
        if (! $package instanceof VipPackage) {
            return $this->none();
        }

        $config = $package->config;
        if (! $this->isValidConfig($config)) {
            return $this->none();
        }

        // Membership timestamps are legacy MySQL wall-clock values. Read the
        // raw value in the product timezone so old rows and new lifecycle
        // writes use the same anchored calendar semantics.
        $start = $this->membershipDate($user, 'start_at');
        $end = $this->membershipDate($user, 'end_at');

        if ($end->lte($at)) {
            return new EntitlementSnapshot(MembershipState::EXPIRED, null, null, 0, 0, 0, []);
        }

        // A future start is not an active entitlement. MembershipService does
        // not create such a projection, but treating it as NONE keeps reads
        // and usage accounting safe if legacy data contains one.
        if ($start->gt($at)) {
            return $this->none();
        }

        $allowTypes = [];
        foreach (LinkType::getAllType() as $type) {
            if ($config['allow_type'][$type] === true) {
                $allowTypes[] = $type;
            }
        }

        return new EntitlementSnapshot(
            (int) $package->level <= 0 ? MembershipState::TRIAL : MembershipState::ACTIVE,
            (int) $package->id,
            RollingPeriod::forAnchor($start, $at),
            (int) $config['count_limit'],
            (int) $config['min_count_limit'],
            $this->normalizeUvLimit((int) $config['uv_limit']),
            $allowTypes,
            $config['pre_min'],
            $config['cur_index'],
        );
    }

    public function assertActive(User $user, ?CarbonImmutable $at = null): EntitlementSnapshot
    {
        $snapshot = $this->resolve($user, $at);

        if ($snapshot->state === MembershipState::EXPIRED) {
            throw new BusinessRuleException('MEMBERSHIP_EXPIRED', '会员已过期');
        }
        if ($snapshot->state === MembershipState::NONE) {
            throw new BusinessRuleException('NO_ENTITLEMENT', '当前账号没有有效会员权益');
        }

        return $snapshot;
    }

    private function none(): EntitlementSnapshot
    {
        return new EntitlementSnapshot(MembershipState::NONE, null, null, 0, 0, 0, []);
    }

    private function isAdmin(User $user): bool
    {
        $type = $user->getAttribute('type');

        return $type === UserType::Admin
            || ((is_int($type) || is_string($type)) && (int) $type === UserType::Admin->value);
    }

    private function normalizeUvLimit(int $limit): int
    {
        return $limit === 0 ? PHP_INT_MAX : $limit;
    }

    /**
     * Package forms validate these keys, but old rows can predate that
     * validation. Invalid rows must not accidentally grant a partial quota.
     */
    private function isValidConfig(mixed $config): bool
    {
        if (! is_array($config)) {
            return false;
        }

        foreach (['uv_limit', 'count_limit', 'min_count_limit'] as $key) {
            if (! array_key_exists($key, $config) || ! is_int($config[$key]) || $config[$key] < 0) {
                return false;
            }
        }
        foreach (['pre_min', 'min_disabled_check', 'support', 'cur_index'] as $key) {
            if (! array_key_exists($key, $config) || ! is_bool($config[$key])) {
                return false;
            }
        }

        $allowType = $config['allow_type'] ?? null;
        if (! is_array($allowType) || array_diff(LinkType::getAllType(), array_keys($allowType)) !== []) {
            return false;
        }
        foreach ($allowType as $enabled) {
            if (! is_bool($enabled)) {
                return false;
            }
        }

        return true;
    }

    private function immutableDate(mixed $date): CarbonImmutable
    {
        if ($date instanceof CarbonImmutable) {
            return $date;
        }
        if ($date instanceof DateTimeInterface) {
            return CarbonImmutable::instance($date);
        }

        return CarbonImmutable::parse((string) $date);
    }

    private function membershipDate(User $user, string $attribute): CarbonImmutable
    {
        $raw = $user->getRawOriginal($attribute);
        $value = $user->getAttribute($attribute);
        if ($user->isDirty($attribute)) {
            return $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE)
                : CarbonImmutable::parse((string) $value, self::TIMEZONE);
        }
        if (is_string($raw) && $raw !== '') {
            return CarbonImmutable::parse($raw, self::TIMEZONE);
        }

        return $this->immutableDate($value)->setTimezone(self::TIMEZONE);
    }
}
