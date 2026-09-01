<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Models\VipLogs;
use App\Models\VipPackage;
use App\Services\AdminProvisioner;
use App\Services\RegistrationService;
use App\Services\SystemConfig;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AuthRegistrationTest extends TestCase
{
    public function test_registration_binds_a_valid_referral_code_without_creating_commission_rows(): void
    {
        $parent = User::factory()->create(['type' => UserType::MEMBER, 'status' => true]);

        $response = $this->postJson('/api/register', [
            'username' => '13800000001',
            'password' => 'password',
            'password_confirmation' => 'password',
            'referral_code' => $parent->referral_code,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'username' => '13800000001',
            'parent_id' => $parent->id,
        ]);
        $this->assertSame(0, VipLogs::query()->count());
    }

    public function test_unknown_referral_code_is_rejected_with_validation_error(): void
    {
        $this->postJson('/api/register', [
            'username' => '13800000002',
            'password' => 'password',
            'password_confirmation' => 'password',
            'referral_code' => 'MISSING1',
        ])->assertStatus(422);
    }

    public function test_disabled_referrer_is_rejected_with_validation_error(): void
    {
        $parent = User::factory()->create(['status' => false]);

        $this->postJson('/api/register', [
            'username' => '13800000003',
            'password' => 'password',
            'password_confirmation' => 'password',
            'referral_code' => $parent->referral_code,
        ])->assertStatus(422);
    }

    public function test_self_referral_is_rejected_with_validation_error(): void
    {
        $parent = User::factory()->create(['username' => '13800000004']);

        $this->postJson('/api/register', [
            'username' => $parent->username,
            'password' => 'password',
            'password_confirmation' => 'password',
            'referral_code' => $parent->referral_code,
        ])->assertStatus(422);
    }

    public function test_duplicate_username_is_rejected_without_creating_a_second_user(): void
    {
        User::factory()->create(['username' => '13800000005']);

        $this->postJson('/api/register', [
            'username' => '13800000005',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422);

        $this->assertSame(1, User::query()->where('username', '13800000005')->count());
    }

    public function test_registration_grants_configured_trial_once(): void
    {
        SystemConfig::set([
            'is_give_vip' => '1',
            'give_vip_id' => '1',
            'give_vip_days' => '3',
        ]);
        $package = VipPackage::query()->create([
            'name' => 'trial', 'price' => 0, 'level' => 1, 'config' => [],
        ]);
        SystemConfig::set('give_vip_id', (string) $package->id);

        $this->postJson('/api/register', [
            'username' => '13800000006',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertOk();

        $user = User::query()->where('username', '13800000006')->firstOrFail();
        $this->assertSame(1, VipLogs::query()->where('user_id', $user->id)->where('action', 'trial')->count());
    }

    public function test_login_issues_a_real_sanctum_token_and_disabled_users_are_rejected(): void
    {
        $user = User::factory()->create([
            'username' => '13800000007',
            'password' => Hash::make('password'),
        ]);

        $token = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertOk()->json('token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $user->update(['status' => false]);
        $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_provisioned_admin_is_forced_to_change_password_until_the_gate_route(): void
    {
        $admin = app(AdminProvisioner::class)->provision('owner', 'correct-horse-battery-staple');
        $token = $this->postJson('/api/login', [
            'username' => $admin->username,
            'password' => 'correct-horse-battery-staple',
        ])->assertOk()->json('token');

        $this->authWithToken($token)->getJson('/api/userinfo')
            ->assertStatus(403)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->authWithToken($token)->getJson('/api/userinfo?route=change-password')
            ->assertStatus(403);

        $this->authWithToken($token)->postJson('/api/change-password', [
            'password' => 'new-correct-password',
            'password_confirmation' => 'new-correct-password',
        ])->assertOk();

        $admin->refresh();
        $this->assertFalse((bool) $admin->must_change_password);
        $this->assertTrue(Hash::check('new-correct-password', $admin->password));
        $this->authWithToken($token)->getJson('/api/userinfo')->assertOk();
    }

    public function test_unauthenticated_requests_are_stable_401_json_responses(): void
    {
        $this->getJson('/api/userinfo')->assertStatus(401);
    }

    public function test_admin_user_update_cannot_reparent_or_replace_a_referral_code(): void
    {
        $admin = User::factory()->create([
            'type' => UserType::Admin,
            'must_change_password' => false,
        ]);
        $parent = User::factory()->create();
        $child = User::factory()->create(['parent_id' => $parent->id]);
        $originalCode = $child->referral_code;
        $token = $admin->createToken('test')->plainTextToken;

        $this->authWithToken($token)->putJson('/api/users/'.$child->id, [
            'parent_id' => $admin->id,
            'referral_code' => 'REPLACE1',
        ])->assertStatus(422);

        $this->assertDatabaseHas('users', [
            'id' => $child->id,
            'parent_id' => $parent->id,
            'referral_code' => $originalCode,
        ]);
    }

    public function test_registration_service_maps_a_database_username_race_to_validation(): void
    {
        $existing = User::factory()->create(['username' => '13800000008']);

        $this->expectException(ValidationException::class);

        app(RegistrationService::class)->register(
            $existing->username,
            'password',
            null,
        );
    }

    public function test_user_referral_code_is_generated_uppercase_and_not_replaced_on_update(): void
    {
        $user = User::query()->create([
            'username' => '13800000009',
            'password' => Hash::make('password'),
            'type' => UserType::MEMBER,
            'status' => true,
        ]);
        $originalCode = $user->referral_code;

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $originalCode);
        $user->update(['referral_code' => 'REPLACE2', 'parent_id' => $user->id]);

        $this->assertSame($originalCode, $user->refresh()->referral_code);
        $this->assertNull($user->parent_id);
    }

    private function authWithToken(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
