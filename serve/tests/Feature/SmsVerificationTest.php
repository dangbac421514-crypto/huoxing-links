<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Enums\CodeMode;
use App\Exceptions\BusinessRuleException;
use App\Services\AliyunSmsGateway;
use App\Services\ImageCaptchaService;
use App\Services\SecretConfigService;
use App\Services\SystemConfig;
use App\Services\VerificationCodeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\Support\DeterministicCaptchaBuilderFactory;
use Tests\Support\FakeSmsGateway;
use Tests\TestCase;

final class SmsVerificationTest extends TestCase
{
    private FakeSmsGateway $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.redis.client' => 'predis']);
        $this->fake = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->fake);
        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();
    }

    public function test_success_is_hashed_in_redis_and_consumed_once(): void
    {
        app(VerificationCodeService::class)->send(
            CodeMode::SMS,
            '13800000001',
            '203.0.113.10',
            'reset_password',
            'RESET_TEMPLATE',
        );

        $code = $this->fake->lastCode('13800000001');
        $identity = hash_hmac('sha256', '13800000001', (string) config('app.key'));
        $payload = Cache::store('redis')->get('verification:SMS:reset_password:'.$identity);

        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('code', $payload);
        $this->assertArrayHasKey('hash', $payload);
        $this->assertTrue(password_verify($code, $payload['hash']));

        app(VerificationCodeService::class)->consume(
            CodeMode::SMS,
            '13800000001',
            '203.0.113.10',
            'reset_password',
            $code,
        );

        try {
            app(VerificationCodeService::class)->consume(
                CodeMode::SMS,
                '13800000001',
                '203.0.113.10',
                'reset_password',
                $code,
            );
            $this->fail('验证码必须一次消费');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_CODE_EXPIRED', $exception->errorCode);
        }
    }

    public function test_code_is_bound_to_the_sending_ip_without_exposing_the_ip(): void
    {
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::SMS, '13800000012', '203.0.113.40', 'register', 'REGISTER_TEMPLATE');
        $code = $this->fake->lastCode('13800000012');
        $identity = hash_hmac('sha256', '13800000012', (string) config('app.key'));
        $payload = Cache::store('redis')->get('verification:SMS:register:'.$identity);

        $this->assertArrayHasKey('ip_hash', $payload);
        $this->assertSame(
            hash_hmac('sha256', '203.0.113.40', (string) config('app.key')),
            $payload['ip_hash'],
        );
        $this->assertStringNotContainsString('203.0.113.40', json_encode($payload));

        try {
            $service->consume(CodeMode::SMS, '13800000012', '203.0.113.41', 'register', $code);
            $this->fail('不同 IP 不得消费验证码');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_CODE_INVALID', $exception->errorCode);
        }

        $payloadAfterMismatch = Cache::store('redis')->get('verification:SMS:register:'.$identity);
        $this->assertSame(0, $payloadAfterMismatch['attempts']);
        $service->consume(CodeMode::SMS, '13800000012', '203.0.113.40', 'register', $code);
    }

    public function test_same_recipient_and_ip_is_rate_limited_without_real_http(): void
    {
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::SMS, '13800000002', '203.0.113.11', 'register', 'REGISTER_TEMPLATE');

        try {
            $service->send(CodeMode::SMS, '13800000002', '203.0.113.11', 'register', 'REGISTER_TEMPLATE');
            $this->fail('同手机号和 IP 的频控必须拒绝第二次发送');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_RATE_LIMITED', $exception->errorCode);
        }

        $this->assertCount(1, $this->fake->messages);
    }

    public function test_provider_failure_releases_the_throttle_reservation(): void
    {
        $this->app->instance(SmsGateway::class, new class implements SmsGateway
        {
            public function send(string $recipient, string $code, string $template): void
            {
                throw new \RuntimeException('provider unavailable');
            }
        });

        $service = app(VerificationCodeService::class);
        try {
            $service->send(CodeMode::SMS, '13800000008', '203.0.113.18', 'register', 'REGISTER_TEMPLATE');
            $this->fail('发送失败必须返回稳定错误');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_SEND_FAILED', $exception->errorCode);
        }

        $this->app->instance(SmsGateway::class, $this->fake);
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::SMS, '13800000008', '203.0.113.18', 'register', 'REGISTER_TEMPLATE');
        $this->assertCount(1, $this->fake->messages);
    }

    public function test_provider_failure_cannot_delete_a_newer_throttle_owner(): void
    {
        $recipient = '13800000016';
        $ip = '203.0.113.45';
        $identity = hash_hmac('sha256', $recipient, (string) config('app.key'));
        $ipHash = hash_hmac('sha256', $ip, (string) config('app.key'));
        $throttleKey = 'verification:rate:SMS:register:'.$identity.':'.$ipHash;

        $this->app->instance(SmsGateway::class, new class($throttleKey) implements SmsGateway
        {
            public function __construct(private string $throttleKey) {}

            public function send(string $recipient, string $code, string $template): void
            {
                Redis::set($this->throttleKey, 'newer-owner', 'EX', 60);
                throw new \RuntimeException('provider unavailable');
            }
        });

        try {
            app(VerificationCodeService::class)->send(CodeMode::SMS, $recipient, $ip, 'register', 'REGISTER_TEMPLATE');
            $this->fail('发送失败必须返回稳定错误');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_SEND_FAILED', $exception->errorCode);
        }

        $this->assertSame('newer-owner', Redis::get($throttleKey));
    }

    public function test_wrong_code_is_limited_to_five_attempts_and_does_not_extend_expiry(): void
    {
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::SMS, '13800000009', '203.0.113.19', 'register', 'REGISTER_TEMPLATE');
        $identity = hash_hmac('sha256', '13800000009', (string) config('app.key'));
        $key = 'verification:SMS:register:'.$identity;
        $before = Cache::store('redis')->get($key);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $service->consume(CodeMode::SMS, '13800000009', '203.0.113.19', 'register', '000000');
                $this->fail('错误验证码必须拒绝');
            } catch (BusinessRuleException $exception) {
                $this->assertSame('SMS_CODE_INVALID', $exception->errorCode);
            }
        }

        $after = Cache::store('redis')->get($key);
        $this->assertSame(4, $after['attempts']);
        $this->assertSame($before['expires_at'], $after['expires_at']);

        try {
            $service->consume(CodeMode::SMS, '13800000009', '203.0.113.19', 'register', '000000');
            $this->fail('第五次错误后验证码必须失效');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_CODE_EXPIRED', $exception->errorCode);
        }

        $this->assertNull(Cache::store('redis')->get($key));
    }

    public function test_code_ttl_is_five_minutes_and_wrong_attempt_does_not_extend_redis_ttl(): void
    {
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::SMS, '13800000013', '203.0.113.42', 'register', 'REGISTER_TEMPLATE');
        $identity = hash_hmac('sha256', '13800000013', (string) config('app.key'));
        $key = 'verification:SMS:register:'.$identity;
        $store = Cache::store('redis')->getStore();
        $redisKey = $store->getPrefix().$key;
        $before = Redis::connection('cache')->ttl($redisKey);

        $this->assertGreaterThanOrEqual(295, $before);
        $this->assertLessThanOrEqual(300, $before);
        try {
            $service->consume(CodeMode::SMS, '13800000013', '203.0.113.42', 'register', '000000');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_CODE_INVALID', $exception->errorCode);
        }

        $after = Redis::connection('cache')->ttl($redisKey);
        $this->assertLessThanOrEqual($before, $after);
        $this->assertGreaterThan(0, $after);
    }

    public function test_register_and_reset_password_purposes_cannot_share_a_code(): void
    {
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::SMS, '13800000014', '203.0.113.43', 'register', 'REGISTER_TEMPLATE');
        $code = $this->fake->lastCode('13800000014');

        try {
            $service->consume(CodeMode::SMS, '13800000014', '203.0.113.43', 'reset_password', $code);
            $this->fail('注册验证码不得用于重置密码');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_CODE_EXPIRED', $exception->errorCode);
        }

        $service->consume(CodeMode::SMS, '13800000014', '203.0.113.43', 'register', $code);
    }

    public function test_lock_contention_maps_to_a_stable_busy_error(): void
    {
        $service = app(VerificationCodeService::class);
        $service->send(CodeMode::SMS, '13800000015', '203.0.113.44', 'register', 'REGISTER_TEMPLATE');
        $identity = hash_hmac('sha256', '13800000015', (string) config('app.key'));
        $lock = Cache::store('redis')->lock('verification:lock:verification:SMS:register:'.$identity, 5);
        $this->assertTrue($lock->get());

        try {
            $service->consume(CodeMode::SMS, '13800000015', '203.0.113.44', 'register', '000000');
            $this->fail('锁被占用时必须返回稳定忙错误');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_CODE_BUSY', $exception->errorCode);
        } finally {
            $lock->release();
        }
    }

    public function test_image_captcha_switch_controls_required_fields_and_consumes_once(): void
    {
        SystemConfig::set([
            'send_code_mode' => (string) CodeMode::SMS->value,
            'verify_code_is_open' => '0',
        ]);
        $this->postJson('/api/captcha/sms', [
            'tel' => '13800000004',
            'purpose' => 'register',
        ])->assertOk();

        SystemConfig::set(['verify_code_is_open' => '1']);
        $this->postJson('/api/captcha/sms', [
            'tel' => '13800000005',
            'purpose' => 'register',
        ])->assertStatus(422);

        $this->app->instance(
            ImageCaptchaService::class,
            new ImageCaptchaService(new DeterministicCaptchaBuilderFactory('123456')),
        );
        $image = app(ImageCaptchaService::class)->issue();
        $payload = [
            'tel' => '13800000007',
            'purpose' => 'register',
            'key' => $image['key'],
            'captcha' => '123456',
        ];

        $this->postJson('/api/captcha/sms', $payload)->assertOk();
        $this->postJson('/api/captcha/sms', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'IMAGE_CAPTCHA_INVALID');
    }

    public function test_missing_provider_configuration_returns_stable_error_without_network(): void
    {
        SystemConfig::set([
            'ali_sms_key' => '',
            'ali_sms_secret' => '',
            'ali_sms_sign_name' => '',
        ]);

        try {
            app(AliyunSmsGateway::class)->send('13800000006', '123456', 'REGISTER_TEMPLATE');
            $this->fail('缺少短信配置必须阻断真实发送');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SMS_NOT_CONFIGURED', $exception->errorCode);
        }
    }

    public function test_aliyun_adapter_uses_the_injected_client_without_network(): void
    {
        SystemConfig::set(['ali_sms_sign_name' => 'Test Sign']);
        app(SecretConfigService::class)->set('ali_sms_key', 'access-key');
        app(SecretConfigService::class)->set('ali_sms_secret', 'access-secret');
        $sent = [];
        $client = new class($sent)
        {
            public function __construct(private array &$sent) {}

            public function send(string $recipient, array $message, array $gateways): array
            {
                $this->sent[] = compact('recipient', 'message', 'gateways');

                return ['aliyun' => ['status' => 'success']];
            }
        };
        $gateway = new AliyunSmsGateway(
            app(SecretConfigService::class),
            static fn (array $config): object => $client,
        );

        $gateway->send('13800000011', '123456', 'REGISTER_TEMPLATE');

        $this->assertSame('13800000011', $sent[0]['recipient']);
        $this->assertSame(['code' => '123456'], $sent[0]['message']['data']);
        $this->assertSame(['aliyun'], $sent[0]['gateways']);
    }

    public function test_invalid_send_mode_returns_stable_configuration_error(): void
    {
        SystemConfig::set(['send_code_mode' => '99']);

        $this->postJson('/api/captcha/sms', [
            'tel' => '13800000010',
            'purpose' => 'register',
        ])->assertStatus(500)->assertJsonPath('code', 'CODE_MODE_INVALID');
    }

    public function test_invalid_send_mode_is_not_silently_treated_as_sms_on_login(): void
    {
        SystemConfig::set([
            'send_code_mode' => '99',
            'verify_code_is_open' => '1',
        ]);

        $this->postJson('/api/login', [
            'username' => '13800000010',
            'password' => 'password',
        ])->assertStatus(500)->assertJsonPath('code', 'CODE_MODE_INVALID');
    }
}
