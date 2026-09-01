<?php

namespace App\Services;

use App\Enums\MembershipState;
use App\Enums\UserType;
use App\Enums\VipStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\MembershipChange;
use App\Models\User;
use App\Models\VipLogs;
use App\Models\VipPackage;
use App\ValueObjects\RollingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class MembershipService
{
    public function grantConfiguredTrial(User $user): ?VipLogs
    {
        return DB::transaction(function () use ($user): ?VipLogs {
            $locked = $this->lockUser($user);
            $existing = VipLogs::query()
                ->where('user_id', $locked->id)
                ->where('action', 'trial')
                ->orderBy('id')
                ->first();
            if ($existing) {
                return $existing;
            }

            $at = $this->now();
            if (! $this->configuredBoolean('is_give_vip') || $this->hasEntitlement($locked, $at)) {
                return null;
            }

            $days = (int) SystemConfig::get('give_vip_days', 0);
            $packageId = (int) SystemConfig::get('give_vip_id', 0);
            if ($days < 1 || $packageId < 1) {
                return null;
            }
            $package = VipPackage::query()->find($packageId);
            if (! $package) {
                return null;
            }

            $before = $this->snapshot($locked, $at);
            $start = $at;
            $end = $start->addDays($days);
            $locked->forceFill([
                'vip_id' => $package->id,
                'start_at' => $start,
                'end_at' => $end,
            ])->save();

            return $this->writeLog(
                $locked,
                null,
                'trial',
                '注册体验',
                $before,
                $this->snapshot($locked, $at, MembershipState::TRIAL),
                $at,
                Str::uuid()->toString(),
            );
        });
    }

    public function open(User $user, VipPackage $package, User $actor, string $reason, string $idempotencyKey): VipLogs
    {
        $this->validateReasonAndKey($reason, $idempotencyKey);

        return $this->runLogMutation($idempotencyKey, $user->id, 'open', $package->id, function () use ($user, $package, $actor, $reason, $idempotencyKey): VipLogs {
            $locked = $this->lockUser($user);
            $actor = $this->enabledAdmin($actor);
            $existing = $this->existingLog($idempotencyKey, $locked->id, 'open', $package->id);
            if ($existing) {
                return $existing;
            }

            $at = $this->now();
            if ($this->hasEntitlement($locked, $at)) {
                throw $this->rule('membership_already_active', '当前会员仍在有效期内');
            }
            $package = $this->freshPackage($package);
            $before = $this->snapshot($locked, $at);
            $start = $at;
            $end = $this->storageDate(RollingPeriod::forAnchor($start, $at)->end(), $at);
            $locked->forceFill([
                'vip_id' => $package->id,
                'start_at' => $start,
                'end_at' => $end,
            ])->save();
            $this->cancelPendingChanges($locked->id);

            return $this->writeLog(
                $locked,
                $actor,
                'open',
                $reason,
                $before,
                $this->snapshot($locked, $at),
                $at,
                $idempotencyKey,
            );
        });
    }

    public function renew(User $user, VipPackage $package, User $actor, string $reason, string $idempotencyKey): VipLogs
    {
        $this->validateReasonAndKey($reason, $idempotencyKey);

        return $this->runLogMutation($idempotencyKey, $user->id, 'renew', $package->id, function () use ($user, $package, $actor, $reason, $idempotencyKey): VipLogs {
            $locked = $this->lockUser($user);
            $actor = $this->enabledAdmin($actor);
            $existing = $this->existingLog($idempotencyKey, $locked->id, 'renew', $package->id);
            if ($existing) {
                return $existing;
            }

            $at = $this->now();
            $package = $this->freshPackage($package);
            $current = $this->currentPackage($locked);
            if (! $this->hasEntitlement($locked, $at)) {
                throw $this->rule('membership_not_active', '当前会员不在有效期内');
            }
            if (! $current || $current->id !== $package->id) {
                throw $this->rule('membership_package_mismatch', '续期必须使用当前套餐');
            }
            $anchor = $this->immutableDate($locked->start_at);
            $end = $this->storageDate(
                RollingPeriod::forAnchor($anchor, $this->immutableDate($locked->end_at))->end(),
                $at,
            );
            $before = $this->snapshot($locked, $at);
            $locked->forceFill(['end_at' => $end])->save();
            $this->cancelPendingChanges($locked->id);

            return $this->writeLog(
                $locked,
                $actor,
                'renew',
                $reason,
                $before,
                $this->snapshot($locked, $at),
                $at,
                $idempotencyKey,
            );
        });
    }

    public function upgrade(User $user, VipPackage $package, User $actor, string $reason, string $idempotencyKey): VipLogs
    {
        $this->validateReasonAndKey($reason, $idempotencyKey);

        return $this->runLogMutation($idempotencyKey, $user->id, 'upgrade', $package->id, function () use ($user, $package, $actor, $reason, $idempotencyKey): VipLogs {
            $locked = $this->lockUser($user);
            $actor = $this->enabledAdmin($actor);
            $existing = $this->existingLog($idempotencyKey, $locked->id, 'upgrade', $package->id);
            if ($existing) {
                return $existing;
            }

            $at = $this->now();
            $package = $this->freshPackage($package);
            $current = $this->currentPackage($locked);
            if (! $this->hasEntitlement($locked, $at)) {
                throw $this->rule('membership_not_active', '当前会员不在有效期内');
            }
            if (! $current || $package->level <= $current->level) {
                throw $this->rule('invalid_upgrade_direction', '升级套餐等级必须更高');
            }
            $before = $this->snapshot($locked, $at);
            $locked->forceFill(['vip_id' => $package->id])->save();
            $this->cancelPendingChanges($locked->id);

            return $this->writeLog(
                $locked,
                $actor,
                'upgrade',
                $reason,
                $before,
                $this->snapshot($locked, $at),
                $at,
                $idempotencyKey,
            );
        });
    }

    public function scheduleDowngrade(User $user, VipPackage $package, User $actor, string $reason, string $idempotencyKey): MembershipChange
    {
        $this->validateReasonAndKey($reason, $idempotencyKey);

        return $this->runChangeMutation($idempotencyKey, $user->id, 'downgrade', $package->id, function () use ($user, $package, $actor, $reason, $idempotencyKey): MembershipChange {
            $locked = $this->lockUser($user);
            $actor = $this->enabledAdmin($actor);
            $existing = MembershipChange::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->matchingChange($existing, $locked->id, 'downgrade', $package->id);
            }

            $at = $this->now();
            $package = $this->freshPackage($package);
            $current = $this->currentPackage($locked);
            if (! $this->hasEntitlement($locked, $at) || ! $current) {
                throw $this->rule('membership_not_active', '当前会员不在有效期内');
            }
            if ($package->level >= $current->level) {
                throw $this->rule('invalid_downgrade_direction', '降级套餐等级必须更低');
            }
            if (MembershipChange::query()->where('user_id', $locked->id)->where('status', 'pending')->exists()) {
                throw $this->rule('membership_change_pending', '已有待生效的会员变更');
            }

            $effectiveAt = $this->storageDate(
                RollingPeriod::forAnchor(
                    $this->immutableDate($locked->start_at),
                    $at,
                )->end(),
                $at,
            );

            $before = $this->snapshot($locked, $at);
            $change = MembershipChange::query()->create([
                'user_id' => $locked->id,
                'from_vip_id' => $current->id,
                'to_vip_id' => $package->id,
                'action' => 'downgrade',
                'status' => 'pending',
                'effective_at' => $effectiveAt,
                'actor_user_id' => $actor->id,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->writeLog(
                $locked,
                $actor,
                'downgrade',
                $reason,
                $before,
                $before,
                $effectiveAt,
                $idempotencyKey,
            );

            return $change;
        });
    }

    public function revoke(User $user, User $actor, string $reason, string $idempotencyKey): VipLogs
    {
        $this->validateReasonAndKey($reason, $idempotencyKey);

        return $this->runLogMutation($idempotencyKey, $user->id, 'revoke', null, function () use ($user, $actor, $reason, $idempotencyKey): VipLogs {
            $locked = $this->lockUser($user);
            $actor = $this->enabledAdmin($actor);
            $existing = $this->existingLog($idempotencyKey, $locked->id, 'revoke');
            if ($existing) {
                return $existing;
            }

            $at = $this->now();
            $before = $this->snapshot($locked, $at);
            $locked->forceFill([
                'vip_id' => null,
                'start_at' => null,
                'end_at' => null,
            ])->save();
            $this->cancelPendingChanges($locked->id);

            return $this->writeLog(
                $locked,
                $actor,
                'revoke',
                $reason,
                $before,
                $this->snapshot($locked, $at),
                $at,
                $idempotencyKey,
            );
        });
    }

    public function expireDue(CarbonImmutable $at): int
    {
        $ids = User::query()
            ->whereNotNull('vip_id')
            ->whereNotNull('start_at')
            ->whereNotNull('end_at')
            ->where('end_at', '<=', $at)
            ->pluck('id');
        $processed = 0;

        foreach ($ids as $id) {
            $processed += DB::transaction(function () use ($id, $at): int {
                $user = User::query()->lockForUpdate()->find($id);
                if (! $user || ! $user->vip_id || ! $user->start_at || ! $user->end_at) {
                    return 0;
                }
                $userEnd = $this->immutableDate($user->end_at);
                if ($this->compareDates($userEnd, $at) > 0) {
                    return 0;
                }

                $idempotencyKey = 'vip-expired:'.$user->id.':'.$userEnd->format('Y-m-d H:i:s');
                if (VipLogs::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                    return 0;
                }

                $log = VipLogs::query()
                    ->where('user_id', $user->id)
                    ->where('status', VipStatus::ACTIVE)
                    ->where('vip_id', $user->vip_id)
                    ->where('start_at', $user->start_at)
                    ->where('end_at', $user->end_at)
                    ->lockForUpdate()
                    ->orderByDesc('id')
                    ->first();

                $before = $this->snapshot($user, $at);
                $user->forceFill([
                    'vip_id' => null,
                    'start_at' => null,
                    'end_at' => null,
                ])->save();
                $log?->forceFill(['status' => VipStatus::EXPIRED])->save();
                $this->cancelPendingChanges($user->id);
                $this->writeLog(
                    $user,
                    null,
                    'expired',
                    '会员到期',
                    $before,
                    $this->snapshot($user, $at),
                    $at,
                    $idempotencyKey,
                );

                return 1;
            });
        }

        return $processed;
    }

    public function applyDueChanges(CarbonImmutable $at): int
    {
        $ids = MembershipChange::query()
            ->where('status', 'pending')
            ->where('effective_at', '<=', $at)
            ->pluck('id');
        $processed = 0;

        foreach ($ids as $id) {
            $processed += DB::transaction(function () use ($id, $at): int {
                $candidate = MembershipChange::query()->find($id);
                if (! $candidate) {
                    return 0;
                }
                $user = User::query()->lockForUpdate()->find($candidate->user_id);
                if (! $user) {
                    return 0;
                }
                $change = MembershipChange::query()->lockForUpdate()->find($id);
                if (! $change || $change->status !== 'pending' || $this->compareDates($change->effective_at, $at) > 0) {
                    return 0;
                }

                if (! $this->hasEntitlement($user, $at)) {
                    $before = $this->snapshot($user, $at);
                    if ($user->vip_id || $user->start_at || $user->end_at) {
                        $user->forceFill([
                            'vip_id' => null,
                            'start_at' => null,
                            'end_at' => null,
                        ])->save();
                        $this->writeLog(
                            $user,
                            null,
                            'expired',
                            '会员到期',
                            $before,
                            $this->snapshot($user, $at),
                            $at,
                            'membership-expired:change:'.$change->id,
                        );
                    }
                    $change->forceFill(['status' => 'cancelled'])->save();
                    $this->cancelPendingChanges($user->id, $change->id);

                    return 1;
                }

                if ((int) $user->vip_id !== (int) $change->from_vip_id) {
                    $change->forceFill(['status' => 'cancelled'])->save();

                    return 1;
                }

                $before = $this->snapshot($user, $at);
                $user->forceFill(['vip_id' => $change->to_vip_id])->save();
                $change->forceFill(['status' => 'applied'])->save();
                $this->writeLog(
                    $user,
                    $change->actor,
                    'downgrade_applied',
                    $change->reason ?? '到期降级',
                    $before,
                    $this->snapshot($user, $at),
                    $at,
                    'membership-change:'.$change->id,
                );

                return 1;
            });
        }

        return $processed;
    }

    private function runLogMutation(string $idempotencyKey, int $userId, string $action, ?int $packageId, callable $callback): VipLogs
    {
        try {
            return DB::transaction($callback, 3);
        } catch (QueryException $exception) {
            if (! $this->isIdempotencyDuplicate($exception, ['vip_logs'])) {
                throw $exception;
            }

            $existing = VipLogs::query()->where('idempotency_key', $idempotencyKey)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->matchingLog($existing, $userId, $action, $packageId);
        }
    }

    private function runChangeMutation(string $idempotencyKey, int $userId, string $action, ?int $packageId, callable $callback): MembershipChange
    {
        try {
            return DB::transaction($callback, 3);
        } catch (QueryException $exception) {
            if (! $this->isIdempotencyDuplicate($exception, ['membership_changes', 'vip_logs'])) {
                throw $exception;
            }

            $existing = MembershipChange::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->matchingChange($existing, $userId, $action, $packageId);
            }

            $existingLog = VipLogs::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existingLog) {
                throw $this->idempotencyConflict();
            }

            throw $exception;
        }
    }

    private function lockUser(User $user): User
    {
        return User::query()->lockForUpdate()->findOrFail($user->id);
    }

    private function enabledAdmin(User $actor): User
    {
        $fresh = User::query()->find($actor->id);
        if (! $fresh || $fresh->type !== UserType::Admin || ! $fresh->status) {
            throw $this->rule('membership_actor_invalid', '操作者必须是启用中的管理员');
        }

        return $fresh;
    }

    private function freshPackage(VipPackage $package): VipPackage
    {
        return VipPackage::query()->findOrFail($package->id);
    }

    private function currentPackage(User $user): ?VipPackage
    {
        return $user->vip_id ? VipPackage::query()->find($user->vip_id) : null;
    }

    private function existingLog(string $idempotencyKey, int $userId, string $action, ?int $packageId = null): ?VipLogs
    {
        $existing = VipLogs::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->matchingLog($existing, $userId, $action, $packageId);
        }

        return $existing;
    }

    private function matchingLog(VipLogs $log, int $userId, string $action, ?int $packageId = null): VipLogs
    {
        if ((int) $log->user_id !== $userId || $log->action !== $action || ($packageId !== null && (int) $log->vip_id !== $packageId)) {
            throw $this->idempotencyConflict();
        }

        return $log;
    }

    private function matchingChange(MembershipChange $change, int $userId, string $action, ?int $packageId = null): MembershipChange
    {
        if (
            (int) $change->user_id !== $userId
            || $change->action !== $action
            || ($packageId !== null && (int) $change->to_vip_id !== $packageId)
        ) {
            throw $this->idempotencyConflict();
        }

        return $change;
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function isIdempotencyDuplicate(QueryException $exception, array $tables): bool
    {
        $errorInfo = $exception->errorInfo;
        if ((string) ($errorInfo[0] ?? $exception->getCode()) !== '23000' || (int) ($errorInfo[1] ?? 0) !== 1062) {
            return false;
        }

        $message = (string) ($errorInfo[2] ?? $exception->getMessage());
        foreach ($tables as $table) {
            if (str_contains($message, $table.'.'.$table.'_idempotency_key_unique')) {
                return true;
            }
        }

        return false;
    }

    private function cancelPendingChanges(int $userId, ?int $exceptId = null): void
    {
        MembershipChange::query()
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['status' => 'cancelled']);
    }

    private function writeLog(
        User $user,
        ?User $actor,
        string $action,
        string $reason,
        array $before,
        array $after,
        CarbonImmutable $effectiveAt,
        string $idempotencyKey,
    ): VipLogs {
        return VipLogs::query()->create([
            'user_id' => $user->id,
            'actor_user_id' => $actor?->id,
            'vip_id' => $user->vip_id,
            'status' => $user->vip_id ? VipStatus::ACTIVE : VipStatus::EXPIRED,
            'start_at' => $user->start_at ?? $effectiveAt,
            'end_at' => $user->end_at ?? $effectiveAt,
            'action' => $action,
            'reason' => $reason,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'effective_at' => $effectiveAt,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function snapshot(User $user, CarbonImmutable $at, ?MembershipState $state = null): array
    {
        return [
            'vip_id' => $user->vip_id,
            'start_at' => $user->start_at ? $this->immutableDate($user->start_at)->toIso8601String() : null,
            'end_at' => $user->end_at ? $this->immutableDate($user->end_at)->toIso8601String() : null,
            'state' => ($state ?? $this->state($user, $at))->value,
        ];
    }

    private function state(User $user, CarbonImmutable $at): MembershipState
    {
        if ($user->type === UserType::Admin) {
            return MembershipState::ADMIN;
        }
        if (! $user->vip_id || ! $user->start_at || ! $user->end_at) {
            return MembershipState::NONE;
        }
        if ($this->compareDates($user->end_at, $at) <= 0) {
            return MembershipState::EXPIRED;
        }
        if (($this->currentPackage($user)?->level ?? 1) <= 0) {
            return MembershipState::TRIAL;
        }

        return MembershipState::ACTIVE;
    }

    private function hasEntitlement(User $user, CarbonImmutable $at): bool
    {
        return $user->vip_id !== null
            && $user->start_at !== null
            && $user->end_at !== null
            && $this->compareDates($user->start_at, $at) <= 0
            && $this->compareDates($user->end_at, $at) > 0;
    }

    private function compareDates(mixed $left, mixed $right): int
    {
        return $this->immutableDate($left)->getTimestamp() <=> $this->immutableDate($right)->getTimestamp();
    }

    private function immutableDate(mixed $date): CarbonImmutable
    {
        return $date instanceof CarbonImmutable
            ? $date
            : CarbonImmutable::instance($date instanceof \DateTimeInterface ? $date : CarbonImmutable::parse($date));
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    private function storageDate(CarbonImmutable $date, CarbonImmutable $reference): CarbonImmutable
    {
        return $date->setTimezone($reference->getTimezone());
    }

    private function configuredBoolean(string $key): bool
    {
        return filter_var(SystemConfig::get($key, false), FILTER_VALIDATE_BOOL);
    }

    private function validateReasonAndKey(string $reason, string $idempotencyKey): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('会员变更原因不能为空');
        }
        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('会员变更幂等键必须是 UUID');
        }
    }

    private function rule(string $code, string $message): BusinessRuleException
    {
        return new BusinessRuleException($code, $message, 422);
    }

    private function idempotencyConflict(): BusinessRuleException
    {
        return $this->rule('IDEMPOTENCY_CONFLICT', '幂等键与会员或操作不匹配');
    }
}
