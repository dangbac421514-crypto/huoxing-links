<?php

namespace Tests\Feature;

use App\Enums\VipStatus;
use App\Models\MembershipChange;
use App\Models\User;
use App\Models\VipLogs;
use App\Models\VipPackage;
use App\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class VipExpiredCommandTest extends TestCase
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

    public function test_due_membership_is_projected_once_and_wrong_user_column_is_not_used(): void
    {
        $user = User::factory()->create([
            'type' => 1,
            'status' => true,
            'vip_id' => 2,
            'start_at' => '2026-08-15 10:00:00',
            'end_at' => '2026-09-15 10:00:00',
        ]);
        VipLogs::query()->create([
            'user_id' => $user->id,
            'vip_id' => 2,
            'status' => VipStatus::ACTIVE,
            'start_at' => '2026-08-15 10:00:00',
            'end_at' => '2026-09-15 10:00:00',
            'action' => 'open',
            'idempotency_key' => '00000000-0000-0000-0000-000000000010',
        ]);
        $this->artisan('app:vip-expired', ['--at' => '2026-09-15 18:00:00'])
            ->expectsOutput('processed=1')
            ->assertExitCode(0);

        $this->assertNull($user->refresh()->vip_id);

        $this->artisan('app:vip-expired', ['--at' => '2026-09-15 18:00:00'])
            ->expectsOutput('processed=0')
            ->assertExitCode(0);

        $this->assertSame(1, VipLogs::query()->where('user_id', $user->id)->where('action', 'expired')->count());
    }

    public function test_stale_pre_renewal_log_does_not_expire_the_current_membership(): void
    {
        $user = User::factory()->create([
            'type' => 1,
            'status' => true,
            'vip_id' => 2,
            'start_at' => '2026-08-15 10:00:00',
            'end_at' => '2026-11-15 10:00:00',
        ]);
        $log = VipLogs::query()->create([
            'user_id' => $user->id,
            'vip_id' => 2,
            'status' => VipStatus::ACTIVE,
            'start_at' => '2026-08-15 10:00:00',
            'end_at' => '2026-10-15 10:00:00',
            'action' => 'open',
            'idempotency_key' => '00000000-0000-0000-0000-000000000011',
        ]);

        $this->artisan('app:vip-expired', ['--at' => '2026-10-15 10:00:00'])->assertExitCode(0);

        $this->assertSame(2, $user->refresh()->vip_id);
        $this->assertSame(VipStatus::ACTIVE, $log->refresh()->status);
        $this->assertSame(0, VipLogs::query()->where('user_id', $user->id)->where('action', 'expired')->count());
    }

    public function test_due_downgrade_is_applied_by_the_command(): void
    {
        Date::setTestNow('2026-09-20 10:00:00');
        $user = User::factory()->create([
            'type' => 1,
            'status' => true,
            'vip_id' => 3,
            'start_at' => '2026-09-15 10:00:00',
            'end_at' => '2026-12-15 10:00:00',
        ]);
        $admin = User::factory()->create(['type' => 3, 'status' => true]);
        $change = app(MembershipService::class)->scheduleDowngrade(
            $user,
            VipPackage::query()->findOrFail(2),
            $admin,
            '降级',
            '00000000-0000-0000-0000-000000000012',
        );

        $this->artisan('app:vip-expired', ['--at' => '2026-10-15 18:00:00'])
            ->expectsOutput('processed=1')
            ->assertExitCode(0);

        $this->assertSame(2, $user->refresh()->vip_id);
        $this->assertSame('applied', $change->refresh()->status);
        $this->assertSame(1, VipLogs::query()->where('user_id', $user->id)->where('action', 'downgrade_applied')->count());
    }

    public function test_due_downgrade_is_cancelled_when_membership_expired(): void
    {
        Date::setTestNow('2026-09-20 10:00:00');
        $user = User::factory()->create([
            'type' => 1,
            'status' => true,
            'vip_id' => 3,
            'start_at' => '2026-09-15 10:00:00',
            'end_at' => '2026-09-25 10:00:00',
        ]);
        $admin = User::factory()->create(['type' => 3, 'status' => true]);
        $change = app(MembershipService::class)->scheduleDowngrade(
            $user,
            VipPackage::query()->findOrFail(2),
            $admin,
            '降级',
            '00000000-0000-0000-0000-000000000013',
        );

        $this->artisan('app:vip-expired', ['--at' => '2026-10-15 10:00:00'])->assertExitCode(0);

        $this->assertNull($user->refresh()->vip_id);
        $this->assertSame('cancelled', $change->refresh()->status);
        $this->assertSame(1, VipLogs::query()->where('user_id', $user->id)->where('action', 'expired')->count());
    }

    public function test_explicit_at_is_passed_as_asia_shanghai_time(): void
    {
        $user = User::factory()->create([
            'type' => 1,
            'status' => true,
            'vip_id' => 2,
            'start_at' => '2026-08-15 10:00:00',
            'end_at' => '2026-09-15 10:00:00',
        ]);
        VipLogs::query()->create([
            'user_id' => $user->id,
            'vip_id' => 2,
            'status' => VipStatus::ACTIVE,
            'start_at' => '2026-08-15 10:00:00',
            'end_at' => '2026-09-15 10:00:00',
            'action' => 'open',
            'idempotency_key' => '00000000-0000-0000-0000-000000000014',
        ]);

        $this->artisan('app:vip-expired', ['--at' => '2026-09-15 18:00:00'])->assertExitCode(0);

        $audit = VipLogs::query()->where('user_id', $user->id)->where('action', 'expired')->firstOrFail();
        $this->assertSame('vip-expired:'.$user->id.':2026-09-15 10:00:00', $audit->idempotency_key);
    }

    public function test_expiry_schedule_is_hourly_and_non_overlapping(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('php artisan app:vip-expired')
            ->assertExitCode(0);

        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($event): bool => str_contains($event->command, 'app:vip-expired'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_expiry_uses_the_instant_when_at_has_a_different_timezone(): void
    {
        $user = User::factory()->create([
            'type' => 1,
            'status' => true,
            'vip_id' => 2,
            'start_at' => CarbonImmutable::parse('2026-08-15 09:00:00', 'UTC'),
            'end_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
        ]);
        VipLogs::query()->create([
            'user_id' => $user->id,
            'vip_id' => 2,
            'status' => VipStatus::ACTIVE,
            'start_at' => CarbonImmutable::parse('2026-08-15 09:00:00', 'UTC'),
            'end_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
            'action' => 'open',
            'idempotency_key' => '00000000-0000-0000-0000-000000000015',
        ]);

        $this->artisan('app:vip-expired', ['--at' => '2026-09-15 10:00:00'])->assertExitCode(0);

        $this->assertSame(2, $user->refresh()->vip_id);
        $this->assertSame(0, VipLogs::query()->where('user_id', $user->id)->where('action', 'expired')->count());
    }

    public function test_entitlement_uses_the_instant_when_now_has_a_different_timezone(): void
    {
        Date::setTestNow(CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'));
        $user = User::factory()->create([
            'type' => 1,
            'status' => true,
            'vip_id' => 3,
            'start_at' => CarbonImmutable::parse('2026-08-15 09:00:00', 'UTC'),
            'end_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
        ]);

        $change = MembershipChange::query()->create([
            'user_id' => $user->id,
            'from_vip_id' => 3,
            'to_vip_id' => 2,
            'action' => 'downgrade',
            'status' => 'pending',
            'effective_at' => CarbonImmutable::parse('2026-09-15 01:00:00', 'UTC'),
            'actor_user_id' => User::factory()->create(['type' => 3, 'status' => true])->id,
            'reason' => '降级',
            'idempotency_key' => '00000000-0000-0000-0000-000000000016',
        ]);

        $this->assertSame(1, app(MembershipService::class)->applyDueChanges(
            CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
        ));
        $this->assertSame(2, $user->refresh()->vip_id);
        $this->assertSame('applied', $change->refresh()->status);
    }

    public function test_due_change_and_entitlement_use_the_instant_when_at_has_a_different_timezone(): void
    {
        $user = User::factory()->create([
            'type' => 1,
            'status' => true,
            'vip_id' => 3,
            'start_at' => CarbonImmutable::parse('2026-08-15 09:00:00', 'UTC'),
            'end_at' => CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC'),
        ]);
        $change = MembershipChange::query()->create([
            'user_id' => $user->id,
            'from_vip_id' => 3,
            'to_vip_id' => 2,
            'action' => 'downgrade',
            'status' => 'pending',
            'effective_at' => CarbonImmutable::parse('2026-09-15 01:00:00', 'UTC'),
            'actor_user_id' => User::factory()->create(['type' => 3, 'status' => true])->id,
            'reason' => '降级',
            'idempotency_key' => '00000000-0000-0000-0000-000000000017',
        ]);

        $this->artisan('app:vip-expired', ['--at' => '2026-09-15 10:00:00'])->assertExitCode(0);

        $this->assertSame(2, $user->refresh()->vip_id);
        $this->assertSame('applied', $change->refresh()->status);
    }
}
