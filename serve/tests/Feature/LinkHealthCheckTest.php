<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Enums\LinkType;
use App\Services\Health\LinkHealthCheckService;
use App\Services\LinkAccessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class LinkHealthCheckTest extends TestCase
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

    public function test_health_check_records_result_without_touching_manual_status(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT, ['url' => 'https://work.weixin.qq.com/ca/example']);
        $link->update(['manual_status' => false, 'health_status' => false]);
        Http::fake(['https://work.weixin.qq.com/*' => Http::response('', 200)]);

        $this->artisan('app:links-health-check')
            ->expectsOutput('checked=1 failed=0')
            ->assertSuccessful();

        $stored = $link->fresh();
        $this->assertFalse((bool) $stored->manual_status);
        $this->assertTrue((bool) $stored->health_status);
        $this->assertNull($stored->health_error_code);
        $this->assertNotNull($stored->health_checked_at);
        $this->assertFalse(app(LinkAccessPolicy::class)->check($stored, CarbonImmutable::now('Asia/Shanghai'))->allowed);
    }

    public function test_legacy_alias_delegates_to_the_same_health_command(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT);
        Http::fake(['https://work.weixin.qq.com/*' => Http::response('', 200)]);

        $this->artisan('app:chk-link')
            ->expectsOutput('checked=1 failed=0')
            ->assertSuccessful();

        $this->assertTrue((bool) $link->fresh()->health_status);
    }

    public function test_positive_link_id_checks_only_that_link(): void
    {
        $first = $this->linkForType(LinkType::WORK_WECHAT);
        $second = $this->linkForType(LinkType::WORK_WECHAT);
        Http::fake(['https://work.weixin.qq.com/*' => Http::response('', 200)]);

        $this->artisan('app:links-health-check', ['link_id' => $first->id])
            ->expectsOutput('checked=1 failed=0')
            ->assertSuccessful();

        $this->assertNotNull($first->fresh()->health_checked_at);
        $this->assertNull($second->fresh()->health_checked_at);
    }

    public function test_invalid_or_missing_link_id_returns_safe_nonzero_without_configuration_leakage(): void
    {
        foreach (['0', '-1', 'abc', '1.2'] as $id) {
            $this->artisan('app:links-health-check', ['link_id' => $id])
                ->expectsOutput('invalid link_id')
                ->assertExitCode(2);
        }

        $this->artisan('app:links-health-check', ['link_id' => '999999'])
            ->expectsOutput('link not found')
            ->assertExitCode(2);
    }

    public function test_all_mode_continues_after_one_link_checker_failure_and_reports_safe_counts(): void
    {
        $good = $this->linkForType(LinkType::WORK_WECHAT, ['url' => 'https://work.weixin.qq.com/ca/good']);
        $bad = $this->linkForType(LinkType::WORK_WECHAT, ['url' => 'http://work.weixin.qq.com/ca/bad']);
        Http::fake(['https://work.weixin.qq.com/*' => Http::response('', 200)]);

        $this->artisan('app:links-health-check')
            ->expectsOutput('checked=2 failed=1')
            ->assertSuccessful();

        $this->assertTrue((bool) $good->fresh()->health_status);
        $this->assertSame('WORK_WECHAT_HEALTH_FAILED', $bad->fresh()->health_error_code);
    }

    public function test_scheduler_removes_legacy_daily_check_and_schedules_canonical_every_ten_minutes(): void
    {
        $events = app(Schedule::class)->events();
        $healthEvents = collect($events)->filter(fn ($event): bool => str_contains($event->command, 'app:links-health-check'));
        $legacyEvents = collect($events)->filter(fn ($event): bool => str_contains($event->command, 'app:chk-link'));

        $this->assertCount(1, $healthEvents);
        $this->assertCount(0, $legacyEvents);
        $event = $healthEvents->first();
        $this->assertSame('*/10 * * * *', $event->getExpression());
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(9, $event->expiresAt);

        $this->artisan('schedule:list')
            ->expectsOutputToContain('php artisan app:links-health-check')
            ->assertExitCode(0);
    }

    public function test_health_migration_has_only_the_health_index_and_columns(): void
    {
        $indexes = Schema::getIndexes('links');
        $this->assertTrue(collect($indexes)->contains(
            fn (array $index): bool => $index['columns'] === ['health_status', 'health_checked_at'],
        ));
        $this->assertTrue(Schema::hasColumns('links', ['manual_status', 'health_status', 'health_checked_at', 'health_error_code']));
    }

    public function test_health_path_does_not_write_legacy_or_manual_columns(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT);
        $before = DB::table('links')->where('id', $link->id)->first();
        Http::fake(['https://work.weixin.qq.com/*' => Http::response('', 200)]);

        app(LinkHealthCheckService::class)->check($link, CarbonImmutable::now('Asia/Shanghai'));

        $after = DB::table('links')->where('id', $link->id)->first();
        foreach (['manual_status', 'status', 'expired_at', 'updated_at', 'config', 'target_version'] as $field) {
            $this->assertSame($before->{$field}, $after->{$field}, $field.' changed during health check');
        }
    }
}
