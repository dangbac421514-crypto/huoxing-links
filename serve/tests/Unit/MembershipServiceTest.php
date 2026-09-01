<?php

namespace Tests\Unit;

use App\Enums\MembershipState;
use App\Enums\UserType;
use App\Exceptions\BusinessRuleException;
use App\Models\MembershipChange;
use App\Models\User;
use App\Models\VipLogs;
use App\Models\VipPackage;
use App\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class MembershipServiceTest extends TestCase
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

    public function test_first_open_sets_a_new_rolling_anchor_and_audit_snapshot(): void
    {
        Date::setTestNow('2026-09-15 10:00:00');
        $admin = $this->admin();
        $member = $this->member();

        $log = $this->service()->open($member, $this->package(2), $admin, '人工开通', $this->key(1));

        $member = $member->refresh();
        $this->assertSame(2, $member->vip_id);
        $this->assertSame('2026-09-15 10:00:00', $member->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 10:00:00', $member->end_at->format('Y-m-d H:i:s'));
        $this->assertSame('open', $log->action);
        $this->assertSame(MembershipState::ACTIVE->value, $log->after_snapshot['state']);
        $this->assertSame(['vip_id', 'start_at', 'end_at', 'state'], array_keys($log->after_snapshot));
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame('人工开通', $log->reason);
    }

    public function test_first_open_rejects_a_currently_entitled_member(): void
    {
        Date::setTestNow('2026-09-15 10:00:00');
        $member = $this->member([
            'vip_id' => 2,
            'start_at' => '2026-09-01 10:00:00',
            'end_at' => '2026-10-01 10:00:00',
        ]);

        $this->expectException(BusinessRuleException::class);

        $this->service()->open($member, $this->package(2), $this->admin(), '再次开通', $this->key(2));
    }

    public function test_same_package_renews_from_existing_end_and_restores_anchor_after_february_clamp(): void
    {
        Date::setTestNow('2024-02-15 10:00:00');
        $member = $this->member([
            'vip_id' => 2,
            'start_at' => '2024-01-31 10:00:00',
            'end_at' => '2024-02-29 10:00:00',
        ]);

        $log = $this->service()->renew($member, $this->package(2), $this->admin(), '续期', $this->key(3));

        $member = $member->refresh();
        $this->assertSame('2024-01-31 10:00:00', $member->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2024-03-31 10:00:00', $member->end_at->format('Y-m-d H:i:s'));
        $this->assertSame('renew', $log->action);
    }

    public function test_renew_requires_the_same_package_and_an_active_entitlement(): void
    {
        Date::setTestNow('2026-09-15 10:00:00');
        $member = $this->member([
            'vip_id' => 2,
            'start_at' => '2026-08-15 10:00:00',
            'end_at' => '2026-09-15 10:00:00',
        ]);

        $this->expectException(BusinessRuleException::class);

        $this->service()->renew($member, $this->package(2), $this->admin(), '续期', $this->key(4));
    }

    public function test_upgrade_requires_a_strictly_higher_level_and_preserves_paid_time(): void
    {
        Date::setTestNow('2026-09-20 10:00:00');
        $member = $this->member([
            'vip_id' => 2,
            'start_at' => '2026-09-15 10:00:00',
            'end_at' => '2026-10-15 10:00:00',
        ]);

        $log = $this->service()->upgrade($member, $this->package(3), $this->admin(), '升级', $this->key(5));

        $member = $member->refresh();
        $this->assertSame(3, $member->vip_id);
        $this->assertSame('2026-09-15 10:00:00', $member->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 10:00:00', $member->end_at->format('Y-m-d H:i:s'));
        $this->assertSame('upgrade', $log->action);

        $this->expectException(BusinessRuleException::class);
        $this->service()->upgrade($member, $this->package(2), $this->admin(), '错误升级', $this->key(6));
    }

    public function test_downgrade_is_pending_at_the_next_rolling_boundary_and_keeps_remaining_end_time(): void
    {
        Date::setTestNow('2026-09-20 10:00:00');
        $member = $this->member([
            'vip_id' => 3,
            'start_at' => '2026-09-15 10:00:00',
            'end_at' => '2026-12-15 10:00:00',
        ]);
        $admin = $this->admin();

        $change = $this->service()->scheduleDowngrade($member, $this->package(2), $admin, '降级', $this->key(7));

        $this->assertSame('pending', $change->status);
        $this->assertSame('2026-10-15 10:00:00', $change->effective_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, $member->refresh()->vip_id);
        $this->assertDatabaseHas('vip_logs', [
            'user_id' => $member->id,
            'action' => 'downgrade',
            'idempotency_key' => $this->key(7),
        ]);

        $this->assertSame(1, $this->service()->applyDueChanges(CarbonImmutable::parse('2026-10-15 10:00:00')));
        $member = $member->refresh();
        $this->assertSame(2, $member->vip_id);
        $this->assertSame('2026-09-15 10:00:00', $member->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-15 10:00:00', $member->end_at->format('Y-m-d H:i:s'));
        $this->assertSame('applied', $change->refresh()->status);
    }

    public function test_downgrade_requires_a_strictly_lower_level(): void
    {
        Date::setTestNow('2026-09-20 10:00:00');
        $member = $this->member([
            'vip_id' => 2,
            'start_at' => '2026-09-15 10:00:00',
            'end_at' => '2026-10-15 10:00:00',
        ]);

        $this->expectException(BusinessRuleException::class);

        $this->service()->scheduleDowngrade($member, $this->package(3), $this->admin(), '错误降级', $this->key(8));
    }

    public function test_non_admin_actor_is_rejected_by_the_service(): void
    {
        Date::setTestNow('2026-09-15 10:00:00');
        $member = $this->member();

        $this->expectException(BusinessRuleException::class);
        $this->service()->open($member, $this->package(2), $this->member(), '越权', $this->key(9));
    }

    public function test_disabled_admin_actor_is_rejected_by_the_service(): void
    {
        Date::setTestNow('2026-09-15 10:00:00');
        $member = $this->member();
        $disabled = $this->admin(['status' => false]);

        $this->expectException(BusinessRuleException::class);
        $this->service()->open($member, $this->package(2), $disabled, '禁用管理员', $this->key(10));
    }

    public function test_repeated_idempotency_returns_original_log_without_a_second_mutation(): void
    {
        Date::setTestNow('2026-09-15 10:00:00');
        $member = $this->member();
        $admin = $this->admin();
        $key = $this->key(11);

        $first = $this->service()->open($member, $this->package(2), $admin, '开通', $key);
        $member->refresh();
        $second = $this->service()->open($member, $this->package(2), $admin, '重复开通', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VipLogs::query()->where('idempotency_key', $key)->count());
        $this->assertSame('2026-10-15 10:00:00', $member->refresh()->end_at->format('Y-m-d H:i:s'));
    }

    public function test_repeated_downgrade_idempotency_returns_the_original_pending_change(): void
    {
        Date::setTestNow('2026-09-20 10:00:00');
        $member = $this->member([
            'vip_id' => 3,
            'start_at' => '2026-09-15 10:00:00',
            'end_at' => '2026-12-15 10:00:00',
        ]);
        $admin = $this->admin();
        $key = $this->key(18);

        $first = $this->service()->scheduleDowngrade($member, $this->package(2), $admin, '降级', $key);
        $second = $this->service()->scheduleDowngrade($member->refresh(), $this->package(2), $admin, '重复降级', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MembershipChange::query()->where('idempotency_key', $key)->count());
        $this->assertSame(1, VipLogs::query()->where('idempotency_key', $key)->count());
    }

    public function test_configured_trial_is_granted_once_and_never_overwrites_existing_entitlement(): void
    {
        Date::setTestNow('2026-09-15 10:00:00');
        $member = $this->member();

        $first = $this->service()->grantConfiguredTrial($member);
        $second = $this->service()->grantConfiguredTrial($member->refresh());

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, VipLogs::query()->where('user_id', $member->id)->where('action', 'trial')->count());
        $this->assertSame(1, $member->refresh()->vip_id);
        $this->assertSame('2026-09-18 10:00:00', $member->end_at->format('Y-m-d H:i:s'));

        $entitled = $this->member([
            'vip_id' => 2,
            'start_at' => '2026-09-15 09:00:00',
            'end_at' => '2026-10-15 09:00:00',
        ]);
        $this->assertNull($this->service()->grantConfiguredTrial($entitled));
        $this->assertSame(0, VipLogs::query()->where('user_id', $entitled->id)->count());
    }

    public function test_revoke_clears_membership_and_cancels_pending_downgrade(): void
    {
        Date::setTestNow('2026-09-20 10:00:00');
        $member = $this->member([
            'vip_id' => 3,
            'start_at' => '2026-09-15 10:00:00',
            'end_at' => '2026-10-15 10:00:00',
        ]);
        $admin = $this->admin();
        $change = $this->service()->scheduleDowngrade($member, $this->package(2), $admin, '降级', $this->key(12));

        $log = $this->service()->revoke($member, $admin, '撤销', $this->key(13));

        $member = $member->refresh();
        $this->assertNull($member->vip_id);
        $this->assertNull($member->start_at);
        $this->assertNull($member->end_at);
        $this->assertSame('revoke', $log->action);
        $this->assertSame('cancelled', $change->refresh()->status);
        $this->assertSame(0, $this->service()->applyDueChanges(CarbonImmutable::parse('2026-11-01 00:00:00')));
        $this->assertNull($member->refresh()->vip_id);
    }

    public function test_expiry_wins_over_a_due_downgrade_when_no_entitlement_remains(): void
    {
        Date::setTestNow('2026-09-20 10:00:00');
        $member = $this->member([
            'vip_id' => 3,
            'start_at' => '2026-09-15 10:00:00',
            'end_at' => '2026-09-25 10:00:00',
        ]);
        $change = $this->service()->scheduleDowngrade($member, $this->package(2), $this->admin(), '降级', $this->key(14));

        $this->assertSame(1, $this->service()->applyDueChanges(CarbonImmutable::parse('2026-10-15 10:00:00')));
        $this->assertNull($member->refresh()->vip_id);
        $this->assertSame('cancelled', $change->refresh()->status);
    }

    public function test_expire_due_is_idempotent_and_writes_an_expiry_audit(): void
    {
        Date::setTestNow('2026-09-15 10:00:00');
        $member = $this->member();
        $this->service()->open($member, $this->package(2), $this->admin(), '占位', $this->key(15));
        VipLogs::query()
            ->where('user_id', $member->id)
            ->where('action', 'open')
            ->update(['end_at' => '2026-09-15 10:00:00']);
        $member->update(['end_at' => '2026-09-15 10:00:00']);

        $at = CarbonImmutable::parse('2026-09-15 10:00:00');
        $this->assertSame(1, $this->service()->expireDue($at));
        $this->assertSame(0, $this->service()->expireDue($at));
        $this->assertNull($member->refresh()->vip_id);
        $this->assertDatabaseHas('vip_logs', ['user_id' => $member->id, 'action' => 'expired']);
    }

    public function test_expire_due_does_not_expire_an_old_log_after_a_renewal_extended_the_end(): void
    {
        Date::setTestNow('2026-09-15 10:00:00');
        $member = $this->member();
        $admin = $this->admin();
        $this->service()->open($member, $this->package(2), $admin, '开通', $this->key(16));
        $member->update([
            'start_at' => '2026-08-15 10:00:00',
            'end_at' => '2026-10-15 10:00:00',
        ]);
        Date::setTestNow('2026-09-20 10:00:00');
        $this->service()->renew($member->refresh(), $this->package(2), $admin, '续期', $this->key(17));

        $this->assertSame(0, $this->service()->expireDue(CarbonImmutable::parse('2026-10-15 10:00:00')));
        $this->assertSame(2, $member->refresh()->vip_id);
        $this->assertSame('2026-11-15 10:00:00', $member->end_at->format('Y-m-d H:i:s'));
    }

    private function service(): MembershipService
    {
        return app(MembershipService::class);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['type' => UserType::MEMBER, 'status' => true], $attributes));
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['type' => UserType::Admin, 'status' => true], $attributes));
    }

    private function package(int $id): VipPackage
    {
        return VipPackage::query()->findOrFail($id);
    }

    private function key(int $suffix): string
    {
        return sprintf('00000000-0000-0000-0000-%012d', $suffix);
    }
}
