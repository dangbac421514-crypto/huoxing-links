<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Models\VipPackage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UserTest extends TestCase
{
    public function test_register_binds_its_referrer_without_commission_side_effects(): void
    {
        $agent = User::factory()->create(['type' => UserType::AGENT]);
        $username = '138'.fake()->numerify('########');

        $response = $this
            ->postJson('/api/register', [
                'username' => $username,
                'password' => '123456',
                'password_confirmation' => '123456',
                'referral_code' => $agent->referral_code,
            ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'username' => $username,
            'parent_id' => $agent->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => User::query()->where('username', $username)->value('id'),
            'commission' => 0,
            'accumulate_commission' => 0,
        ]);
    }

    public function test_user_package_update_uses_the_membership_service(): void
    {
        $admin = User::factory()->create([
            'username' => 'test-admin',
            'type' => UserType::Admin,
            'must_change_password' => false,
            'password' => Hash::make('password'),
        ]);
        $user = User::factory()->create();
        $package = VipPackage::query()->create([
            'name' => 'test-package',
            'price' => 0,
            'level' => 1,
            'config' => [],
        ]);
        $token = $admin->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/users/'.$user->id, [
                'vip_id' => $package->id,
                'action' => 'open',
                'reason' => '测试开通',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'vip_id' => $package->id,
        ]);
    }
}
