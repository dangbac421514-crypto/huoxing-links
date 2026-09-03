<?php

namespace Tests\Feature;

use App\Contracts\EmailGateway;
use App\Enums\CodeMode;
use App\Exceptions\BusinessRuleException;
use App\Jobs\SendEmailJobs;
use App\Mail\SendEmail;
use App\Services\LaravelMailGateway;
use App\Services\SecretConfigService;
use App\Services\SystemConfig;
use App\Services\VerificationCodeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeEmailGateway;
use Tests\TestCase;

final class EmailVerificationTest extends TestCase
{
    private FakeEmailGateway $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.redis.client' => 'predis']);
        $this->fake = new FakeEmailGateway;
        $this->app->instance(EmailGateway::class, $this->fake);
        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();
    }

    public function test_email_mode_uses_fake_gateway_and_consumes_once(): void
    {
        Mail::fake();
        SystemConfig::set([
            'send_code_mode' => (string) CodeMode::Email->value,
            'mail_host' => 'smtp.test',
            'mail_port' => '587',
            'mail_username' => 'test@example.com',
            'mail_from_address' => 'test@example.com',
            'mail_encryption' => 'tls',
        ]);
        app(SecretConfigService::class)->set('mail_password', 'password');

        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::Email, 'owner@example.com', '203.0.113.30', 'reset_password', 'RESET_EMAIL');
        $code = $this->fake->lastCode('owner@example.com');

        try {
            $service->send(CodeMode::Email, 'owner@example.com', '203.0.113.30', 'reset_password', 'RESET_EMAIL');
            $this->fail('同邮箱和 IP 必须频控');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('EMAIL_RATE_LIMITED', $exception->errorCode);
        }

        $service->consume(CodeMode::Email, 'owner@example.com', '203.0.113.30', 'reset_password', $code);
        try {
            $service->consume(CodeMode::Email, 'owner@example.com', '203.0.113.30', 'reset_password', $code);
            $this->fail('邮箱验证码必须一次消费');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('EMAIL_CODE_EXPIRED', $exception->errorCode);
        }

        Mail::assertNothingSent();
    }

    public function test_email_code_is_bound_to_the_sending_ip_without_exposing_the_ip(): void
    {
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::Email, 'ip@example.com', '203.0.113.50', 'register', 'REGISTER_EMAIL');
        $code = $this->fake->lastCode('ip@example.com');
        $identity = hash_hmac('sha256', 'ip@example.com', (string) config('app.key'));
        $payload = Cache::store('redis')->get('verification:EMAIL:register:'.$identity);

        $this->assertArrayHasKey('ip_hash', $payload);
        $this->assertSame(
            hash_hmac('sha256', '203.0.113.50', (string) config('app.key')),
            $payload['ip_hash'],
        );
        $this->assertStringNotContainsString('203.0.113.50', json_encode($payload));

        try {
            $service->consume(CodeMode::Email, 'ip@example.com', '203.0.113.51', 'register', $code);
            $this->fail('不同 IP 不得消费邮箱验证码');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('EMAIL_CODE_INVALID', $exception->errorCode);
        }

        $payloadAfterMismatch = Cache::store('redis')->get('verification:EMAIL:register:'.$identity);
        $this->assertSame(0, $payloadAfterMismatch['attempts']);
        $service->consume(CodeMode::Email, 'ip@example.com', '203.0.113.50', 'register', $code);
    }

    public function test_register_and_reset_password_purposes_cannot_share_an_email_code(): void
    {
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::Email, 'purpose@example.com', '203.0.113.52', 'register', 'REGISTER_EMAIL');
        $code = $this->fake->lastCode('purpose@example.com');

        try {
            $service->consume(CodeMode::Email, 'purpose@example.com', '203.0.113.52', 'reset_password', $code);
            $this->fail('注册邮箱验证码不得用于重置密码');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('EMAIL_CODE_EXPIRED', $exception->errorCode);
        }

        $service->consume(CodeMode::Email, 'purpose@example.com', '203.0.113.52', 'register', $code);
    }

    public function test_email_lock_contention_maps_to_a_stable_busy_error(): void
    {
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::Email, 'busy@example.com', '203.0.113.53', 'register', 'REGISTER_EMAIL');
        $identity = hash_hmac('sha256', 'busy@example.com', (string) config('app.key'));
        $lock = Cache::store('redis')->lock('verification:lock:verification:EMAIL:register:'.$identity, 5);
        $this->assertTrue($lock->get());

        try {
            $service->consume(CodeMode::Email, 'busy@example.com', '203.0.113.53', 'register', '000000');
            $this->fail('锁被占用时必须返回稳定忙错误');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('EMAIL_CODE_BUSY', $exception->errorCode);
        } finally {
            $lock->release();
        }
    }

    public function test_missing_smtp_purges_a_stale_resolved_mailer_and_password(): void
    {
        config([
            'mail.mailers.runtime_smtp' => [
                'transport' => 'smtp',
                'scheme' => 'smtp',
                'host' => 'stale.test',
                'port' => 587,
                'username' => 'stale@example.com',
                'password' => 'stale-password',
            ],
        ]);
        $manager = app('mail.manager');
        $manager->mailer('runtime_smtp');
        Mail::fake();
        SystemConfig::set(['mail_host' => '', 'mail_from_address' => '']);

        try {
            app(LaravelMailGateway::class)->send('owner@example.com', '123456', 'RESET_EMAIL');
            $this->fail('缺少 SMTP 必须返回稳定错误');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('EMAIL_NOT_CONFIGURED', $exception->errorCode);
        }

        $property = new \ReflectionProperty($manager, 'mailers');
        $property->setAccessible(true);
        $this->assertArrayNotHasKey('runtime_smtp', $property->getValue($manager));
        $this->assertNull(config('mail.mailers.runtime_smtp.password'));
    }

    public function test_missing_smtp_returns_email_not_configured(): void
    {
        config(['mail.mailers.runtime_smtp.password' => 'stale-password']);
        SystemConfig::set(['mail_host' => '', 'mail_from_address' => '']);

        try {
            app(LaravelMailGateway::class)->send('owner@example.com', '123456', 'RESET_EMAIL');
            $this->fail('缺少 SMTP 必须返回稳定错误');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('EMAIL_NOT_CONFIGURED', $exception->errorCode);
        }
        $this->assertNull(config('mail.mailers.runtime_smtp.password'));
    }

    public function test_email_gateway_failure_maps_to_stable_error(): void
    {
        $this->app->instance(EmailGateway::class, new class implements EmailGateway
        {
            public function send(string $recipient, string $code, string $template): void
            {
                throw new \RuntimeException('smtp unavailable');
            }
        });
        SystemConfig::set([
            'mail_host' => 'smtp.test',
            'mail_port' => '587',
            'mail_username' => 'test@example.com',
            'mail_from_address' => 'test@example.com',
            'mail_encryption' => 'tls',
        ]);

        try {
            app(VerificationCodeService::class)->send(
                CodeMode::Email,
                'owner@example.com',
                '203.0.113.31',
                'register',
                'REGISTER_EMAIL',
            );
            $this->fail('SMTP 发送失败必须映射稳定错误');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('EMAIL_SEND_FAILED', $exception->errorCode);
        }
    }

    public function test_runtime_smtp_uses_named_mailer_and_clears_password_after_send(): void
    {
        Mail::fake();
        SystemConfig::set([
            'mail_host' => 'smtp.test',
            'mail_port' => '465',
            'mail_username' => 'test@example.com',
            'mail_from_address' => 'sender@example.com',
            'mail_from_name' => 'Test Sender',
            'mail_encryption' => 'ssl',
        ]);
        app(SecretConfigService::class)->set('mail_password', 'plain-secret');

        app(LaravelMailGateway::class)->send('owner@example.com', '123456', 'RESET_EMAIL');

        Mail::assertSent(SendEmail::class, function (SendEmail $mail): bool {
            return $mail->usesMailer('runtime_smtp') && $mail->hasTo('owner@example.com');
        });
        $this->assertNull(config('mail.mailers.runtime_smtp.password'));
        $this->assertSame('sender@example.com', config('mail.from.address'));
    }

    public function test_email_job_reports_failure_without_sending_again_or_serializing_code(): void
    {
        $job = new SendEmailJobs('owner@example.com', '123456', 'RESET_EMAIL');
        $job->handle($this->fake);
        $job->failed(new \RuntimeException('smtp unavailable'));

        $this->assertCount(1, $this->fake->messages);
        $this->assertStringNotContainsString('123456', serialize($job));
    }
}
