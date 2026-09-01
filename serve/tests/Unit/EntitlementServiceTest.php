<?php

namespace Tests\Unit;

use App\Enums\MembershipState;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\VipPackage;
use App\Services\EntitlementService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class EntitlementServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_is_unlimited_and_has_official_pool_access(): void
    {
        $admin = User::factory()->create(['type' => 3]);

        $snapshot = app(EntitlementService::class)->resolve($admin, $this->at());

        $this->assertSame(MembershipState::ADMIN, $snapshot->state);
        $this->assertNull($snapshot->packageId);
        $this->assertNull($snapshot->period);
        $this->assertSame(PHP_INT_MAX, $snapshot->linkLimit);
        $this->assertSame(PHP_INT_MAX, $snapshot->miniProgramLimit);
        $this->assertSame(PHP_INT_MAX, $snapshot->uvLimit);
        $this->assertSame(['*'], $snapshot->allowTypes);
        $this->assertTrue($snapshot->preMin);
    }

    public function test_active_member_gets_the_package_snapshot_and_exact_enabled_types(): void
    {
        $member = User::factory()->create([
            'type' => 1,
            'vip_id' => 2,
            'start_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'),
        ]);

        $snapshot = app(EntitlementService::class)->resolve($member, $this->at());

        $this->assertSame(MembershipState::ACTIVE, $snapshot->state);
        $this->assertSame(2, $snapshot->packageId);
        $this->assertSame(5, $snapshot->linkLimit);
        $this->assertSame(5, $snapshot->miniProgramLimit);
        $this->assertSame(50, $snapshot->uvLimit);
        $this->assertTrue($snapshot->preMin);
        $allowTypes = $snapshot->allowTypes;
        sort($allowTypes);
        $expectedAllowTypes = [
            'CLI_QR',
            'KING_DOC',
            'LANDING_MINI',
            'MINI_PROGRAM',
            'QR_QQ',
            'WORK_WECHAT',
        ];
        sort($expectedAllowTypes);
        $this->assertSame($expectedAllowTypes, $allowTypes);
        $this->assertSame('2026-09-15 10:00:00', $snapshot->period?->start()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 10:00:00', $snapshot->period?->end()->format('Y-m-d H:i:s'));
    }

    public function test_trial_package_resolves_to_trial_with_its_configured_limits(): void
    {
        $member = User::factory()->create([
            'vip_id' => 1,
            'start_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-09-18 10:00:00', 'Asia/Shanghai'),
        ]);

        $snapshot = app(EntitlementService::class)->resolve($member, CarbonImmutable::parse('2026-09-16 10:00:00', 'Asia/Shanghai'));

        $this->assertSame(MembershipState::TRIAL, $snapshot->state);
        $this->assertSame(1, $snapshot->linkLimit);
        $this->assertSame(0, $snapshot->miniProgramLimit);
        $this->assertSame(500, $snapshot->uvLimit);
        $this->assertSame(['MINI_PROGRAM'], $snapshot->allowTypes);
        $this->assertTrue($snapshot->preMin);
    }

    public function test_exact_start_is_active_and_exact_end_is_expired(): void
    {
        $member = User::factory()->create([
            'vip_id' => 2,
            'start_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'),
        ]);
        $service = app(EntitlementService::class);

        $this->assertSame(
            MembershipState::ACTIVE,
            $service->resolve($member, CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'))->state,
        );
        $expired = $service->resolve($member, CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'));
        $this->assertSame(MembershipState::EXPIRED, $expired->state);
        $this->assertNull($expired->period);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('会员已过期');
        $service->assertActive($member, CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'));
    }

    public function test_none_and_missing_or_malformed_packages_have_distinct_stable_errors(): void
    {
        $service = app(EntitlementService::class);
        $at = $this->at();

        $none = User::factory()->create();
        $this->assertSame(MembershipState::NONE, $service->resolve($none, $at)->state);
        try {
            $service->assertActive($none, $at);
            $this->fail('无会员权益必须被拒绝');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('NO_ENTITLEMENT', $exception->errorCode);
        }

        $missingPackage = User::factory()->create([
            'vip_id' => 999999,
            'start_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'),
        ]);
        $this->assertSame(MembershipState::NONE, $service->resolve($missingPackage, $at)->state);
        try {
            $service->assertActive($missingPackage, $at);
            $this->fail('缺失套餐必须被拒绝');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('NO_ENTITLEMENT', $exception->errorCode);
        }

        $malformed = VipPackage::query()->create([
            'name' => 'malformed',
            'price' => 0,
            'level' => 1,
            'config' => ['uv_limit' => 10],
        ]);
        $malformedUser = User::factory()->create([
            'vip_id' => $malformed->id,
            'start_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'),
        ]);
        $this->assertSame(MembershipState::NONE, $service->resolve($malformedUser, $at)->state);
        try {
            $service->assertActive($malformedUser, $at);
            $this->fail('格式错误的套餐必须被拒绝');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('NO_ENTITLEMENT', $exception->errorCode);
        }
    }

    public function test_zero_uv_limit_is_normalized_to_unlimited_without_changing_zero_other_limits(): void
    {
        $package = VipPackage::query()->findOrFail(2);
        $config = $package->config;
        $config['uv_limit'] = 0;
        $config['count_limit'] = 0;
        $config['min_count_limit'] = 0;
        $package->update(['config' => $config]);
        $member = User::factory()->create([
            'vip_id' => $package->id,
            'start_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'),
        ]);

        $snapshot = app(EntitlementService::class)->resolve($member, $this->at());

        $this->assertSame(PHP_INT_MAX, $snapshot->uvLimit);
        $this->assertSame(0, $snapshot->linkLimit);
        $this->assertSame(0, $snapshot->miniProgramLimit);
    }

    private function at(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-20 10:00:00', 'Asia/Shanghai');
    }
}
