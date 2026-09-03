<?php

namespace Tests\Unit\Services;

use App\DTO\VisitorTokenPayload;
use App\Exceptions\InvalidVisitorToken;
use App\Exceptions\LinkResolutionException;
use App\Services\VisitorTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VisitorTokenServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.visitor_token_key' => base64_encode(str_repeat('t', 32))]);
    }

    public function test_issue_encrypts_a_fixed_payload_and_verify_returns_it(): void
    {
        $expiresAt = CarbonImmutable::parse('2026-09-02 12:10:00', 'Asia/Shanghai');
        $service = app(VisitorTokenService::class);
        $token = $service->issue('abc123', 'visitor-1', $expiresAt);

        $this->assertNotSame('', $token);
        $this->assertStringNotContainsString('visitor-1', $token);
        $decodedEnvelope = base64_decode($token, true);
        $this->assertIsString($decodedEnvelope);
        $this->assertStringNotContainsString('visitor-1', $decodedEnvelope);
        $this->assertStringNotContainsString('abc123', $decodedEnvelope);

        $payload = $service->verify($token, 'abc123', $expiresAt->subMinute());
        $this->assertInstanceOf(VisitorTokenPayload::class, $payload);
        $this->assertSame('visitor-1', $payload->visitorId);
        $this->assertSame('abc123', $payload->code);
        $this->assertSame($expiresAt->timestamp, $payload->expiresAt);
        $this->assertTrue(Str::isUuid($payload->jti));
    }

    public function test_token_key_is_independent_from_the_application_key(): void
    {
        $service = app(VisitorTokenService::class);
        $token = $service->issue('abc123', 'visitor-1', CarbonImmutable::now()->addMinute());
        $applicationKey = (string) config('app.key');
        $this->assertStringStartsWith('base64:', $applicationKey);
        $applicationKey = base64_decode(substr($applicationKey, 7), true);
        $this->assertIsString($applicationKey);
        $this->assertSame(32, strlen($applicationKey));

        $this->expectException(\Throwable::class);
        (new Encrypter($applicationKey, 'aes-256-gcm'))->decryptString($token);
    }

    public function test_non_canonical_base64_tokens_are_rejected_before_decryption(): void
    {
        $service = app(VisitorTokenService::class);
        $expiresAt = CarbonImmutable::now()->addMinutes(10);
        $token = $service->issue('abc123', 'visitor-1', $expiresAt);
        $this->assertSame('visitor-1', $service->verify($token, 'abc123', CarbonImmutable::now())->visitorId);

        $variants = [
            '!'.$token,
            $token.'!',
            ' '.$token,
            $token.' ',
            "\n{$token}",
            "{$token}\n",
            $token.'=',
            $token.'==',
            $token.'@',
        ];
        foreach ($variants as $variant) {
            try {
                $service->verify($variant, 'abc123', CarbonImmutable::now());
                $this->fail('non-canonical token was accepted');
            } catch (InvalidVisitorToken $exception) {
                $this->assertSame('VISITOR_TOKEN_INVALID', $exception->errorCode);
                $this->assertSame('Invalid visitor token', $exception->getMessage());
            }
        }
    }

    public function test_wrong_code_tamper_and_exact_expiry_are_invalid(): void
    {
        $service = app(VisitorTokenService::class);
        $expiresAt = CarbonImmutable::parse('2026-09-02 12:10:00', 'Asia/Shanghai');
        $token = $service->issue('abc123', 'visitor-1', $expiresAt);

        try {
            $service->verify($token, 'wrong-code', $expiresAt->subMinute());
            $this->fail('wrong code was accepted');
        } catch (InvalidVisitorToken $exception) {
            $this->assertSame('VISITOR_TOKEN_INVALID', $exception->errorCode);
            $this->assertSame('Invalid visitor token', $exception->getMessage());
        }

        try {
            $service->verify(substr($token, 0, -2).'xx', 'abc123', $expiresAt->subMinute());
            $this->fail('tampered token was accepted');
        } catch (InvalidVisitorToken $exception) {
            $this->assertSame('VISITOR_TOKEN_INVALID', $exception->errorCode);
        }

        $this->expectException(InvalidVisitorToken::class);
        $service->verify($token, 'abc123', $expiresAt);
    }

    public function test_malformed_payload_shape_and_jti_are_invalid(): void
    {
        $key = base64_decode((string) config('app.visitor_token_key'), true);
        $this->assertIsString($key);
        $encrypter = new Encrypter($key, 'aes-256-gcm');
        $at = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai');

        foreach ([
            ['code' => 'abc123', 'visitor_id' => 'visitor-1', 'exp' => $at->addMinute()->timestamp],
            ['code' => 'abc123', 'visitor_id' => '', 'exp' => $at->addMinute()->timestamp, 'jti' => (string) Str::uuid()],
            ['code' => 'abc123', 'visitor_id' => 'visitor-1', 'exp' => (string) $at->addMinute()->timestamp, 'jti' => (string) Str::uuid()],
            ['code' => 'abc123', 'visitor_id' => 'visitor-1', 'exp' => $at->addMinute()->timestamp, 'jti' => 'not-a-uuid'],
            ['code' => 'abc123', 'visitor_id' => 'visitor-1', 'exp' => $at->addMinute()->timestamp, 'jti' => (string) Str::uuid(), 'extra' => true],
        ] as $malformed) {
            try {
                app(VisitorTokenService::class)->verify(
                    $encrypter->encryptString(json_encode($malformed, JSON_THROW_ON_ERROR)),
                    'abc123',
                    $at,
                );
                $this->fail('malformed payload was accepted');
            } catch (InvalidVisitorToken $exception) {
                $this->assertSame('VISITOR_TOKEN_INVALID', $exception->errorCode);
            }
        }
    }

    public function test_missing_or_weak_token_key_is_a_configuration_error(): void
    {
        foreach (['', base64_encode(str_repeat('x', 31)), base64_encode(str_repeat('x', 33)), '%%%'] as $key) {
            config(['app.visitor_token_key' => $key]);
            try {
                app(VisitorTokenService::class)->issue('abc123', 'visitor-1', CarbonImmutable::now()->addMinute());
                $this->fail('invalid token key was accepted');
            } catch (LinkResolutionException $exception) {
                $this->assertSame('VISITOR_TOKEN_KEY_INVALID', $exception->errorCode);
                if ($key !== '') {
                    $this->assertStringNotContainsString($key, $exception->getMessage());
                }
            }
        }
    }
}
