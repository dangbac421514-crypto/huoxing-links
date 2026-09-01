<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Contracts\MiniProgramSchemeGenerator;
use App\Enums\LinkType;
use App\Enums\MiniType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\QrUnavailable;
use App\Models\Link;
use App\Models\LinkVisitLog;
use App\Models\MiniProgram;
use App\Models\UsagePeriod;
use App\Services\LandingSelectionStore;
use App\Services\QrRotationService;
use App\Services\UsageMeter;
use App\Services\VisitorTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class LinkResolutionTest extends TestCase
{
    use CreatesLinkFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_public_target_uses_sanitized_envelope_and_server_cookie(): void
    {
        $link = $this->miniProgramLink();
        $generator = new class implements MiniProgramSchemeGenerator
        {
            public function generate(MiniProgram $mini, string $path, string $query): string
            {
                return 'weixin://dl/business/?t=coordinator-target';
            }
        };
        app()->instance(MiniProgramSchemeGenerator::class, $generator);

        $response = $this->getJson('/api/link-target/'.$link->code.'?device_uid=client-controlled');

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['code', 'data' => ['title', 'description', 'icon', 'target']])
            ->assertCookie('visitor_id')
            ->assertJsonMissingPath('data.visitorToken')
            ->assertJsonMissingPath('data.params');
        $this->assertSame([
            'title', 'description', 'icon', 'target',
        ], array_keys($response->json('data')));
    }

    public function test_policy_refusal_returns_stable_status_without_usage_or_visit_log(): void
    {
        $link = $this->miniProgramLink();
        $link->update(['manual_status' => false]);

        $response = $this->getJson('/api/link-target/'.$link->code);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'LINK_DISABLED')
            ->assertJsonStructure(['code', 'message']);
        $this->assertDatabaseMissing('link_visit_logs', ['link_id' => $link->id]);
        $this->assertDatabaseMissing('usage_periods', ['user_id' => $link->user_id]);
        $this->assertFalse($response->headers->has('set-cookie'));
    }

    public function test_target_cache_hit_skips_second_resolver_but_logs_each_visitor_and_consumes_unique_uv(): void
    {
        $link = $this->miniProgramLink();
        $generator = new class implements MiniProgramSchemeGenerator
        {
            public int $calls = 0;

            public function generate(MiniProgram $mini, string $path, string $query): string
            {
                $this->calls++;

                return 'weixin://dl/business/?t=cache-target';
            }
        };
        app()->instance(MiniProgramSchemeGenerator::class, $generator);
        $firstVisitor = (string) Str::uuid();
        $secondVisitor = (string) Str::uuid();

        $this->withUnencryptedCookies(['visitor_id' => $firstVisitor])
            ->withCredentials()
            ->getJson('/api/link-target/'.$link->code)
            ->assertOk();
        $this->withUnencryptedCookies(['visitor_id' => $secondVisitor])
            ->withCredentials()
            ->getJson('/api/link-target/'.$link->code)
            ->assertOk();
        $this->withUnencryptedCookies(['visitor_id' => $firstVisitor])
            ->withCredentials()
            ->getJson('/api/link-target/'.$link->code)
            ->assertOk();

        $this->assertSame(1, $generator->calls);
        $this->assertSame(3, LinkVisitLog::query()->where('link_id', $link->id)->count());
        $this->assertSame(2, (int) UsagePeriod::query()->where('user_id', $link->user_id)->value('used_uv'));
    }

    public function test_target_cache_uses_update_version_and_never_contains_public_or_secret_values(): void
    {
        $link = $this->miniProgramLink();
        $generator = new class implements MiniProgramSchemeGenerator
        {
            public int $calls = 0;

            public function generate(MiniProgram $mini, string $path, string $query): string
            {
                $this->calls++;

                return 'weixin://dl/business/?t=cache-'.$this->calls;
            }
        };
        app()->instance(MiniProgramSchemeGenerator::class, $generator);
        $visitor = (string) Str::uuid();

        $this->withUnencryptedCookies(['visitor_id' => $visitor])->withCredentials()->getJson('/api/link-target/'.$link->code)->assertOk();
        $firstVersionKeys = Redis::connection('cache')->keys('*link-target*');
        $this->assertNotEmpty($firstVersionKeys);
        $link->forceFill([
            'title' => 'changed-public-title',
            'updated_at' => CarbonImmutable::now('Asia/Shanghai')->addSecond(),
        ])->saveQuietly();

        $this->withUnencryptedCookies(['visitor_id' => $visitor])->withCredentials()->getJson('/api/link-target/'.$link->code)->assertOk();

        $this->assertSame(2, $generator->calls);
        $allKeys = Redis::connection('cache')->keys('*link-target*');
        $this->assertGreaterThanOrEqual(2, count($allKeys));
        foreach ($allKeys as $key) {
            $this->assertStringNotContainsString($link->code, $key);
            $this->assertStringNotContainsString('changed-public-title', $key);
            $this->assertStringNotContainsString('task-2-secret', $key);
        }
    }

    public function test_landing_target_and_show_qr_reuse_the_first_selection_without_second_uv_or_visit_log(): void
    {
        $link = $this->landingLinkWithQrs([
            [
                'sort' => 1,
                'path' => 'landing-qr.png',
                'expired_at' => CarbonImmutable::now('Asia/Shanghai')->addDay()->format('Y-m-d'),
                'uv_limit_num' => 5,
            ],
        ]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        $generator = new class implements MiniProgramSchemeGenerator
        {
            public function generate(MiniProgram $mini, string $path, string $query): string
            {
                return 'weixin://dl/business/?t=landing-target';
            }
        };
        app()->instance(MiniProgramSchemeGenerator::class, $generator);

        $target = $this->getJson('/api/link-target/'.$link->code);
        $target->assertOk()->assertJsonPath('code', 0);
        $token = $target->json('data.visitorToken');
        $this->assertIsString($token);
        $beforeUv = (int) UsagePeriod::query()->where('user_id', $link->user_id)->value('used_uv');
        $beforeLogs = LinkVisitLog::query()->where('link_id', $link->id)->count();

        $showQr = $this->getJson('/api/link-show-qr/'.$link->code.'?visitor_token='.urlencode($token));

        $showQr->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonStructure(['code', 'data' => ['id', 'avatar', 'title', 'sub_title', 'qr']]);
        $this->assertSame(['id', 'avatar', 'title', 'sub_title', 'qr'], array_keys($showQr->json('data')));
        $this->assertNotSame('', $showQr->json('data.qr'));
        $this->assertSame($beforeUv, (int) UsagePeriod::query()->where('user_id', $link->user_id)->value('used_uv'));
        $this->assertSame($beforeLogs, LinkVisitLog::query()->where('link_id', $link->id)->count());
        $this->assertSame('1', (string) Redis::connection('default')->hget('link:qr:'.$link->id.':accumulate', '1'));
    }

    public function test_show_qr_rejects_wrong_expired_missing_and_extra_identity_inputs_without_writing_logs(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'landing-qr.png', 'expired_at' => null, 'uv_limit_num' => 5],
        ]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        app()->instance(MiniProgramSchemeGenerator::class, new class implements MiniProgramSchemeGenerator
        {
            public function generate(MiniProgram $mini, string $path, string $query): string
            {
                return 'weixin://dl/business/?t=landing-target';
            }
        });

        $target = $this->getJson('/api/link-target/'.$link->code)->assertOk();
        $token = $target->json('data.visitorToken');
        $logCount = LinkVisitLog::query()->count();
        $periodUsed = (int) UsagePeriod::query()->where('user_id', $link->user_id)->value('used_uv');

        $this->getJson('/api/link-show-qr/'.$link->code.'?visitor_token='.urlencode($token).'&device_uid=legacy')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VISITOR_TOKEN_INVALID');
        $this->getJson('/api/link-show-qr/'.$link->code)
            ->assertStatus(422)
            ->assertJsonPath('code', 'VISITOR_TOKEN_INVALID');

        $expired = app(VisitorTokenService::class)->issue(
            $link->code,
            (string) Str::uuid(),
            CarbonImmutable::now('Asia/Shanghai')->subSecond(),
        );
        app(LandingSelectionStore::class)->put($expired, ['id' => $link->id, 'qr' => 'landing-qr.png']);
        $this->getJson('/api/link-show-qr/'.$link->code.'?visitor_token='.urlencode($expired))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VISITOR_TOKEN_INVALID');

        $other = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'other-qr.png', 'expired_at' => null, 'uv_limit_num' => 5],
        ]);
        $otherMini = MiniProgram::query()->findOrFail(data_get($other->config, 'min_id'));
        $otherMini->update(['is_pre_min' => true]);
        $this->getJson('/api/link-show-qr/'.$other->code.'?visitor_token='.urlencode($token))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VISITOR_TOKEN_INVALID');

        $this->assertSame($logCount, LinkVisitLog::query()->count());
        $this->assertSame($periodUsed, (int) UsagePeriod::query()->where('user_id', $link->user_id)->value('used_uv'));
    }

    public function test_qr_provider_failure_returns_stable_error_without_log_and_keeps_new_cookie(): void
    {
        $link = $this->landingLinkWithQrs([]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        app()->instance(MiniProgramSchemeGenerator::class, new class implements MiniProgramSchemeGenerator
        {
            public function generate(MiniProgram $mini, string $path, string $query): string
            {
                return 'weixin://dl/business/?t=never-called';
            }
        });

        $response = $this->getJson('/api/link-target/'.$link->code);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'QR_UNAVAILABLE')
            ->assertJsonStructure(['code', 'message'])
            ->assertCookie('visitor_id');
        $this->assertDatabaseMissing('link_visit_logs', ['link_id' => $link->id]);
        $this->assertSame(1, (int) UsagePeriod::query()->where('user_id', $link->user_id)->value('used_uv'));
    }

    public function test_quota_refusal_maps_to_429_and_existing_visitor_remains_allowed(): void
    {
        $link = $this->miniProgramLink();
        $package = $link->user->vipPackage;
        $config = $package->config;
        $config['uv_limit'] = 1;
        $package->update(['config' => $config]);
        app()->instance(MiniProgramSchemeGenerator::class, new class implements MiniProgramSchemeGenerator
        {
            public function generate(MiniProgram $mini, string $path, string $query): string
            {
                return 'weixin://dl/business/?t=quota-target';
            }
        });

        $first = $this->getJson('/api/link-target/'.$link->code)->assertOk();
        $second = $this->getJson('/api/link-target/'.$link->code);

        $second->assertStatus(429)
            ->assertJsonPath('code', 'QUOTA_EXCEEDED')
            ->assertJsonStructure(['code', 'message'])
            ->assertCookie('visitor_id');
        $visitorCookie = $first->headers->getCookies()[0]->getValue();
        $this->withUnencryptedCookies(['visitor_id' => $visitorCookie])
            ->withCredentials()
            ->getJson('/api/link-target/'.$link->code)
            ->assertOk();
        $this->assertSame(1, (int) UsagePeriod::query()->where('user_id', $link->user_id)->value('used_uv'));
        $this->assertSame(2, LinkVisitLog::query()->where('link_id', $link->id)->count());
    }

    public function test_all_six_public_target_paths_return_only_their_common_safe_shape(): void
    {
        app()->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
        Http::fake([
            'https://account.kdocs.cn/api/v3/miniprogram/urllink*' => Http::response([
                'url_link' => 'https://account.kdocs.cn/fetch/task6',
            ], 200),
            'https://account.kdocs.cn/fetch/task6' => Http::response("url_scheme: 'weixin://dl/business/?t=king-task6'", 200),
            'https://nc.cli.im/api/weixin/getWxUrlScheme/*' => Http::response([
                'data' => ['wx_url_scheme' => ['fetchUrl' => 'https://nc.cli.im/fetch/task6']],
            ], 200),
            'https://nc.cli.im/fetch/task6' => Http::response([
                'data' => ['urlScheme' => 'weixin://dl/business/?t=cli-task6'],
            ], 200),
            'https://ym.link/task6' => Http::response('weixin://dl/business/?t=qq-task6', 200),
        ]);
        app()->instance(MiniProgramSchemeGenerator::class, new class implements MiniProgramSchemeGenerator
        {
            public function generate(MiniProgram $mini, string $path, string $query): string
            {
                return 'weixin://dl/business/?t=mini-task6';
            }
        });

        $links = [
            $this->miniProgramLink(),
            $this->linkForType(LinkType::KING_DOC, ['url' => 'https://kdocs.cn/l/task6']),
            $this->linkForType(LinkType::CLI_QR, ['url' => 'https://qr61.cn/task6/id']),
            $this->linkForType(LinkType::WORK_WECHAT, ['url' => 'https://work.weixin.qq.com/ca/task6']),
        ];
        $landing = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'landing-task6.png', 'expired_at' => null, 'uv_limit_num' => 5],
        ]);
        $landingMini = MiniProgram::query()->findOrFail(data_get($landing->config, 'min_id'));
        $landingMini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        $links[] = $landing;
        $links[] = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/task6']);

        foreach ($links as $link) {
            $response = $this->withUnencryptedCookies(['visitor_id' => (string) Str::uuid()])->withCredentials()
                ->getJson('/api/link-target/'.$link->code);
            $response->assertOk()->assertJsonPath('code', 0);
            $data = $response->json('data');
            $this->assertSame(
                $link->type === LinkType::LANDING_MINI
                    ? ['title', 'description', 'icon', 'target', 'visitorToken']
                    : ['title', 'description', 'icon', 'target'],
                array_keys($data),
            );
            $this->assertIsString($data['target']);
            $this->assertNotSame('', $data['target']);
        }
    }

    public function test_schema_keeps_links_without_an_expiry_gate_and_sanitizes_visit_logs(): void
    {
        $this->assertTrue(Schema::hasColumns('links', ['manual_status', 'health_status']));
        $this->assertTrue(Schema::hasColumns('link_visit_logs', ['visitor_hash', 'ip_hash', 'user_agent_hash']));
        $this->assertTrue(Schema::hasColumns('links', ['status', 'expired_at']));
        $this->assertTrue(Schema::hasColumns('link_visit_logs', ['ip', 'device_uid', 'cache']));
        $this->assertSame(0, DB::table('links')->whereNotNull('expired_at')->count());
    }

    public function test_link_models_cast_new_status_fields_and_reject_legacy_identity_mass_assignment(): void
    {
        $link = new Link(['manual_status' => 0, 'health_status' => 1]);
        $this->assertFalse($link->manual_status);
        $this->assertTrue($link->health_status);

        $log = new LinkVisitLog([
            'link_id' => 1,
            'user_id' => 2,
            'visitor_hash' => str_repeat('a', 64),
            'ip_hash' => str_repeat('b', 64),
            'user_agent_hash' => str_repeat('c', 64),
            'cache' => ['target' => 'https://example.test'],
            'ip' => '192.0.2.1',
            'device_uid' => 'client-supplied-device',
        ]);

        $this->assertSame(['link_id', 'user_id', 'visitor_hash', 'ip_hash', 'user_agent_hash', 'cache'], $log->getFillable());
        $this->assertFalse($log->isFillable('ip'));
        $this->assertFalse($log->isFillable('device_uid'));
        $this->assertArrayNotHasKey('ip', $log->getAttributes());
        $this->assertArrayNotHasKey('device_uid', $log->getAttributes());
        $this->assertSame('https://example.test', $log->cache['target']);
    }

    public function test_upgrade_copies_legacy_status_clears_expiry_and_preserves_legacy_columns(): void
    {
        $migrationPath = database_path('migrations/2026_09_01_000101_link_resolution_stability.php');
        $migration = require $migrationPath;
        $migration->down();

        $legacyLinkId = DB::table('links')->insertGetId([
            'user_id' => 1,
            'title' => 'Legacy link',
            'type' => 1,
            'status' => 0,
            'icon' => '',
            'description' => null,
            'remark' => null,
            'code' => 'legacy-upgrade',
            'config' => json_encode([]),
            'price' => 0,
            'expired_at' => '2030-01-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $upgraded = DB::table('links')->where('id', $legacyLinkId)->first();
        $this->assertSame(0, (int) $upgraded->manual_status);
        $this->assertSame(1, (int) $upgraded->health_status);
        $this->assertNull($upgraded->expired_at);
        $this->assertTrue(Schema::hasColumns('links', ['status', 'expired_at']));
        $this->assertTrue(Schema::hasColumns('link_visit_logs', ['ip', 'device_uid', 'cache']));
    }

    public function test_task_one_indexes_cover_status_and_sanitized_visit_lookup(): void
    {
        $linkIndexes = Schema::getIndexes('links');
        $visitIndexes = Schema::getIndexes('link_visit_logs');

        $this->assertTrue(collect($linkIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['user_id', 'manual_status'],
        ));
        $this->assertTrue(collect($visitIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['link_id', 'visitor_hash'],
        ));
        $this->assertTrue(collect($visitIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['link_id', 'created_at'],
        ));
    }

    public function test_account_uv_passes_raw_server_visitor_once_to_foundation_meter(): void
    {
        $user = $this->activeMemberWithUvLimit(1);
        $at = CarbonImmutable::now('Asia/Shanghai');
        $meter = app(UsageMeter::class);
        $visitorId = (string) Str::uuid();
        $otherVisitorId = (string) Str::uuid();

        $meter->consume($user, $visitorId, $at);
        $meter->consume($user, $visitorId, $at);

        try {
            $meter->consume($user, $otherVisitorId, $at);
            $this->fail('a different visitor must be rejected at the full account quota');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('QUOTA_EXCEEDED', $exception->errorCode);
        }

        $period = UsagePeriod::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1, (int) $period->used_uv);
        $this->assertDatabaseHas('usage_visitors', [
            'usage_period_id' => $period->id,
            'visitor_hash' => hash('sha256', $visitorId),
        ]);
        $this->assertDatabaseMissing('usage_visitors', [
            'usage_period_id' => $period->id,
            'visitor_hash' => hash('sha256', $otherVisitorId),
        ]);
    }

    public function test_rotation_feature_path_returns_sort_zero_and_ignores_display_visit_uv(): void
    {
        $link = $this->landingLinkWithQrs([
            [
                'sort' => 0,
                'path' => 'feature-zero.png',
                'name' => 'feature-zero',
                'visit_uv' => 999,
                'uv_limit_num' => 1,
            ],
        ]);

        $selection = app(QrRotationService::class)->reserve(
            $link,
            CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai'),
        );

        $this->assertSame(0, $selection->sort);
        $this->assertSame('feature-zero.png', $selection->path);
        $this->assertSame('1', (string) Redis::connection('default')->hget('link:qr:'.$link->id.':accumulate', '0'));
    }

    public function test_rotation_feature_path_maps_invalid_persisted_qr_config_to_stable_error(): void
    {
        $link = $this->linkForType(LinkType::LANDING_MINI, [
            'wx' => [
                'qr' => [['sort' => 1, 'path' => 'unsafe.png', 'uv_limit_num' => 'not-a-number']],
                'switch_type' => 1,
                'uv_limit_type' => 1,
            ],
        ]);

        try {
            app(QrRotationService::class)->reserve($link, CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai'));
            $this->fail('invalid persisted QR config must fail closed');
        } catch (QrUnavailable $exception) {
            $this->assertSame('QR_UNAVAILABLE', $exception->errorCode);
            $this->assertStringNotContainsString('not-a-number', $exception->getMessage());
        }
    }
}
