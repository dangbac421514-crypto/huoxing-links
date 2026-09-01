<?php

namespace Tests\Feature;

use App\Enums\VipStatus;
use App\Models\User;
use App\Models\VipLogs;
use App\Models\VipPackage;
use App\Services\MembershipService;
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
        $this->artisan('app:vip-expired', ['--at' => '2026-09-15 10:00:00'])
            ->expectsOutput('processed=1')
            ->assertExitCode(0);

        $this->assertNull($user->refresh()->vip_id);

        $this->artisan('app:vip-expired', ['--at' => '2026-09-15 10:00:00'])
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

        $this->artisan('app:vip-expired', ['--at' => '2026-10-15 10:00:00'])
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

        $this->artisan('app:vip-expired', ['--at' => '2026-09-15 10:00:00'])->assertExitCode(0);

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
}
