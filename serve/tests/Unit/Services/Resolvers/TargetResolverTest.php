<?php

namespace Tests\Unit\Services\Resolvers;

use App\Contracts\DnsResolver;
use App\Contracts\MiniProgramSchemeClient;
use App\Contracts\MiniProgramSchemeGenerator;
use App\DTO\VisitorContext;
use App\Enums\LinkType;
use App\Enums\MiniType;
use App\Exceptions\LinkResolutionException;
use App\Exceptions\MiniProgramForbidden;
use App\Exceptions\QrUnavailable;
use App\Models\Link;
use App\Models\MiniProgram;
use App\Services\EasyWechatMiniProgramSchemeClient;
use App\Services\EasyWechatMiniProgramSchemeGenerator;
use App\Services\Resolvers\CliQrTargetResolver;
use App\Services\Resolvers\KingDocTargetResolver;
use App\Services\Resolvers\LandingMiniTargetResolver;
use App\Services\Resolvers\MiniProgramTargetResolver;
use App\Services\Resolvers\QqQrTargetResolver;
use App\Services\Resolvers\TargetResolver;
use App\Services\Resolvers\TargetResolverRegistry;
use App\Services\Resolvers\WorkWechatTargetResolver;
use App\Services\WeixinSchemePolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class TargetResolverTest extends TestCase
{
    use CreatesLinkFixtures;

    /** @var array<string, list<string>> */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Redis::connection('default')->flushdb();
        $this->bindDns([
            'account.kdocs.cn' => ['8.8.8.8'],
            'kdocs.cn' => ['8.8.8.8'],
            'nc.cli.im' => ['8.8.8.8'],
            'ym.link' => ['8.8.8.8'],
            'work.weixin.qq.com' => ['8.8.8.8'],
        ]);
        app()->instance(MiniProgramSchemeGenerator::class, new FakeSchemeGenerator);
    }

    protected function tearDown(): void
    {
        Redis::connection('default')->flushdb();
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_mini_program_resolver_uses_tenant_checked_mini_and_only_code_query(): void
    {
        $link = $this->miniProgramLink();
        $generator = new FakeSchemeGenerator('weixin://dl/business/?t=mini-token');
        app()->instance(MiniProgramSchemeGenerator::class, $generator);

        $result = app(MiniProgramTargetResolver::class)->resolve($link, VisitorContext::anonymous());

        $this->assertSame('weixin://dl/business/?t=mini-token', $result->target);
        $this->assertSame('pages/index/index', $generator->path);
        $this->assertSame(['code' => $link->code], $this->parseQuery($generator->query));
        $this->assertStringNotContainsString('visitor', $generator->query);
        $this->assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_mini_program_resolver_rejects_a_missing_persisted_code(): void
    {
        $link = $this->miniProgramLink();
        $link->code = '';
        $link->saveQuietly();

        try {
            app(MiniProgramTargetResolver::class)->resolve($link->fresh(), VisitorContext::anonymous());
            $this->fail('missing link code was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('MINI_PROGRAM_EXTERNAL_ERROR', $exception->errorCode);
        }
    }

    public function test_mini_program_resolver_uses_mini_default_when_optional_path_is_null(): void
    {
        $link = $this->miniProgramLink();
        $config = $link->config;
        $config['url'] = null;
        $link->config = $config;
        $link->save();
        $generator = new FakeSchemeGenerator;
        app()->instance(MiniProgramSchemeGenerator::class, $generator);

        app(MiniProgramTargetResolver::class)->resolve($link->fresh(), VisitorContext::anonymous());

        $this->assertSame('pages/index/index', $generator->path);
    }

    public function test_king_doc_resolver_uses_bounded_two_step_protocol_and_validates_scheme(): void
    {
        $link = $this->linkForType(LinkType::KING_DOC, ['url' => 'https://kdocs.cn/l/Abc123']);
        Http::fake([
            'https://account.kdocs.cn/api/v3/miniprogram/urllink*' => Http::response([
                'url_link' => 'https://account.kdocs.cn/fetch/Abc123',
            ], 200),
            'https://account.kdocs.cn/fetch/Abc123' => Http::response("url_scheme: 'weixin://dl/business/?t=king-token'", 200),
        ]);

        $result = app(KingDocTargetResolver::class)->resolve($link, VisitorContext::anonymous());

        $this->assertSame('weixin://dl/business/?t=king-token', $result->target);
        Http::assertSentCount(2);
    }

    public function test_king_doc_resolver_normalizes_provider_escaped_slashes_once(): void
    {
        $link = $this->linkForType(LinkType::KING_DOC, ['url' => 'https://kdocs.cn/l/Abc123']);
        Http::fake([
            'https://account.kdocs.cn/api/v3/miniprogram/urllink*' => Http::response([
                'url_link' => 'https://account.kdocs.cn/fetch/Abc123',
            ], 200),
            'https://account.kdocs.cn/fetch/Abc123' => Http::response(
                json_encode(['url_scheme' => 'weixin:\/\/dl\/business\/?t=king-token'], JSON_THROW_ON_ERROR),
                200,
            ),
        ]);

        $result = app(KingDocTargetResolver::class)->resolve($link, VisitorContext::anonymous());

        $this->assertSame('weixin://dl/business/?t=king-token', $result->target);
    }

    public function test_king_doc_resolver_decodes_html_entity_wrapped_scheme(): void
    {
        $link = $this->linkForType(LinkType::KING_DOC, ['url' => 'https://kdocs.cn/l/Abc123']);
        Http::fake([
            'https://account.kdocs.cn/api/v3/miniprogram/urllink*' => Http::response([
                'url_link' => 'https://account.kdocs.cn/fetch/Abc123',
            ], 200),
            'https://account.kdocs.cn/fetch/Abc123' => Http::response('url_scheme: &quot;weixin://dl/business/?t=king-token&quot;', 200),
        ]);

        $result = app(KingDocTargetResolver::class)->resolve($link, VisitorContext::anonymous());

        $this->assertSame('weixin://dl/business/?t=king-token', $result->target);
    }

    public function test_king_doc_resolver_rejects_a_second_malformed_scheme_field(): void
    {
        $link = $this->linkForType(LinkType::KING_DOC, ['url' => 'https://kdocs.cn/l/Abc123']);
        Http::fake([
            'https://account.kdocs.cn/api/v3/miniprogram/urllink*' => Http::response([
                'url_link' => 'https://account.kdocs.cn/fetch/Abc123',
            ], 200),
            'https://account.kdocs.cn/fetch/Abc123' => Http::response(
                "url_scheme: 'weixin://dl/business/?t=one'\nurl_scheme: invalid",
                200,
            ),
        ]);

        try {
            app(KingDocTargetResolver::class)->resolve($link, VisitorContext::anonymous());
            $this->fail('multiple scheme fields were accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('KING_DOC_EXTERNAL_ERROR', $exception->errorCode);
        }
    }

    public function test_cli_qr_resolver_fetches_ticket_and_final_scheme_through_safe_http(): void
    {
        $link = $this->linkForType(LinkType::CLI_QR, ['url' => 'https://qr61.cn/user_1/id-2']);
        Http::fake([
            'https://nc.cli.im/api/weixin/getWxUrlScheme/*' => Http::response([
                'data' => ['wx_url_scheme' => ['fetchUrl' => 'https://nc.cli.im/fetch/ticket']],
            ], 200),
            'https://nc.cli.im/fetch/ticket' => Http::response([
                'data' => ['urlScheme' => 'weixin://dl/business/?t=cli-token'],
            ], 200),
        ]);

        $result = app(CliQrTargetResolver::class)->resolve($link, VisitorContext::anonymous());

        $this->assertSame('weixin://dl/business/?t=cli-token', $result->target);
        Http::assertSentCount(2);
    }

    public function test_work_wechat_resolver_validates_without_fetching(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT, ['url' => 'https://work.weixin.qq.com/ca/example?scene=1']);

        $result = app(WorkWechatTargetResolver::class)->resolve($link, VisitorContext::anonymous());

        $this->assertSame('https://work.weixin.qq.com/ca/example?scene=1', $result->target);
        Http::assertNothingSent();
    }

    public function test_work_wechat_resolver_accepts_an_unsaved_link_with_a_typed_enum(): void
    {
        $link = new Link([
            'type' => LinkType::WORK_WECHAT,
            'title' => 'Unsaved work link',
            'description' => '',
            'icon' => null,
            'config' => ['url' => 'https://work.weixin.qq.com/ca/example'],
        ]);

        $result = app(WorkWechatTargetResolver::class)->resolve($link, VisitorContext::anonymous());

        $this->assertSame('https://work.weixin.qq.com/ca/example', $result->target);
    }

    public function test_landing_resolver_reserves_one_qr_and_uses_only_code_and_opaque_token(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'qr.png', 'expired_at' => '2026-09-03', 'uv_limit_num' => 2],
        ]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        $generator = new FakeSchemeGenerator('weixin://dl/business/?t=landing-token');
        app()->instance(MiniProgramSchemeGenerator::class, $generator);

        $context = new VisitorContext('visitor-server-id', 'visitor-hash');
        $result = app(LandingMiniTargetResolver::class)->resolve($link->fresh(), $context);

        $this->assertSame('weixin://dl/business/?t=landing-token', $result->target);
        $this->assertIsString($result->visitorToken);
        $this->assertNull($result->qr);
        $query = $this->parseQuery($generator->query);
        $this->assertSame(['code' => $link->code, 'visitor_token' => $result->visitorToken], $query);
        $this->assertStringNotContainsString('visitor-server-id', $generator->query);
        $this->assertStringNotContainsString('device_uid', $generator->query);
        $this->assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_qq_qr_resolver_entity_decodes_only_escaped_slashes_and_requires_exactly_one_scheme(): void
    {
        $link = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/path/to/qr']);
        Http::fake([
            'https://ym.link/path/to/qr' => Http::response(
                '&lt;script&gt;const x = "weixin:\\/\\/dl\\/business\\/?t=qq-token";&lt;/script&gt;',
                200,
            ),
        ]);

        $result = app(QqQrTargetResolver::class)->resolve($link, VisitorContext::anonymous());

        $this->assertSame('weixin://dl/business/?t=qq-token', $result->target);
    }

    public function test_registry_maps_every_link_type_and_rejects_unknown_values(): void
    {
        $registry = app(TargetResolverRegistry::class);
        foreach (LinkType::cases() as $type) {
            $this->assertInstanceOf(TargetResolver::class, $registry->for($type));
            $this->assertInstanceOf(TargetResolver::class, $registry->for($type->value));
        }

        foreach ([0, 6, 10, 12, '01', '6', 'not-a-type', null, new \stdClass] as $unknown) {
            try {
                $registry->for($unknown);
                $this->fail('unknown link type was accepted');
            } catch (LinkResolutionException $exception) {
                $this->assertSame('LINK_TYPE_UNSUPPORTED', $exception->errorCode);
            }
        }
    }

    public function test_malformed_provider_data_and_wrong_type_have_type_specific_safe_errors(): void
    {
        $link = $this->linkForType(LinkType::KING_DOC, ['url' => 'https://kdocs.cn/l/id']);
        Http::fake(['https://account.kdocs.cn/*' => Http::response(['url_link' => 'https://kdocs.cn/fetch'], 200)]);
        try {
            app(KingDocTargetResolver::class)->resolve($link, VisitorContext::anonymous());
            $this->fail('malformed provider response was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('KING_DOC_EXTERNAL_ERROR', $exception->errorCode);
            $this->assertStringNotContainsString('kdocs.cn', $exception->getMessage());
        }

        $wrong = $this->linkForType(LinkType::WORK_WECHAT);
        try {
            app(KingDocTargetResolver::class)->resolve($wrong, VisitorContext::anonymous());
            $this->fail('wrong link type was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('KING_DOC_EXTERNAL_ERROR', $exception->errorCode);
        }
    }

    public function test_weixin_scheme_policy_rejects_http_userinfo_fragment_and_malformed_percent_values(): void
    {
        $policy = app(WeixinSchemePolicy::class);
        foreach ([
            'https://dl/business/?t=bad',
            'weixin://evil/business/?t=bad',
            'weixin://user@dl/business/?t=bad',
            'weixin://dl/business/?t=bad#fragment',
            'weixin://dl/business/?t=bad%',
            'weixin://dl/business/?t=%00',
            'weixin://dl/business/?t=%5c',
            'weixin://dl/business/?t=',
            'weixin://dl/business/?=value',
        ] as $scheme) {
            $this->assertFalse($policy->validate($scheme));
        }

        $this->assertTrue($policy->validate('weixin://dl/business/?t=safe-token'));
    }

    public function test_landing_resolver_rejects_anonymous_context_and_does_not_reserve_or_cache(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'qr.png', 'expired_at' => '2026-09-03', 'uv_limit_num' => 2],
        ]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);

        try {
            app(LandingMiniTargetResolver::class)->resolve($link, VisitorContext::anonymous());
            $this->fail('anonymous landing context was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('LANDING_MINI_EXTERNAL_ERROR', $exception->errorCode);
        }

        $this->assertSame(0, Redis::connection('default')->exists('link:qr:'.$link->id.':accumulate'));
    }

    public function test_landing_resolver_maps_qr_exhaustion_without_generating_a_scheme(): void
    {
        $link = $this->landingLinkWithQrs([]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        $generator = new FakeSchemeGenerator;
        app()->instance(MiniProgramSchemeGenerator::class, $generator);

        try {
            app(LandingMiniTargetResolver::class)->resolve(
                $link,
                new VisitorContext('visitor-server-id', 'visitor-hash'),
            );
            $this->fail('QR exhaustion was accepted');
        } catch (QrUnavailable $exception) {
            $this->assertSame('QR_UNAVAILABLE', $exception->errorCode);
        }

        $this->assertNull($generator->path);
        $this->assertNull($generator->query);
    }

    public function test_regular_mini_resolver_preserves_cross_tenant_mini_forbidden_error(): void
    {
        $link = $this->miniProgramLink();
        $other = $this->activeMemberWithUvLimit(10);
        $otherMini = $this->miniProgramFor($other);
        $config = $link->config;
        $config['min_id'] = $otherMini->id;
        $link->config = $config;
        $link->save();

        try {
            app(MiniProgramTargetResolver::class)->resolve($link->fresh(), VisitorContext::anonymous());
            $this->fail('cross-tenant mini reference was accepted');
        } catch (MiniProgramForbidden $exception) {
            $this->assertSame('MINI_PROGRAM_FORBIDDEN', $exception->errorCode);
        }
    }

    public function test_landing_resolver_preserves_token_key_configuration_error(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'qr.png', 'uv_limit_num' => 2],
        ]);
        $mini = MiniProgram::query()->findOrFail(data_get($link->config, 'min_id'));
        $mini->update(['is_pre_min' => true, 'type' => MiniType::LANDING]);
        config(['app.visitor_token_key' => 'not-valid-base64-key']);

        try {
            app(LandingMiniTargetResolver::class)->resolve(
                $link->fresh(),
                new VisitorContext('visitor-server-id', 'visitor-hash'),
            );
            $this->fail('invalid visitor token key was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('VISITOR_TOKEN_KEY_INVALID', $exception->errorCode);
        }
    }

    public function test_qq_qr_resolver_rejects_multiple_schemes_without_leaking_html(): void
    {
        $link = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/path']);
        Http::fake([
            'https://ym.link/path' => Http::response('<p>weixin://dl/business/?t=one</p><p>weixin://dl/business/?t=two</p>', 200),
        ]);
        try {
            app(QqQrTargetResolver::class)->resolve($link, VisitorContext::anonymous());
            $this->fail('multiple QQ QR schemes were accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('QQ_QR_EXTERNAL_ERROR', $exception->errorCode);
        }
    }

    public function test_qq_qr_resolver_rejects_missing_scheme_without_leaking_html(): void
    {
        $link = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/path']);
        Http::fake([
            'https://ym.link/path' => Http::response('<p>nothing useful</p><p>provider-secret</p>', 200),
        ]);
        try {
            app(QqQrTargetResolver::class)->resolve($link, VisitorContext::anonymous());
            $this->fail('missing QQ QR scheme was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('QQ_QR_EXTERNAL_ERROR', $exception->errorCode);
            $this->assertStringNotContainsString('provider-secret', $exception->getMessage());
        }
    }

    public function test_qq_qr_resolver_does_not_accept_an_embedded_scheme_token(): void
    {
        $link = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/path']);
        Http::fake(['https://ym.link/path' => Http::response('notweixin://dl/business/?t=embedded', 200)]);

        try {
            app(QqQrTargetResolver::class)->resolve($link, VisitorContext::anonymous());
            $this->fail('embedded scheme token was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('QQ_QR_EXTERNAL_ERROR', $exception->errorCode);
        }
    }

    public function test_qq_qr_resolver_handles_json_escaped_slashes_without_decoding_other_escapes(): void
    {
        $link = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/path']);
        $encoded = str_replace(
            '/',
            '\\/',
            json_encode(['target' => 'weixin://dl/business/?t=json-token'], JSON_THROW_ON_ERROR),
        );
        Http::fake(['https://ym.link/path' => Http::response($encoded, 200)]);

        $result = app(QqQrTargetResolver::class)->resolve($link, VisitorContext::anonymous());

        $this->assertSame('weixin://dl/business/?t=json-token', $result->target);
    }

    public function test_qq_qr_resolver_rejects_extra_query_parameters_after_t(): void
    {
        $link = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/path']);
        Http::fake(['https://ym.link/path' => Http::response('weixin://dl/business/?t=token&extra=x', 200)]);

        try {
            app(QqQrTargetResolver::class)->resolve($link, VisitorContext::anonymous());
            $this->fail('QQ QR scheme with extra query parameters was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('QQ_QR_EXTERNAL_ERROR', $exception->errorCode);
        }
    }

    public function test_qq_qr_resolver_rejects_duplicate_t_query_parameters(): void
    {
        $link = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/path']);
        Http::fake(['https://ym.link/path' => Http::response('weixin://dl/business/?t=one&t=two', 200)]);

        try {
            app(QqQrTargetResolver::class)->resolve($link, VisitorContext::anonymous());
            $this->fail('QQ QR scheme with duplicate t parameters was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('QQ_QR_EXTERNAL_ERROR', $exception->errorCode);
        }
    }

    public function test_qq_qr_resolver_rejects_an_incomplete_query_delimiter(): void
    {
        $link = $this->linkForType(LinkType::QR_QQ, ['url' => 'https://ym.link/path']);
        Http::fake(['https://ym.link/path' => Http::response('weixin://dl/business/?t=token&', 200)]);

        try {
            app(QqQrTargetResolver::class)->resolve($link, VisitorContext::anonymous());
            $this->fail('incomplete QQ QR query was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('QQ_QR_EXTERNAL_ERROR', $exception->errorCode);
        }
    }

    public function test_easywechat_adapter_uses_injected_fake_client_and_returns_only_openlink(): void
    {
        $mini = MiniProgram::query()->create([
            'user_id' => $this->activeMemberWithUvLimit(10)->id,
            'name' => 'Adapter mini',
            'app_id' => 'wxadapter1234567',
            'secret' => 'adapter-secret',
            'url' => 'pages/index/index',
            'type' => MiniType::OWN,
            'is_pre_min' => false,
            'is_enable' => true,
        ]);
        $client = new FakeSchemeClient(['openlink' => 'weixin://dl/business/?t=adapter-token']);
        $generator = new EasyWechatMiniProgramSchemeGenerator($client);

        $this->assertSame('weixin://dl/business/?t=adapter-token', $generator->generate($mini, 'pages/index/index', 'code=abc'));
        $this->assertSame($mini->app_id, $client->appId);
        $this->assertSame('adapter-secret', $client->secret);
        $this->assertSame('pages/index/index', $client->path);
        $this->assertSame('code=abc', $client->query);
    }

    public function test_easywechat_adapter_never_rethrows_provider_error_details(): void
    {
        $mini = MiniProgram::query()->create([
            'user_id' => $this->activeMemberWithUvLimit(10)->id,
            'name' => 'Adapter error mini',
            'app_id' => 'wxadapter1234567',
            'secret' => 'adapter-secret',
            'url' => 'pages/index/index',
            'type' => MiniType::OWN,
            'is_pre_min' => false,
            'is_enable' => true,
        ]);
        $client = new class implements MiniProgramSchemeClient
        {
            public function generate(string $appId, string $secret, string $path, string $query): array
            {
                throw new LinkResolutionException('PROVIDER_SECRET', 'provider-secret-must-not-leak', 502);
            }
        };

        try {
            (new EasyWechatMiniProgramSchemeGenerator($client))->generate($mini, 'pages/index/index', 'code=abc');
            $this->fail('provider error was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('MINI_PROGRAM_EXTERNAL_ERROR', $exception->errorCode);
            $this->assertStringNotContainsString('provider-secret', $exception->getMessage());
        }
    }

    public function test_easywechat_adapter_rejects_a_non_weixin_openlink(): void
    {
        $mini = MiniProgram::query()->create([
            'user_id' => $this->activeMemberWithUvLimit(10)->id,
            'name' => 'Adapter malformed mini',
            'app_id' => 'wxadapter1234567',
            'secret' => 'adapter-secret',
            'url' => 'pages/index/index',
            'type' => MiniType::OWN,
            'is_pre_min' => false,
            'is_enable' => true,
        ]);
        $client = new FakeSchemeClient(['openlink' => 'https://evil.example/redirect']);

        try {
            (new EasyWechatMiniProgramSchemeGenerator($client))->generate($mini, 'pages/index/index', 'code=abc');
            $this->fail('non-WeChat openlink was accepted');
        } catch (LinkResolutionException $exception) {
            $this->assertSame('MINI_PROGRAM_EXTERNAL_ERROR', $exception->errorCode);
            $this->assertStringNotContainsString('evil.example', $exception->getMessage());
        }
    }

    public function test_easywechat_transport_uses_mock_http_with_access_token_and_safe_options(): void
    {
        $requests = [];
        $transport = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];
            if (str_contains($url, 'cgi-bin/token')) {
                return new MockResponse(
                    json_encode(['access_token' => 'fake-access-token', 'expires_in' => 7200], JSON_THROW_ON_ERROR),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
                );
            }

            return new MockResponse(
                json_encode(['openlink' => 'weixin://dl/business/?t=transport-token'], JSON_THROW_ON_ERROR),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        });
        $client = new EasyWechatMiniProgramSchemeClient($transport);

        $appId = 'wx'.Str::lower(Str::random(16));
        $result = $client->generate($appId, 'transport-secret', 'pages/index/index', 'code=abc');

        $this->assertSame(['openlink' => 'weixin://dl/business/?t=transport-token'], $result);
        $this->assertCount(2, $requests);
        [$method, $url, $options] = $requests[1];
        $this->assertSame('POST', $method);
        $this->assertStringContainsString('wxa/generatescheme', $url);
        $this->assertSame(3.0, $options['timeout']);
        $this->assertSame(3.0, $options['max_connect_duration']);
        $this->assertSame(8.0, $options['max_duration']);
        $this->assertSame('fake-access-token', $options['query']['access_token']);
        $this->assertStringNotContainsString('transport-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_easywechat_transport_retries_two_server_failures_and_maps_provider_error_safely(): void
    {
        $attempts = 0;
        $transport = new MockHttpClient(function (string $method, string $url, array $options) use (&$attempts): MockResponse {
            if (str_contains($url, 'cgi-bin/token')) {
                return new MockResponse(
                    json_encode(['access_token' => 'fake-access-token', 'expires_in' => 7200], JSON_THROW_ON_ERROR),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
                );
            }
            $attempts++;
            if ($attempts <= 2) {
                return new MockResponse('', ['http_code' => 503]);
            }

            return new MockResponse(
                json_encode(['errcode' => 40001, 'errmsg' => 'provider-secret-must-not-leak'], JSON_THROW_ON_ERROR),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        });
        $client = new EasyWechatMiniProgramSchemeClient($transport);

        try {
            $client->generate('wxtransportretry1', 'transport-secret', 'pages/index/index', 'code=abc');
            $this->fail('provider error was accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Mini program provider request failed', $exception->getMessage());
            $this->assertStringNotContainsString('provider-secret', $exception->getMessage());
        }
        $this->assertSame(3, $attempts);
    }

    /** @return array<string, string> */
    private function parseQuery(string $query): array
    {
        parse_str($query, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    /** @param array<string, list<string>> $answers */
    private function bindDns(array $answers): void
    {
        $this->dns = $answers;
        app()->instance(DnsResolver::class, new class($answers) implements DnsResolver
        {
            /** @param array<string, list<string>> $answers */
            public function __construct(private array $answers) {}

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? [];
            }
        });
    }
}

final class FakeSchemeGenerator implements MiniProgramSchemeGenerator
{
    public ?string $path = null;

    public ?string $query = null;

    public function __construct(private string $scheme = 'weixin://dl/business/?t=fake-token') {}

    public function generate(MiniProgram $mini, string $path, string $query): string
    {
        $this->path = $path;
        $this->query = $query;

        return $this->scheme;
    }
}

final class FakeSchemeClient implements MiniProgramSchemeClient
{
    public ?string $appId = null;

    public ?string $secret = null;

    public ?string $path = null;

    public ?string $query = null;

    /** @param array<string, mixed> $response */
    public function __construct(private array $response) {}

    public function generate(string $appId, string $secret, string $path, string $query): array
    {
        $this->appId = $appId;
        $this->secret = $secret;
        $this->path = $path;
        $this->query = $query;

        return $this->response;
    }
}
