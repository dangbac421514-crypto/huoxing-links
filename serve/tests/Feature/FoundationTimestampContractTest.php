<?php

namespace Tests\Feature;

use App\Enums\MembershipState;
use App\Models\User;
use App\Models\VipPackage;
use App\Services\EntitlementService;
use App\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class FoundationTimestampContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_lifecycle_membership_keeps_one_instant_after_mysql_round_trip(): void
    {
        $openedAt = CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai');
        Date::setTestNow($openedAt);

        $admin = User::factory()->create(['type' => 3, 'status' => true]);
        $member = User::factory()->create(['type' => 1, 'status' => true]);
        $package = VipPackage::query()->findOrFail(2);

        app(MembershipService::class)->open(
            $member,
            $package,
            $admin,
            '时间契约测试',
            '00000000-0000-4000-8000-000000000101',
        );

        // Resolve a new model instance so an in-memory Carbon object cannot
        // hide a storage/query timezone conversion.
        $reloaded = User::query()->findOrFail($member->id);
        $oneHourBeforeEnd = CarbonImmutable::parse('2026-10-15 09:00:00', 'Asia/Shanghai');
        $exactEnd = CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai');
        $entitlements = app(EntitlementService::class);

        $active = $entitlements->resolve($reloaded, $oneHourBeforeEnd);
        $this->assertSame(MembershipState::ACTIVE, $active->state);
        $this->assertSame($openedAt->getTimestamp(), $reloaded->start_at->getTimestamp());
        $this->assertSame($exactEnd->getTimestamp(), $reloaded->end_at->getTimestamp());
        $this->assertSame('2026-09-15 10:00:00', $active->period?->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 10:00:00', $active->period?->end()->format('Y-m-d H:i:s'));

        $expired = $entitlements->resolve($reloaded, $exactEnd);
        $this->assertSame(MembershipState::EXPIRED, $expired->state);
        $this->assertNull($expired->period);
    }
}
