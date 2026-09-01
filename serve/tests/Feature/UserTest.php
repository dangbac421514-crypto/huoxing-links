<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Enums\CodeMode;
use App\Enums\UserType;
use App\Models\User;
use App\Models\VipPackage;
use App\Services\SystemConfig;
use App\Services\VerificationCodeService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\FakeSmsGateway;
use Tests\TestCase;

final class UserTest extends TestCase
{
    private FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.redis.client' => 'predis']);
        SystemConfig::set([
            'send_code_mode' => (string) CodeMode::SMS->value,
            'verify_code_is_open' => '0',
        ]);
        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
    }

    public function test_register_binds_its_referrer_without_commission_side_effects(): void
    {
        $agent = User::factory()->create(['type' => UserType::AGENT]);
        $username = '138'.fake()->numerify('########');
        app(VerificationCodeService::class)->send(
            CodeMode::SMS,
            $username,
            '203.0.113.20',
            'register',
            'REGISTER_TEMPLATE',
        );

        $response = $this
            ->postJson('/api/register', [
                'username' => $username,
                'password' => '123456',
                'password_confirmation' => '123456',
                'code' => $this->sms->lastCode($username),
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
