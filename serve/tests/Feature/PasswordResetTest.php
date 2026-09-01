<?php

namespace Tests\Feature;

use App\Contracts\EmailGateway;
use App\Contracts\SmsGateway;
use App\Enums\CodeMode;
use App\Models\User;
use App\Services\SystemConfig;
use App\Services\VerificationCodeService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeEmailGateway;
use Tests\Support\FakeSmsGateway;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.redis.client' => 'predis']);
    }

    public function test_reset_consumes_sms_code_once_and_never_sends_real_mail(): void
    {
        Mail::fake();
        SystemConfig::set([
            'send_code_mode' => (string) CodeMode::SMS->value,
            'verify_code_is_open' => '0',
        ]);
        $user = User::factory()->create([
            'username' => '13800000003',
            'password' => Hash::make('old-password'),
            'status' => true,
        ]);
        $fake = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $fake);
        app(VerificationCodeService::class)->send(
            CodeMode::SMS,
            $user->username,
            '203.0.113.12',
            'reset_password',
            'RESET_TEMPLATE',
        );
        $code = $fake->lastCode($user->username);

        $this->postJson('/api/reset-password', [
            'username' => $user->username,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
            'code' => $code,
        ])->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
        Mail::assertNothingSent();

        $this->postJson('/api/reset-password', [
            'username' => $user->username,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
            'code' => $code,
        ])->assertStatus(422)->assertJsonPath('code', 'SMS_CODE_EXPIRED');
    }

    public function test_reset_uses_email_recipient_when_email_mode_is_enabled(): void
    {
        SystemConfig::set([
            'send_code_mode' => (string) CodeMode::Email->value,
            'verify_code_is_open' => '0',
        ]);
        $user = User::factory()->create([
            'username' => 'owner@example.com',
            'password' => Hash::make('old-password'),
            'status' => true,
        ]);
        $fake = new FakeEmailGateway;
        $this->app->instance(EmailGateway::class, $fake);
        app(VerificationCodeService::class)->send(
            CodeMode::Email,
            $user->username,
            '203.0.113.13',
            'reset_password',
            'RESET_EMAIL',
        );
        $code = $fake->lastCode($user->username);

        $this->postJson('/api/reset-password', [
            'username' => $user->username,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
            'code' => $code,
        ])->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }
}
