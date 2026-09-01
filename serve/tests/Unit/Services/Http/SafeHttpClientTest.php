<?php

namespace Tests\Unit\Services\Http;

use App\Contracts\DnsResolver;
use App\Exceptions\UnsafeUrl;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\UrlPolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SafeHttpClientTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindDns([]);
    }

    protected function tearDown(): void
    {
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_url_policy_requires_exact_https_allowlisted_host_and_public_dns_answers(): void
    {
        $this->bindDns([
            'safe.example' => ['8.8.8.8'],
            'private.example' => ['10.0.0.1'],
            '2130706433' => ['8.8.8.8'],
        ]);
        $policy = app(UrlPolicy::class);

        $uri = $policy->assertExternal('https://SAFE.example/path?x=1', ['safe.example']);
        $this->assertSame('https://safe.example/path?x=1', (string) $uri);
        $this->assertSame(
            'https://safe.example/path%20with%20space?q=hello%20world',
            (string) $policy->assertExternal('https://safe.example/path%20with%20space?q=hello%20world', ['safe.example']),
        );

        foreach ([
            'http://safe.example/path',
            'https://user:pass@safe.example/path',
            'https://safe.example/path#fragment',
            'https://safe.example:8443/path',
            'https://safe.example%2e/path',
            'https://safe.example./path',
            'https://safe.example/path%00',
            'https://safe.example/path%5c',
            'https://2130706433/path',
            "https://safe.example/line\nfeed",
        ] as $unsafe) {
            try {
                $policy->assertExternal($unsafe, ['safe.example']);
                $this->fail('Unsafe URL was accepted: '.$unsafe);
            } catch (UnsafeUrl $exception) {
                $this->assertSame('UNSAFE_URL', $exception->errorCode);
                $this->assertStringNotContainsString('safe.example', $exception->getMessage());
            }
        }

        foreach ([[], ['https://safe.example'], ['*.example'], ['safe.example/path'], [''], ["safe.example\n"]] as $allowlist) {
            try {
                $policy->assertExternal('https://safe.example/path', $allowlist);
                $this->fail('invalid allowlist entry was accepted');
            } catch (UnsafeUrl $exception) {
                $this->assertSame('UNSAFE_URL', $exception->errorCode);
            }
        }

        $this->expectException(UnsafeUrl::class);
        $policy->assertExternal('https://private.example/path', ['private.example']);
    }

    public function test_url_policy_fails_closed_for_missing_or_mixed_dns_answers_including_mapped_ipv4(): void
    {
        $this->bindDns([
            'missing.example' => [],
            'mixed.example' => ['8.8.8.8', '192.168.1.10'],
            'mapped.example' => ['::ffff:127.0.0.1'],
            'invalid.example' => ['not-an-ip'],
        ]);
        $policy = app(UrlPolicy::class);

        foreach (['missing.example', 'mixed.example', 'mapped.example', 'invalid.example'] as $host) {
            try {
                $policy->assertExternal('https://'.$host.'/', [$host]);
                $this->fail('Unsafe DNS result was accepted: '.$host);
            } catch (UnsafeUrl $exception) {
                $this->assertSame('UNSAFE_URL', $exception->errorCode);
            }
        }
    }

    public function test_url_policy_rejects_noncanonical_numeric_ip_host_even_if_dns_looks_public(): void
    {
        $this->bindDns(['2130706433' => ['8.8.8.8']]);

        $this->expectException(UnsafeUrl::class);
        app(UrlPolicy::class)->assertExternal('https://2130706433/path', ['2130706433']);
    }

    public function test_url_policy_rejects_short_numeric_ipv4_notation(): void
    {
        $this->bindDns(['127.1' => ['8.8.8.8']]);

        $this->expectException(UnsafeUrl::class);
        app(UrlPolicy::class)->assertExternal('https://127.1/path', ['127.1']);
    }

    public function test_url_policy_classifies_literal_ip_itself_before_trusting_resolver_output(): void
    {
        $this->bindDns(['127.0.0.1' => ['8.8.8.8']]);

        $this->expectException(UnsafeUrl::class);
        app(UrlPolicy::class)->assertExternal('https://127.0.0.1/path', ['127.0.0.1']);
    }

    public function test_url_policy_rejects_hexadecimal_ip_notation(): void
    {
        $this->bindDns(['0x7f000001' => ['8.8.8.8']]);

        $this->expectException(UnsafeUrl::class);
        app(UrlPolicy::class)->assertExternal('https://0x7f000001/path', ['0x7f000001']);
    }

    public function test_url_policy_rejects_empty_fragments_and_non_global_ipv6_answers(): void
    {
        $this->bindDns([
            'ipv6-link-local.example' => ['fe80::1'],
            'ipv6-docs.example' => ['2001:db8::1'],
            'ipv6-multicast.example' => ['ff02::1'],
            'ipv6-global.example' => ['2001:4860:4860::8888'],
        ]);
        $policy = app(UrlPolicy::class);

        foreach (['https://ipv6-link-local.example/', 'https://ipv6-docs.example/', 'https://ipv6-multicast.example/'] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            try {
                $policy->assertExternal($url, [(string) $host]);
                $this->fail('non-global IPv6 answer was accepted');
            } catch (UnsafeUrl $exception) {
                $this->assertSame('UNSAFE_URL', $exception->errorCode);
            }
        }

        $this->assertSame(
            'https://ipv6-global.example/',
            (string) $policy->assertExternal('https://ipv6-global.example/', ['ipv6-global.example']),
        );

        $this->expectException(UnsafeUrl::class);
        $policy->assertExternal('https://ipv6-global.example/#', ['ipv6-global.example']);
    }

    public function test_url_policy_rejects_special_use_ipv4_and_ipv6_answers(): void
    {
        $answers = [
            'as112.example' => '192.31.196.1',
            'nat64.example' => '100::1',
            'site-local.example' => 'fec0::1',
            'orchid-v2.example' => '2001:20::1',
            'documentation-v2.example' => '3fff::1',
            'nat64-private.example' => '64:ff9b::7f00:1',
        ];
        $this->bindDns(array_map(static fn (string $answer): array => [$answer], $answers));
        $policy = app(UrlPolicy::class);

        foreach ($answers as $host => $answer) {
            try {
                $policy->assertExternal('https://'.$host.'/', [$host]);
                $this->fail('special-use address was accepted: '.$answer);
            } catch (UnsafeUrl $exception) {
                $this->assertSame('UNSAFE_URL', $exception->errorCode);
            }
        }
    }

    public function test_safe_http_rechecks_relative_and_cross_host_redirects_without_following_automatically(): void
    {
        $this->bindDns([
            'safe.example' => ['8.8.8.8'],
        ]);
        Http::fake([
            'https://safe.example/start' => Http::response('', 302, ['Location' => '/next']),
            'https://safe.example/next' => Http::response('ok', 200),
        ]);

        $this->assertSame('ok', app(SafeHttpClient::class)->getText('https://safe.example/start', ['safe.example']));
        Http::assertSentCount(2);
    }

    public function test_safe_http_rejects_private_redirect_before_following_it(): void
    {
        $this->bindDns([
            'safe.example' => ['8.8.8.8'],
            'private.example' => ['127.0.0.1'],
        ]);
        Http::fake([
            'https://safe.example/start' => Http::response('', 302, ['Location' => 'https://private.example/admin']),
        ]);
        try {
            app(SafeHttpClient::class)->getText('https://safe.example/start', ['safe.example', 'private.example']);
            $this->fail('Unsafe redirect was accepted');
        } catch (UnsafeUrl $exception) {
            $this->assertSame('UNSAFE_URL', $exception->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_safe_http_follows_at_most_three_redirects(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        Http::fake([
            'https://safe.example/0' => Http::response('', 302, ['Location' => '/1']),
            'https://safe.example/1' => Http::response('', 302, ['Location' => '/2']),
            'https://safe.example/2' => Http::response('', 302, ['Location' => '/3']),
            'https://safe.example/3' => Http::response('done', 200),
        ]);
        $this->assertSame('done', app(SafeHttpClient::class)->getText('https://safe.example/0', ['safe.example']));
        Http::assertSentCount(4);
    }

    public function test_safe_http_rejects_redirect_without_location(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        Http::fake([
            'https://safe.example/0' => Http::response('', 302),
        ]);
        $this->expectException(UnsafeUrl::class);
        app(SafeHttpClient::class)->getText('https://safe.example/0', ['safe.example']);
    }

    public function test_safe_http_rejects_a_fourth_redirect_hop(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        Http::fake([
            'https://safe.example/0' => Http::response('', 302, ['Location' => '/1']),
            'https://safe.example/1' => Http::response('', 302, ['Location' => '/2']),
            'https://safe.example/2' => Http::response('', 302, ['Location' => '/3']),
            'https://safe.example/3' => Http::response('', 302, ['Location' => '/4']),
        ]);

        try {
            app(SafeHttpClient::class)->getText('https://safe.example/0', ['safe.example']);
            $this->fail('fourth redirect hop was accepted');
        } catch (UnsafeUrl $exception) {
            $this->assertSame('UNSAFE_URL', $exception->errorCode);
        }
        Http::assertSentCount(4);
    }

    public function test_safe_http_retries_only_connection_and_server_failures(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return $attempts < 3 ? Http::response('', 503) : Http::response('ok', 200);
        });

        $this->assertSame('ok', app(SafeHttpClient::class)->getText('https://safe.example/retry', ['safe.example']));
        $this->assertSame(3, $attempts);
    }

    public function test_safe_http_does_not_retry_client_failures(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response('', 429);
        });
        try {
            app(SafeHttpClient::class)->getText('https://safe.example/rate-limited', ['safe.example']);
            $this->fail('4xx response was accepted');
        } catch (UnsafeUrl $exception) {
            $this->assertSame(1, $attempts);
            $this->assertStringNotContainsString('429', $exception->getMessage());
        }
    }

    public function test_safe_http_retries_connection_failures(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new ConnectionException('simulated connection failure');
            }

            return Http::response('connected', 200);
        });
        $this->assertSame('connected', app(SafeHttpClient::class)->getText('https://safe.example/connection', ['safe.example']));
        $this->assertSame(3, $attempts);
    }

    public function test_safe_http_caps_body_before_and_during_stream_read_and_requires_json_array(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        Http::fake([
            'https://safe.example/declared-large' => Http::response('small', 200, ['Content-Length' => '1048577']),
            'https://safe.example/large' => Http::response(str_repeat('x', 1048577), 200),
            'https://safe.example/invalid-json' => Http::response('{not-json', 200),
            'https://safe.example/scalar-json' => Http::response('null', 200),
            'https://safe.example/array-json' => Http::response('{"ok":true}', 200),
        ]);

        foreach (['https://safe.example/declared-large', 'https://safe.example/large'] as $url) {
            try {
                app(SafeHttpClient::class)->getText($url, ['safe.example']);
                $this->fail('oversized body was accepted');
            } catch (UnsafeUrl $exception) {
                $this->assertSame('UNSAFE_URL', $exception->errorCode);
                $this->assertStringNotContainsString('xxxx', $exception->getMessage());
            }
        }

        $this->assertSame(['ok' => true], app(SafeHttpClient::class)->getJson('https://safe.example/array-json', ['safe.example']));
        foreach (['https://safe.example/invalid-json', 'https://safe.example/scalar-json'] as $url) {
            $this->expectException(UnsafeUrl::class);
            app(SafeHttpClient::class)->getJson($url, ['safe.example']);
        }
    }

    public function test_safe_http_accepts_a_body_exactly_at_the_one_mebibyte_limit(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        $body = str_repeat('x', 1048576);
        Http::fake([
            'https://safe.example/exact-limit' => Http::response($body, 200, ['Content-Length' => '1048576']),
        ]);

        $this->assertSame($body, app(SafeHttpClient::class)->getText('https://safe.example/exact-limit', ['safe.example']));
    }

    public function test_safe_http_sets_three_second_connect_eight_second_total_timeout_and_redirect_off(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        $observed = null;
        Http::fake(function ($request, array $options) use (&$observed) {
            $observed = $options;

            return Http::response('ok', 200);
        });

        $this->assertSame('ok', app(SafeHttpClient::class)->getText('https://safe.example/options', ['safe.example']));
        $this->assertSame(3, $observed['connect_timeout']);
        $this->assertSame(8, $observed['timeout']);
        $this->assertFalse($observed['allow_redirects']);
        $this->assertTrue($observed['stream']);
    }

    public function test_safe_http_aborts_a_chunked_style_body_without_content_length_after_one_mebibyte(): void
    {
        $this->bindDns(['safe.example' => ['8.8.8.8']]);
        Http::fake([
            'https://safe.example/chunked-large' => Http::response(
                str_repeat('x', 1048577),
                200,
                ['Transfer-Encoding' => 'chunked'],
            ),
        ]);

        try {
            app(SafeHttpClient::class)->getText('https://safe.example/chunked-large', ['safe.example']);
            $this->fail('chunked-style oversized body was accepted');
        } catch (UnsafeUrl $exception) {
            $this->assertSame('UNSAFE_URL', $exception->errorCode);
            $this->assertStringNotContainsString('xxxx', $exception->getMessage());
        }
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
