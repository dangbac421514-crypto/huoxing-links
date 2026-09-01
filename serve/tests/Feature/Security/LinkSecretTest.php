<?php

namespace Tests\Feature\Security;

use App\Contracts\MiniProgramSchemeGenerator;
use App\Models\LinkVisitLog;
use App\Models\MiniProgram;
use App\Services\PublicTargetCache;
use App\Services\SanitizedLinkVisitRecorder;
use App\Services\VisitorIdentityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class LinkSecretTest extends TestCase
{
    use CreatesLinkFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Redis::connection('cache')->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection('cache')->flushdb();
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_public_resolution_logs_only_hashes_and_secret_free_target_cache(): void
    {
        $link = $this->miniProgramLink();
        $visitorId = (string) Str::uuid();
        app()->instance(MiniProgramSchemeGenerator::class, new class implements MiniProgramSchemeGenerator
        {
            public function generate(MiniProgram $mini, string $path, string $query): string
            {
                return 'weixin://dl/business/?t=secret-safe';
            }
        });

        $response = $this->withUnencryptedCookies(['visitor_id' => $visitorId])
            ->withCredentials()
            ->withHeaders([
                'User-Agent' => 'secret-test-agent',
                'X-Forwarded-For' => '203.0.113.10',
            ])
            ->getJson('/api/link-target/'.$link->code.'?device_uid=raw-client-device');

        $response->assertOk();
        $this->assertStringNotContainsString('task-2-secret', $response->getContent());
        $this->assertStringNotContainsString($visitorId, $response->getContent());

        $log = LinkVisitLog::query()->where('link_id', $link->id)->latest('id')->firstOrFail();
        $rawLog = DB::table('link_visit_logs')->where('id', $log->id)->first();
        $this->assertSame(hash_hmac('sha256', $visitorId, (string) config('app.visitor_hash_key')), $rawLog->visitor_hash);
        $this->assertNotNull($rawLog->ip_hash);
        $this->assertNotNull($rawLog->user_agent_hash);
        $this->assertNull($rawLog->ip);
        $this->assertNull($rawLog->device_uid);
        $cache = json_decode((string) $rawLog->cache, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['description', 'icon', 'target', 'title'],
            tap(array_keys($cache), static fn (&$keys) => sort($keys)),
        );
        $this->assertStringNotContainsString('task-2-secret', (string) $rawLog->cache);
        $this->assertStringNotContainsString('raw-client-device', (string) $rawLog->cache);

        $keys = Redis::connection('cache')->keys('*link-target*');
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertStringNotContainsString('task-2-secret', $key);
            $this->assertStringNotContainsString('raw-client-device', $key);
            $value = Redis::connection('cache')->get($key);
            $this->assertStringNotContainsString('task-2-secret', (string) $value);
            $this->assertStringNotContainsString($visitorId, (string) $value);
        }
    }

    public function test_resolved_visit_sanitizer_drops_legacy_params_identity_and_nested_data(): void
    {
        $sanitized = app(SanitizedLinkVisitRecorder::class)->publicTarget([
            'title' => 'safe',
            'description' => null,
            'icon' => '/icon.png',
            'target' => 'weixin://dl/business/?t=safe',
            'params' => ['secret' => 'must-drop'],
            'visitorToken' => 'must-drop',
            'qr' => ['path' => 'must-drop'],
            'nested' => ['target' => 'must-drop'],
        ]);

        $this->assertSame([
            'title' => 'safe',
            'description' => null,
            'icon' => '/icon.png',
            'target' => 'weixin://dl/business/?t=safe',
        ], $sanitized);
        $this->assertStringNotContainsString('must-drop', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }

    public function test_record_clears_non_array_cache_including_json_strings(): void
    {
        $log = app(SanitizedLinkVisitRecorder::class)->record([
            'link_id' => 1,
            'user_id' => 1,
            'cache' => '{"target":"https://must-not-bypass.test","secret":"must-drop"}',
        ]);

        $this->assertSame([], json_decode((string) DB::table('link_visit_logs')->where('id', $log->id)->value('cache'), true));
    }

    public function test_network_log_hashes_are_purpose_separated_and_missing_values_are_null(): void
    {
        $service = app(VisitorIdentityService::class);
        $ip = '203.0.113.10';
        $agent = 'secret-test-agent';

        $this->assertNull($service->hashForLog('ip', null));
        $this->assertNull($service->hashForLog('user_agent', ''));
        $this->assertSame(
            hash_hmac('sha256', 'ip:'.$ip, (string) config('app.visitor_hash_key')),
            $service->hashForLog('ip', $ip),
        );
        $this->assertNotSame(
            $service->hashForLog('ip', $ip),
            $service->hashForLog('user_agent', $agent),
        );
    }

    public function test_public_target_cache_is_strictly_sanitized_and_expires_after_five_minutes(): void
    {
        $link = $this->miniProgramLink();
        $cache = app(PublicTargetCache::class);
        $cache->put($link, [
            'title' => 'safe',
            'description' => 'description',
            'icon' => null,
            'target' => 'weixin://dl/business/?t=cache',
            'visitorToken' => 'must-drop',
            'qr' => ['path' => 'must-drop'],
            'params' => ['secret' => 'must-drop'],
        ]);

        $this->assertSame([
            'title' => 'safe',
            'description' => 'description',
            'icon' => null,
            'target' => 'weixin://dl/business/?t=cache',
        ], $cache->get($link));
        $key = Cache::store('redis')->getStore()->getPrefix().$cache->key($link);
        $ttl = Redis::connection('cache')->ttl($key);
        $this->assertGreaterThanOrEqual(299, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }
}
