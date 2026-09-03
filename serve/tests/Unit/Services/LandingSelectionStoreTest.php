<?php

namespace Tests\Unit\Services;

use App\Services\LandingSelectionStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class LandingSelectionStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection('cache')->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection('cache')->flushdb();
        parent::tearDown();
    }

    public function test_put_whitelists_scalar_selection_and_get_returns_it(): void
    {
        $token = 'encrypted-token-with-visitor-id-never-in-key';
        app(LandingSelectionStore::class)->put($token, [
            'id' => 7, 'avatar' => '/avatar.png', 'title' => 'Title', 'sub_title' => null,
            'qr' => '/qr.png', 'path' => 'pages/index/index', 'name' => 'Name', 'sort' => 2,
            'secret' => 'must-drop', 'params' => ['secret' => 'must-drop'],
            'target' => 'https://must-drop.example', 'nested' => ['id' => 8],
        ]);

        $this->assertSame([
            'id' => 7, 'avatar' => '/avatar.png', 'title' => 'Title', 'sub_title' => null,
            'qr' => '/qr.png', 'path' => 'pages/index/index', 'name' => 'Name', 'sort' => 2,
        ], app(LandingSelectionStore::class)->get($token));
    }

    public function test_cache_key_contains_only_token_digest_and_ttl_is_exactly_ten_minutes(): void
    {
        $token = 'ciphertext-visitor-42';
        $digest = hash('sha256', $token);
        app(LandingSelectionStore::class)->put($token, ['id' => 1]);

        $store = Cache::store('redis')->getStore();
        $redisKey = $store->getPrefix().'landing-selection:'.$digest;
        $this->assertGreaterThanOrEqual(599, Redis::connection('cache')->ttl($redisKey));
        $this->assertLessThanOrEqual(600, Redis::connection('cache')->ttl($redisKey));
        $this->assertSame([], Redis::connection('cache')->keys('*visitor-42*'));
        $this->assertSame([], Redis::connection('cache')->keys('*'.$token.'*'));
    }

    public function test_missing_or_expired_selection_returns_null(): void
    {
        $service = app(LandingSelectionStore::class);
        $this->assertNull($service->get('missing-token'));
        $service->put('expiring-token', ['id' => 1]);
        Redis::connection('cache')->del(Cache::store('redis')->getStore()->getPrefix().'landing-selection:'.hash('sha256', 'expiring-token'));
        $this->assertNull($service->get('expiring-token'));
    }
}
