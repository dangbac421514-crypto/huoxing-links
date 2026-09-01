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
