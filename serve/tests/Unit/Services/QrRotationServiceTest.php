<?php

namespace Tests\Unit\Services;

use App\DTO\QrSelection;
use App\Enums\LinkType;
use App\Enums\UVLimitType;
use App\Exceptions\QrUnavailable;
use App\Models\Link;
use App\Services\QrRotationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class QrRotationServiceTest extends TestCase
{
    use CreatesLinkFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection('default')->flushdb();
        Redis::connection('cache')->flushdb();
        parent::tearDown();
    }

    public function test_rotation_sequence_skips_expired_and_full_codes_and_advances_zero_based_cursor(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'old.png', 'expired_at' => '2026-09-01', 'uv_limit_num' => 10],
            ['sort' => 2, 'path' => 'full.png', 'name' => 'full', 'expired_at' => '2026-09-03', 'uv_limit_num' => 1],
            ['sort' => 3, 'path' => 'next.png', 'name' => 'next', 'expired_at' => '2026-09-03', 'uv_limit_num' => 10],
        ]);
        $counterKey = $this->counterKey($link, 'accumulate');
        $cursorKey = $this->cursorKey($link, 'accumulate');
        Redis::connection('default')->hset($counterKey, '2', 1);

        $selection = app(QrRotationService::class)->reserve($link, $this->at('2026-09-02 12:00:00'));

        $this->assertInstanceOf(QrSelection::class, $selection);
        $this->assertSame(3, $selection->sort);
        $this->assertSame('next.png', $selection->path);
        $this->assertSame('next', $selection->name);
        $this->assertSame('1', (string) Redis::connection('default')->hget($counterKey, '3'));
        $this->assertSame('1', (string) Redis::connection('default')->hget($counterKey, '2'));
        $this->assertSame('2', (string) Redis::connection('default')->get($cursorKey));
    }

    public function test_rotation_sequence_wraps_after_checking_each_slot_once(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 0, 'path' => 'zero.png', 'uv_limit_num' => 3],
            ['sort' => 5, 'path' => 'five.png', 'uv_limit_num' => 3],
        ]);
        $service = app(QrRotationService::class);
        $at = $this->at('2026-09-02 12:00:00');

        $first = $service->reserve($link, $at);
        $second = $service->reserve($link, $at);
        $third = $service->reserve($link, $at);

        $this->assertSame(0, $first->sort);
        $this->assertSame('zero.png', $first->path);
        $this->assertSame(5, $second->sort);
        $this->assertSame(0, $third->sort);
    }

    public function test_rotation_random_mode_only_returns_eligible_candidates(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 0, 'path' => 'expired.png', 'expired_at' => '2026-09-01', 'uv_limit_num' => 10],
            ['sort' => 1, 'path' => 'full.png', 'expired_at' => '2026-09-03', 'uv_limit_num' => 1],
            ['sort' => 2, 'path' => 'random-a.png', 'expired_at' => '2026-09-03', 'uv_limit_num' => 20],
            ['sort' => 3, 'path' => 'random-b.png', 'expired_at' => '2026-09-03', 'uv_limit_num' => 20],
        ]);
        $config = $link->config;
        $config['wx']['switch_type'] = 2;
        $link->config = $config;
        $link->save();
        Redis::connection('default')->hset($this->counterKey($link, 'accumulate'), '1', 1);

        $sorts = [];
        for ($i = 0; $i < 12; $i++) {
            $sorts[] = app(QrRotationService::class)->reserve($link->fresh(), $this->at('2026-09-02 12:00:00'))->sort;
        }

        foreach ($sorts as $sort) {
            $this->assertContains($sort, [2, 3]);
        }
        $this->assertSame('1', (string) Redis::connection('default')->hget($this->counterKey($link, 'accumulate'), '1'));
        $this->assertNull(Redis::connection('default')->hget($this->counterKey($link, 'accumulate'), '0'));
    }

    public function test_rotation_daily_key_uses_supplied_instant_in_shanghai_and_sets_both_ttls(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'daily.png', 'uv_limit_num' => 3],
        ]);
        $config = $link->config;
        $config['wx']['uv_limit_type'] = UVLimitType::DAILY->value;
        $link->config = $config;
        $link->save();
        $at = CarbonImmutable::parse('2026-09-01 16:00:00', 'UTC');
        $dailyKey = $this->counterKey($link, 'daily:20260902');
        $cursorKey = $this->cursorKey($link, 'daily:20260902');

        // A value in the cache DB must not affect the default QR counter.
        Redis::connection('cache')->hset($dailyKey, '1', 99);
        $selection = app(QrRotationService::class)->reserve($link->fresh(), $at);

        $this->assertSame(1, $selection->sort);
        $this->assertSame('1', (string) Redis::connection('default')->hget($dailyKey, '1'));
        $this->assertSame('99', (string) Redis::connection('cache')->hget($dailyKey, '1'));
        $counterTtl = Redis::connection('default')->ttl($dailyKey);
        $cursorTtl = Redis::connection('default')->ttl($cursorKey);
        $this->assertGreaterThan(0, $counterTtl);
        $this->assertLessThanOrEqual(172800, $counterTtl);
        $this->assertGreaterThan(0, $cursorTtl);
        $this->assertLessThanOrEqual(172800, $cursorTtl);
        $this->assertNull(Redis::connection('default')->hget($this->counterKey($link, 'daily:20260901'), '1'));
    }

    public function test_rotation_keys_use_the_configured_prefix_and_never_include_qr_payload(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'private/raw/path.png', 'name' => 'private-name'],
        ]);
        app(QrRotationService::class)->reserve($link, $this->at('2026-09-02 12:00:00'));

        $prefix = (string) Redis::connection('default')->client()->getOptions()->prefix;
        $keys = Redis::connection('default')->keys('*');

        $this->assertContains($prefix.'link:qr:'.$link->id.':accumulate', $keys);
        $this->assertContains($prefix.'link:qr:'.$link->id.':cursor:accumulate', $keys);
        $this->assertStringNotContainsString('private/raw/path.png', implode('\n', $keys));
        $this->assertStringNotContainsString('private-name', implode('\n', $keys));
    }

    public function test_rotation_cumulative_keys_have_no_ttl_and_forget_keeps_daily_bucket(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'persistent.png', 'uv_limit_num' => 3],
        ]);
        $service = app(QrRotationService::class);
        $service->reserve($link, $this->at('2026-09-02 12:00:00'));
        $dailyKey = $this->counterKey($link, 'daily:20260902');
        Redis::connection('default')->hset($dailyKey, '1', 1);
        Redis::connection('default')->expire($dailyKey, 172800);

        $this->assertSame(-1, Redis::connection('default')->ttl($this->counterKey($link, 'accumulate')));
        $this->assertSame(-1, Redis::connection('default')->ttl($this->cursorKey($link, 'accumulate')));

        $service->forget($link);

        $this->assertSame(0, Redis::connection('default')->exists($this->counterKey($link, 'accumulate')));
        $this->assertSame(0, Redis::connection('default')->exists($this->cursorKey($link, 'accumulate')));
        $this->assertSame(1, Redis::connection('default')->exists($dailyKey));
        $this->assertGreaterThan(0, Redis::connection('default')->ttl($dailyKey));
    }

    public function test_rotation_treats_end_of_day_as_inclusive_and_next_midnight_as_expired(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'end-of-day.png', 'expired_at' => '2026-09-02', 'uv_limit_num' => 2],
        ]);
        $service = app(QrRotationService::class);

        $selection = $service->reserve($link, $this->at('2026-09-02 23:59:59'));
        $this->assertSame(1, $selection->sort);

        try {
            $service->reserve($link->fresh(), $this->at('2026-09-03 00:00:00'));
            $this->fail('the exact next-day boundary must be expired');
        } catch (QrUnavailable $exception) {
            $this->assertSame('QR_UNAVAILABLE', $exception->errorCode);
        }
        $this->assertSame('1', (string) Redis::connection('default')->hget($this->counterKey($link, 'accumulate'), '1'));
    }

    public function test_rotation_no_eligible_code_returns_stable_error_without_mutating_redis_state(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'expired-secret.png', 'expired_at' => '2026-09-01', 'uv_limit_num' => 1],
            ['sort' => 2, 'path' => 'full-secret.png', 'uv_limit_num' => 1],
        ]);
        $counterKey = $this->counterKey($link, 'accumulate');
        $cursorKey = $this->cursorKey($link, 'accumulate');
        Redis::connection('default')->hset($counterKey, '2', 1);
        Redis::connection('default')->set($cursorKey, 1);

        try {
            app(QrRotationService::class)->reserve($link, $this->at('2026-09-02 12:00:00'));
            $this->fail('no eligible code must fail closed');
        } catch (QrUnavailable $exception) {
            $this->assertSame('QR_UNAVAILABLE', $exception->errorCode);
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }

        $this->assertSame('1', (string) Redis::connection('default')->hget($counterKey, '2'));
        $this->assertSame('1', (string) Redis::connection('default')->get($cursorKey));
        $this->assertSame(1, Redis::connection('default')->hlen($counterKey));
    }

    public function test_rotation_missing_null_and_empty_limits_are_unlimited_but_invalid_limits_fail_closed(): void
    {
        foreach ([null, ''] as $limit) {
            $link = $this->landingLinkWithQrs([
                ['sort' => 1, 'path' => 'unlimited.png', 'uv_limit_num' => $limit],
            ]);
            $service = app(QrRotationService::class);
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame(1, $service->reserve($link->fresh(), $this->at('2026-09-02 12:00:00'))->sort);
            }
            $this->assertSame('3', (string) Redis::connection('default')->hget($this->counterKey($link, 'accumulate'), '1'));
            Redis::connection('default')->flushdb();
        }

        foreach ([0, -1, 1.5, '1foo', true] as $limit) {
            $link = $this->landingLinkWithQrs([
                ['sort' => 1, 'path' => 'invalid-limit.png', 'uv_limit_num' => $limit],
            ]);
            try {
                app(QrRotationService::class)->reserve($link, $this->at('2026-09-02 12:00:00'));
                $this->fail('invalid QR limit was accepted: '.var_export($limit, true));
            } catch (QrUnavailable $exception) {
                $this->assertSame('QR_UNAVAILABLE', $exception->errorCode);
            }
            $this->assertSame(0, Redis::connection('default')->exists($this->counterKey($link, 'accumulate')));
            $this->assertSame(0, Redis::connection('default')->exists($this->cursorKey($link, 'accumulate')));
        }
    }

    public function test_rotation_rejects_duplicate_sort_invalid_path_and_noncanonical_expiry(): void
    {
        $cases = [
            [
                ['sort' => 1, 'path' => 'a.png'],
                ['sort' => 1, 'path' => 'b.png'],
            ],
            [
                ['sort' => 1, 'path' => ''],
            ],
            [
                ['sort' => 1, 'path' => 'bad-date.png', 'expired_at' => '2026-9-02'],
            ],
            [
                ['sort' => 1, 'path' => 'invalid-date.png', 'expired_at' => '2026-02-30'],
            ],
            [
                ['sort' => 1.5, 'path' => 'fractional-sort.png'],
            ],
            [
                ['sort' => '1foo', 'path' => 'junk-sort.png'],
            ],
        ];

        foreach ($cases as $qrs) {
            $link = $this->landingLinkWithQrs($qrs);
            try {
                app(QrRotationService::class)->reserve($link, $this->at('2026-09-02 12:00:00'));
                $this->fail('malformed QR configuration was accepted');
            } catch (QrUnavailable $exception) {
                $this->assertSame('QR_UNAVAILABLE', $exception->errorCode);
                $this->assertStringNotContainsString('bad-date', $exception->getMessage());
                $this->assertStringNotContainsString('2026-02-30', $exception->getMessage());
            }
            $this->assertSame(0, Redis::connection('default')->exists($this->counterKey($link, 'accumulate')));
            $this->assertSame(0, Redis::connection('default')->exists($this->cursorKey($link, 'accumulate')));
        }
    }

    public function test_rotation_rejects_empty_invalid_type_and_malformed_switch_configuration(): void
    {
        $invalidLinks = [
            $this->linkForType(LinkType::WORK_WECHAT),
            $this->landingLinkWithQrs([]),
        ];
        $invalidLinks[] = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'bad-switch.png'],
        ]);
        $config = $invalidLinks[2]->config;
        $config['wx']['switch_type'] = 7;
        $invalidLinks[2]->config = $config;
        $invalidLinks[2]->save();

        $invalidLinks[] = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'bad-limit-type.png'],
        ]);
        $config = $invalidLinks[3]->config;
        $config['wx']['uv_limit_type'] = 7;
        $invalidLinks[3]->config = $config;
        $invalidLinks[3]->save();

        foreach ($invalidLinks as $link) {
            try {
                app(QrRotationService::class)->reserve($link->fresh(), $this->at('2026-09-02 12:00:00'));
                $this->fail('malformed QR configuration was accepted');
            } catch (QrUnavailable $exception) {
                $this->assertSame('QR_UNAVAILABLE', $exception->errorCode);
                $this->assertStringNotContainsString('bad-', $exception->getMessage());
            }
        }
    }

    private function at(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'Asia/Shanghai');
    }

    private function counterKey(Link $link, string $scope): string
    {
        return $scope === 'accumulate'
            ? 'link:qr:'.$link->id.':accumulate'
            : 'link:qr:'.$link->id.':daily:'.str_replace('daily:', '', $scope);
    }

    private function cursorKey(Link $link, string $scope): string
    {
        return 'link:qr:'.$link->id.':cursor:'.$scope;
    }
}
