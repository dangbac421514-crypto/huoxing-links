<?php

namespace Tests\Unit\Services\Health;

use App\Contracts\DnsResolver;
use App\Enums\LinkType;
use App\Enums\MiniType;
use App\Exceptions\LinkResolutionException;
use App\Models\MiniProgram;
use App\Services\Health\HealthChecker;
use App\Services\Health\HealthCheckerRegistry;
use App\Services\Health\HealthCheckResult;
use App\Services\Health\LinkHealthCheckService;
use App\Support\LinkError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class LinkHealthCheckServiceTest extends TestCase
{
    use CreatesLinkFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();
        Http::preventStrayRequests();
        app()->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
    }

    protected function tearDown(): void
    {
        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_health_schema_can_roll_back_and_reapply_without_removing_links(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT);
        $migration = require database_path('migrations/2026_09_01_000102_link_health_check.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumns('links', ['health_checked_at', 'health_error_code']));
        $this->assertDatabaseHas('links', ['id' => $link->id]);

        $migration->up();
        $this->assertTrue(Schema::hasColumns('links', ['health_checked_at', 'health_error_code']));
        $this->assertDatabaseHas('links', ['id' => $link->id]);
    }

    public function test_health_check_result_requires_a_stable_error_code_and_has_safe_factories(): void
    {
        $healthy = HealthCheckResult::healthy();
        $this->assertTrue($healthy->healthy);
        $this->assertNull($healthy->errorCode);

        $unhealthy = HealthCheckResult::unhealthy('WORK_WECHAT_HEALTH_FAILED');
        $this->assertFalse($unhealthy->healthy);
        $this->assertSame('WORK_WECHAT_HEALTH_FAILED', $unhealthy->errorCode);

        $this->expectException(\InvalidArgumentException::class);
        HealthCheckResult::unhealthy('');
    }

    public function test_registry_maps_all_six_types_and_unknown_values_fail_closed(): void
    {
        $registry = app(HealthCheckerRegistry::class);
        foreach (LinkType::cases() as $type) {
            $this->assertInstanceOf(HealthChecker::class, $registry->for($type));
            $this->assertInstanceOf(HealthChecker::class, $registry->for($type->value));
        }

        foreach ([0, 6, 10, 12, '01', '6', 'not-a-type', null, new \stdClass] as $unknown) {
            try {
                $registry->for($unknown);
                $this->fail('unknown link type was accepted');
            } catch (LinkResolutionException $exception) {
                $this->assertSame(LinkError::LINK_TYPE_UNSUPPORTED, $exception->errorCode);
            }
        }
    }

    public function test_service_persists_only_health_fields_and_preserves_manual_revision_and_updated_at(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT, ['url' => 'https://work.weixin.qq.com/ca/example']);
        $link->update(['manual_status' => false, 'health_status' => false]);
        $before = DB::table('links')->where('id', $link->id)->first();
        $at = CarbonImmutable::parse('2026-09-02 12:34:56', 'Asia/Shanghai');
        Http::fake(['https://work.weixin.qq.com/*' => Http::response('', 200)]);

        app(LinkHealthCheckService::class)->check($link->fresh(), $at);

        $after = DB::table('links')->where('id', $link->id)->first();
        $this->assertSame(0, (int) $after->manual_status);
        $this->assertSame(1, (int) $after->health_status);
        $this->assertNull($after->health_error_code);
        $this->assertSame($before->target_version, $after->target_version);
        $this->assertSame($before->updated_at, $after->updated_at);
        $this->assertSame($at->format('Y-m-d H:i:s'), $after->health_checked_at);
        $this->assertTrue($link->fresh()->health_checked_at->equalTo($at));
    }

    public function test_work_wechat_health_check_uses_bounded_fake_get_and_maps_non_2xx_to_stable_code(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT, ['url' => 'https://work.weixin.qq.com/ca/example']);
        Http::fake(['https://work.weixin.qq.com/*' => Http::response('bad gateway', 503)]);

        app(LinkHealthCheckService::class)->check($link, CarbonImmutable::now('Asia/Shanghai'));

        $stored = $link->fresh();
        $this->assertFalse((bool) $stored->health_status);
        $this->assertSame('WORK_WECHAT_HEALTH_FAILED', $stored->health_error_code);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://work.weixin.qq.com/ca/example');
    }

    public function test_mini_program_health_check_validates_configuration_without_generating_a_scheme_or_http_call(): void
    {
        $link = $this->miniProgramLink();
        $generatorCalled = false;
        Http::fake();

        app(LinkHealthCheckService::class)->check($link, CarbonImmutable::now('Asia/Shanghai'));

        $stored = $link->fresh();
        $this->assertTrue((bool) $stored->health_status);
        $this->assertNull($stored->health_error_code);
        $this->assertFalse($generatorCalled);
        Http::assertNothingSent();
    }

    public function test_mini_program_health_check_rejects_bad_path_and_disabled_reference(): void
    {
        $link = $this->miniProgramLink();
        $config = $link->config;
        $config['url'] = 'pages/index/index?evil=1';
        $link->config = $config;
        $link->save();
        app(LinkHealthCheckService::class)->check($link->fresh(), CarbonImmutable::now('Asia/Shanghai'));
        $this->assertSame('MINI_PROGRAM_HEALTH_FAILED', $link->fresh()->health_error_code);

        $mini = MiniProgram::query()->findOrFail($config['min_id']);
        $mini->update(['is_enable' => false]);
        app(LinkHealthCheckService::class)->check($link->fresh(), CarbonImmutable::now('Asia/Shanghai'));
        $this->assertSame('MINI_PROGRAM_HEALTH_FAILED', $link->fresh()->health_error_code);
    }

    public function test_landing_health_check_accepts_a_non_expired_qr_without_reserving_or_incrementing(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'qr.png', 'expired_at' => '2026-09-03', 'uv_limit_num' => 1],
        ]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        $at = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai');

        app(LinkHealthCheckService::class)->check($link->fresh(), $at);

        $stored = $link->fresh();
        $this->assertTrue((bool) $stored->health_status);
        $this->assertNull($stored->health_error_code);
        $this->assertSame(0, Redis::connection('default')->exists('link:qr:'.$link->id.':accumulate'));
        $this->assertSame(0, Redis::connection('default')->exists('link:qr:'.$link->id.':cursor:accumulate'));
    }

    public function test_landing_health_check_rejects_expired_or_malformed_qr_without_touching_qr_counters(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'expired.png', 'expired_at' => '2026-09-01', 'uv_limit_num' => 1],
        ]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        $at = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai');
        app(LinkHealthCheckService::class)->check($link->fresh(), $at);
        $this->assertSame('LANDING_MINI_HEALTH_FAILED', $link->fresh()->health_error_code);

        $config = $link->fresh()->config;
        $config['wx']['qr'] = [];
        $link->config = $config;
        $link->save();
        app(LinkHealthCheckService::class)->check($link->fresh(), $at);
        $this->assertSame('LANDING_MINI_HEALTH_FAILED', $link->fresh()->health_error_code);
        $this->assertSame(0, Redis::connection('default')->dbsize());
    }

    public function test_landing_health_configuration_does_not_fail_when_only_qr_counters_are_full(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'full.png', 'expired_at' => '2026-09-03', 'uv_limit_num' => 1],
        ]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        Redis::connection('default')->hset('link:qr:'.$link->id.':accumulate', 1, 1);

        app(LinkHealthCheckService::class)->check($link->fresh(), CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai'));

        $this->assertTrue((bool) $link->fresh()->health_status);
        $this->assertSame('1', (string) Redis::connection('default')->hget('link:qr:'.$link->id.':accumulate', 1));
    }

    public function test_king_doc_cli_qr_and_qq_health_checks_reuse_resolvers_with_anonymous_context(): void
    {
        $king = $this->linkForType(LinkType::KING_DOC, ['url' => 'https://kdocs.cn/l/Abc123']);
        $cli = $this->linkForType(LinkType::CLI_QR, ['url' => 'https://qr61.cn/user_1/id-2']);
        $qq = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/path/to/qr']);
        Http::fake([
            'https://account.kdocs.cn/api/v3/miniprogram/urllink*' => Http::response(['url_link' => 'https://account.kdocs.cn/fetch/Abc123'], 200),
            'https://account.kdocs.cn/fetch/Abc123' => Http::response("url_scheme: 'weixin://dl/business/?t=king-token'", 200),
            'https://nc.cli.im/api/weixin/getWxUrlScheme/*' => Http::response(['data' => ['wx_url_scheme' => ['fetchUrl' => 'https://nc.cli.im/fetch/ticket']]], 200),
            'https://nc.cli.im/fetch/ticket' => Http::response(['data' => ['urlScheme' => 'weixin://dl/business/?t=cli-token']], 200),
            'https://ym.link/path/to/qr' => Http::response('weixin://dl/business/?t=qq-token', 200),
        ]);

        $service = app(LinkHealthCheckService::class);
        $at = CarbonImmutable::now('Asia/Shanghai');
        $service->check($king, $at);
        $service->check($cli, $at);
        $service->check($qq, $at);

        $this->assertTrue((bool) $king->fresh()->health_status);
        $this->assertTrue((bool) $cli->fresh()->health_status);
        $this->assertTrue((bool) $qq->fresh()->health_status);
    }

    public function test_provider_exception_is_mapped_to_the_type_code_and_does_not_escape(): void
    {
        $link = $this->linkForType(LinkType::KING_DOC, ['url' => 'https://kdocs.cn/l/Abc123']);
        Http::fake(['https://account.kdocs.cn/*' => fn () => throw new \RuntimeException('provider response secret')]);

        app(LinkHealthCheckService::class)->check($link, CarbonImmutable::now('Asia/Shanghai'));

        $stored = $link->fresh();
        $this->assertFalse((bool) $stored->health_status);
        $this->assertSame('KING_DOC_HEALTH_FAILED', $stored->health_error_code);
        $this->assertStringNotContainsString('provider response secret', (string) $stored->health_error_code);
    }
}
