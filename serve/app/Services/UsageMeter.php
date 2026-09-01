<?php

namespace App\Services;

use App\Enums\MembershipState;
use App\Exceptions\BusinessRuleException;
use App\Models\UsagePeriod;
use App\Models\UsageVisitor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class UsageMeter
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function consume(User $user, string $visitorId, CarbonImmutable $at): void
    {
        $snapshot = $this->entitlements->assertActive($user, $at);
        if ($visitorId === '') {
            throw new BusinessRuleException('INVALID_VISITOR', '访客标识不能为空');
        }
        if ($snapshot->state === MembershipState::ADMIN) {
            return;
        }
        if ($snapshot->period === null) {
            throw new BusinessRuleException('NO_ENTITLEMENT', '当前账号没有有效会员权益');
        }

        $periodStart = $this->storageDate($snapshot->period->start());
        $periodEnd = $this->storageDate($snapshot->period->end());
        $seenAt = $this->storageDate($at);
        // The raw server-issued visitor value enters the digest exactly once;
        // device_uid never reaches this accounting boundary.
        $visitorHash = hash('sha256', $visitorId);

        DB::transaction(function () use ($user, $visitorHash, $periodStart, $periodEnd, $seenAt, $snapshot): void {
            // The unique key serializes creation across independent MySQL
            // connections. The following SELECT then locks the one canonical
            // row before checking/incrementing its account-level counter.
            DB::table('usage_periods')->insertOrIgnore([
                'user_id' => $user->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'used_uv' => 0,
                'created_at' => $seenAt,
                'updated_at' => $seenAt,
            ]);

            $period = UsagePeriod::query()
                ->where('user_id', $user->id)
                ->where('period_start', $periodStart)
                ->lockForUpdate()
                ->first();
            if (! $period) {
                // This should only be possible when the schema's unique key is
                // absent or the user was concurrently removed.
                throw new BusinessRuleException('NO_ENTITLEMENT', '当前账号没有有效额度周期');
            }

            $seen = UsageVisitor::query()
                ->where('usage_period_id', $period->id)
                ->where('visitor_hash', $visitorHash)
                ->exists();
            if ($seen) {
                return;
            }

            if ($period->used_uv >= $snapshot->uvLimit) {
                throw new BusinessRuleException('QUOTA_EXCEEDED', '本周期 UV 额度已用尽');
            }

            UsageVisitor::query()->create([
                'usage_period_id' => $period->id,
                'visitor_hash' => $visitorHash,
                'first_seen_at' => $seenAt,
                'created_at' => $seenAt,
                'updated_at' => $seenAt,
            ]);
            $period->increment('used_uv', 1, ['updated_at' => $seenAt]);
        }, 5);
    }

    private function storageDate(CarbonImmutable $date): CarbonImmutable
    {
        return $date->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
