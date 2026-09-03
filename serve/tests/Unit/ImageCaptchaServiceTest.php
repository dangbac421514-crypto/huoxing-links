<?php

namespace Tests\Unit;

use App\Services\ImageCaptchaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\DeterministicCaptchaBuilderFactory;
use Tests\TestCase;

final class ImageCaptchaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.redis.client' => 'predis']);
    }

    public function test_issue_returns_key_and_png_and_answer_is_hashed_with_one_time_ttl(): void
    {
        $service = new ImageCaptchaService(new DeterministicCaptchaBuilderFactory('123456'));

        $issued = $service->issue();
        $payload = Cache::store('redis')->get('image-captcha:'.$issued['key']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $issued['key']);
        $this->assertStringStartsWith('data:image/png;base64,', $issued['img']);
        $this->assertIsArray($payload);
        $this->assertIsString($payload['hash']);
        $this->assertArrayNotHasKey('answer', $payload);
        $this->assertGreaterThanOrEqual(295, $payload['expires_at'] - now()->timestamp);
        $this->assertLessThanOrEqual(300, $payload['expires_at'] - now()->timestamp);
        $this->assertTrue(Hash::check('123456', $payload['hash']));
        $this->assertTrue($service->verify($issued['key'], '123456'));
        $this->assertFalse($service->verify($issued['key'], '123456'));
    }

    public function test_failed_verification_consumes_the_challenge(): void
    {
        $service = new ImageCaptchaService(new DeterministicCaptchaBuilderFactory('123456'));
        $issued = $service->issue();

        $this->assertFalse($service->verify($issued['key'], 'wrong'));
        $this->assertFalse($service->verify($issued['key'], '123456'));
    }
}
