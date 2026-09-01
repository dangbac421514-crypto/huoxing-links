<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ImageCaptchaEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.redis.client' => 'predis']);
    }

    public function test_image_endpoint_preserves_key_img_contract(): void
    {
        $json = $this->getJson('/api/captcha/image')->assertOk()->json();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $json['key']);
        $this->assertStringStartsWith('data:image/png;base64,', $json['img']);
        $this->assertArrayNotHasKey('answer', $json);
    }
}
