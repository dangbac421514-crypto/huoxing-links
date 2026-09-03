<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\UsagePeriod;
use App\Models\UsageVisitor;
use App\Models\User;
use App\Models\VipPackage;
use App\Services\UsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Date;
use Tests\CreatesApplication;
use Tests\Support\TestEnvironmentGuard;
use Tests\TestCase;

final class UsageMeterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Date::setTestNow($this->at());
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_same_visitor_across_two_links_counts_once_and_full_repeat_is_allowed(): void
    {
        $member = $this->memberWithUvLimit(2);
        $at = $this->at();
        $meter = app(UsageMeter::class);

        $meter->consume($member, 'visitor-a', $at);
        $meter->consume($member, 'visitor-a', $at);
        $meter->consume($member, 'visitor-b', $at);
        $meter->consume($member, 'visitor-a', $at);

        $period = UsagePeriod::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame(2, (int) $period->used_uv);
        $this->assertSame(2, UsageVisitor::query()->where('usage_period_id', $period->id)->count());
        $this->assertDatabaseHas('usage_visitors', [
            'usage_period_id' => $period->id,
            'visitor_hash' => hash('sha256', 'visitor-a'),
        ]);
    }

    public function test_new_visitor_at_full_quota_is_rejected_without_rows_or_increment(): void
    {
        $member = $this->memberWithUvLimit(1);
        $at = $this->at();
        $meter = app(UsageMeter::class);
        $meter->consume($member, 'visitor-a', $at);

        try {
            $meter->consume($member, 'visitor-new', $at);
            $this->fail('满额的新访客必须被拒绝');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('QUOTA_EXCEEDED', $exception->errorCode);
        }

        $period = UsagePeriod::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame(1, (int) $period->used_uv);
        $this->assertSame(1, UsageVisitor::query()->where('usage_period_id', $period->id)->count());
        $this->assertDatabaseMissing('usage_visitors', [
            'usage_period_id' => $period->id,
            'visitor_hash' => hash('sha256', 'visitor-new'),
        ]);
    }

    public function test_empty_visitor_id_is_rejected(): void
    {
        $member = $this->memberWithUvLimit(2);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('访客标识不能为空');
        app(UsageMeter::class)->consume($member, '', $this->at());
    }

    public function test_empty_visitor_id_is_rejected_for_admin_without_usage_rows(): void
    {
        $admin = User::factory()->create(['type' => 3]);

        try {
            app(UsageMeter::class)->consume($admin, '', $this->at());
            $this->fail('管理员也不能使用空访客标识');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('INVALID_VISITOR', $exception->errorCode);
        }

        $this->assertDatabaseCount('usage_periods', 0);
    }

    public function test_admin_does_not_create_usage_rows(): void
    {
        $admin = User::factory()->create(['type' => 3]);

        app(UsageMeter::class)->consume($admin, 'admin-visitor', $this->at());
        $this->assertDatabaseCount('usage_periods', 0);
    }

    public function test_zero_uv_limit_allows_new_visitors_without_a_quota_error(): void
    {
        $member = $this->memberWithUvLimit(0);
        $meter = app(UsageMeter::class);

        foreach (range(1, 3) as $number) {
            $meter->consume($member, 'visitor-'.$number, $this->at());
        }

        $period = UsagePeriod::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame(3, (int) $period->used_uv);
    }

    public function test_period_boundary_starts_a_new_period(): void
    {
        $member = User::factory()->create([
            'vip_id' => 2,
            'start_at' => CarbonImmutable::parse('2026-01-31 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-12-31 10:00:00', 'Asia/Shanghai'),
        ]);
        $meter = app(UsageMeter::class);
        $meter->consume($member, 'visitor-before', CarbonImmutable::parse('2026-02-28 09:59:59', 'Asia/Shanghai'));
        $meter->consume($member, 'visitor-after', CarbonImmutable::parse('2026-02-28 10:00:00', 'Asia/Shanghai'));

        $this->assertSame(2, UsagePeriod::query()->where('user_id', $member->id)->count());
        $this->assertSame(2, (int) UsagePeriod::query()->where('user_id', $member->id)->sum('used_uv'));
    }

    public function test_expired_and_none_memberships_have_stable_usage_errors(): void
    {
        $expired = User::factory()->create([
            'vip_id' => 2,
            'start_at' => CarbonImmutable::parse('2026-08-15 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
        ]);
        try {
            app(UsageMeter::class)->consume($expired, 'visitor', $this->at());
            $this->fail('过期会员必须被拒绝');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('MEMBERSHIP_EXPIRED', $exception->errorCode);
        }

        $none = User::factory()->create();
        try {
            app(UsageMeter::class)->consume($none, 'visitor', $this->at());
            $this->fail('无会员权益必须被拒绝');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('NO_ENTITLEMENT', $exception->errorCode);
        }

        $this->assertDatabaseCount('usage_periods', 0);
    }

    public function test_member_dashboard_reads_usage_period_instead_of_visit_logs(): void
    {
        $member = $this->memberWithUvLimit(10);
        $at = $this->at();
        app(UsageMeter::class)->consume($member, 'visitor-a', $at);

        $token = $member->createToken('dashboard')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/home');

        $response->assertOk()->assertJsonPath('used_uv', 1);
    }

    public function test_userinfo_reads_link_limit_from_entitlement_snapshot(): void
    {
        $member = $this->memberWithUvLimit(10);
        $token = $member->createToken('userinfo')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/userinfo');
        $response
            ->assertOk()
            ->assertJsonPath('link_total', 5)
            ->assertJsonPath('link_created', 0)
            ->assertJsonPath('link_amount', 5);
    }

    private function memberWithUvLimit(int $uvLimit): User
    {
        $package = VipPackage::query()->findOrFail(2);
        $config = $package->config;
        $config['uv_limit'] = $uvLimit;
        $package->update(['config' => $config]);

        return User::factory()->create([
            'vip_id' => $package->id,
            'start_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Shanghai'),
        ]);
    }

    private function at(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-20 10:00:00', 'Asia/Shanghai');
    }
}

/**
 * This class intentionally does not use RefreshDatabase. Each worker must
 * open its own process and MySQL connection to prove the row-lock protocol.
 */
class UsageMeterConcurrencySupport extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        TestEnvironmentGuard::assertSafe();
        parent::setUp();
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])->run();
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])->run();
        parent::tearDown();
    }

    public function test_four_independent_mysql_workers_allow_only_two_distinct_visitors_at_limit_two(): void
    {
        $member = $this->memberWithUvLimit(2);
        $barrier = tempnam(sys_get_temp_dir(), 'usage-meter-race-');
        $this->assertIsString($barrier);

        try {
            $results = $this->runConcurrentWorkers($member->id, ['visitor-1', 'visitor-2', 'visitor-3', 'visitor-4'], $barrier);
        } finally {
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }

        $successful = array_values(array_filter($results, static fn (array $result): bool => $result['ok']));
        $rejected = array_values(array_filter($results, static fn (array $result): bool => ! $result['ok']));
        $this->assertCount(2, $successful, json_encode($results));
        $this->assertCount(2, $rejected, json_encode($results));
        foreach ($rejected as $result) {
            $this->assertSame('QUOTA_EXCEEDED', $result['error_code'], json_encode($results));
        }
        $this->assertSame(2, (int) UsagePeriod::query()->where('user_id', $member->id)->value('used_uv'));
        $this->assertSame(2, UsageVisitor::query()->whereHas('period', fn ($query) => $query->where('user_id', $member->id))->count());
    }

    public function test_two_independent_mysql_workers_dedupe_the_same_visitor_for_one_account(): void
    {
        $member = $this->memberWithUvLimit(2);
        $barrier = tempnam(sys_get_temp_dir(), 'usage-meter-dedupe-');
        $this->assertIsString($barrier);

        try {
            $results = $this->runConcurrentWorkers($member->id, ['same-visitor', 'same-visitor'], $barrier);
        } finally {
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->assertSame(1, (int) UsagePeriod::query()->where('user_id', $member->id)->value('used_uv'));
        $this->assertSame(1, UsageVisitor::query()->whereHas('period', fn ($query) => $query->where('user_id', $member->id))->count());
    }

    /**
     * @param  array<int, string>  $visitorIds
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentWorkers(int $userId, array $visitorIds, string $barrier): array
    {
        $processes = [];
        foreach ($visitorIds as $visitorId) {
            $processes[] = $this->startWorker($userId, $visitorId, $barrier);
        }
        file_put_contents($barrier, 'go');

        $results = [];
        foreach ($processes as $process) {
            $stdout = stream_get_contents($process['pipes'][1]);
            $stderr = stream_get_contents($process['pipes'][2]);
            fclose($process['pipes'][0]);
            fclose($process['pipes'][1]);
            fclose($process['pipes'][2]);
            $exitCode = proc_close($process['resource']);
            $decoded = $this->decodeWorkerOutput($stdout);
            $results[] = is_array($decoded)
                ? $decoded + ['exit_code' => $exitCode, 'stderr' => $stderr]
                : ['ok' => false, 'error_code' => 'WORKER_OUTPUT', 'stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
        }

        return $results;
    }

    /**
     * @return array{resource: resource, pipes: array<int, resource>}
     */
    private function startWorker(int $userId, string $visitorId, string $barrier): array
    {
        $code = sprintf(
            <<<'PHP'
            if ((string) env('TEST_ENV_SENTINEL') !== 'link_saas_test_wrapper') {
                throw new RuntimeException('Unsafe test environment');
            }
            while (!is_file(%s)) { usleep(5000); }
            try {
                app(\App\Services\UsageMeter::class)->consume(
                    \App\Models\User::query()->findOrFail(%d),
                    %s,
                    \Carbon\CarbonImmutable::parse('2026-09-20 10:00:00', 'Asia/Shanghai'),
                );
                echo json_encode(['ok' => true]);
            } catch (Throwable $exception) {
                echo json_encode([
                    'ok' => false,
                    'error_code' => $exception instanceof \App\Exceptions\BusinessRuleException ? $exception->errorCode : get_class($exception),
                    'message' => $exception->getMessage(),
                ]);
            }
            PHP,
            var_export($barrier, true),
            $userId,
            var_export($visitorId, true),
        );
        $pipes = [];
        $resource = proc_open(
            [PHP_BINARY, base_path('artisan'), 'tinker', '--execute='.$code],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        $this->assertIsResource($resource);

        return ['resource' => $resource, 'pipes' => $pipes];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeWorkerOutput(string $stdout): ?array
    {
        $lines = preg_split('/\R/', trim($stdout));
        $json = $lines === false ? '' : trim((string) end($lines));
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function memberWithUvLimit(int $uvLimit): User
    {
        $package = VipPackage::query()->findOrFail(2);
        $config = $package->config;
        $config['uv_limit'] = $uvLimit;
        $package->update(['config' => $config]);

        return User::factory()->create([
            'vip_id' => $package->id,
            'start_at' => '2026-09-15 10:00:00',
            'end_at' => '2026-10-15 10:00:00',
        ]);
    }
}
