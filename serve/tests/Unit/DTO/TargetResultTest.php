<?php

namespace Tests\Unit\DTO;

use App\DTO\TargetResult;
use Tests\TestCase;

final class TargetResultTest extends TestCase
{
    public function test_from_builds_an_immutable_public_target_without_secret_fields(): void
    {
        $result = TargetResult::from([
            'title' => 'Example',
            'description' => 'Description',
            'icon' => '/icon.png',
            'target' => 'weixin://dl/example',
            'qr' => ['path' => '/qr.png'],
            'visitorToken' => 'opaque-token',
            'secret' => 'must-not-be-copied',
            'params' => ['appid' => 'must-not-be-copied'],
        ]);

        $this->assertSame('Example', $result->title);
        $this->assertSame('weixin://dl/example', $result->target);
        $this->assertSame(['path' => '/qr.png'], $result->qr);
        $this->assertSame('opaque-token', $result->visitorToken);
        $this->assertObjectNotHasProperty('secret', $result);
        $this->assertObjectNotHasProperty('params', $result);
        $this->assertStringNotContainsString('must-not-be-copied', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_direct_constructor_also_removes_nested_secret_fields(): void
    {
        $result = new TargetResult(
            'Example',
            'Description',
            null,
            'weixin://dl/example',
            ['path' => '/qr.png', 'secret' => 'must-not-be-copied', 'params' => ['token' => 'hidden']],
        );

        $this->assertSame(['path' => '/qr.png'], $result->qr);
        $this->assertStringNotContainsString('must-not-be-copied', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('hidden', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
