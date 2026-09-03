<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Models\VipPackage;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminMembershipTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_admin_api_delegates_manual_open_to_membership_service(): void
    {
        $this->seed();
        Date::setTestNow('2026-09-15 10:00:00');
        $admin = User::factory()->create([
            'type' => UserType::Admin,
            'status' => true,
            'must_change_password' => false,
        ]);
        $member = User::factory()->create(['type' => UserType::MEMBER, 'status' => true]);
        Sanctum::actingAs($admin, ['*'], 'api');
        $key = '00000000-0000-0000-0000-000000000009';

        $this->putJson('/api/users/'.$member->id, [
            'vip_id' => VipPackage::query()->findOrFail(2)->id,
            'action' => 'open',
            'reason' => '人工开通',
            'idempotency_key' => $key,
        ])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $member->id, 'vip_id' => 2]);
        $this->assertDatabaseHas('vip_logs', [
            'user_id' => $member->id,
            'action' => 'open',
            'idempotency_key' => $key,
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_admin_api_requires_a_server_validated_action_reason_and_uuid(): void
    {
        $this->seed();
        $admin = User::factory()->create([
            'type' => UserType::Admin,
            'status' => true,
            'must_change_password' => false,
        ]);
        $member = User::factory()->create(['type' => UserType::MEMBER, 'status' => true]);
        Sanctum::actingAs($admin, ['*'], 'api');

        $this->putJson('/api/users/'.$member->id, [
            'vip_id' => 2,
            'action' => 'not-a-lifecycle-action',
            'reason' => '',
            'idempotency_key' => 'not-a-uuid',
        ])->assertStatus(422);
    }

    public function test_admin_api_does_not_accept_client_supplied_membership_times(): void
    {
        $this->seed();
        Date::setTestNow('2026-09-15 10:00:00');
        $admin = User::factory()->create([
            'type' => UserType::Admin,
            'status' => true,
            'must_change_password' => false,
        ]);
        $member = User::factory()->create(['type' => UserType::MEMBER, 'status' => true]);
        Sanctum::actingAs($admin, ['*'], 'api');

        $this->putJson('/api/users/'.$member->id, [
            'vip_id' => 2,
            'action' => 'open',
            'reason' => '人工开通',
            'idempotency_key' => '00000000-0000-0000-0000-000000000010',
            'end_at' => '2099-01-01 00:00:00',
        ])->assertOk();

        $this->assertSame('2026-10-15 10:00:00', $member->refresh()->end_at->format('Y-m-d H:i:s'));
    }
}
