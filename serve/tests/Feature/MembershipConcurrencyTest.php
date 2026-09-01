<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Models\VipLogs;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\CreatesApplication;
use Tests\Support\TestEnvironmentGuard;

final class MembershipConcurrencyTest extends BaseTestCase
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
        Date::setTestNow();
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])->run();
        parent::tearDown();
    }

    public function test_two_independent_processes_same_user_key_create_one_membership_mutation(): void
    {
        $member = User::factory()->create(['type' => UserType::MEMBER, 'status' => true]);
        $admin = User::factory()->create(['type' => UserType::Admin, 'status' => true]);
        $key = '00000000-0000-0000-0000-000000000021';

        $results = $this->runConcurrentWorkers([$member->id, $member->id], $admin->id, $key);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertTrue($results[1]['ok'], json_encode($results));
        $this->assertSame(1, VipLogs::query()->where('user_id', $member->id)->where('idempotency_key', $key)->count());
        $this->assertSame(2, $member->refresh()->vip_id);
    }

    public function test_two_independent_processes_cross_user_key_collision_is_rejected_safely(): void
    {
        $firstMember = User::factory()->create(['type' => UserType::MEMBER, 'status' => true]);
        $secondMember = User::factory()->create(['type' => UserType::MEMBER, 'status' => true]);
        $admin = User::factory()->create(['type' => UserType::Admin, 'status' => true]);
        $key = '00000000-0000-0000-0000-000000000022';

        $results = $this->runConcurrentWorkers([$firstMember->id, $secondMember->id], $admin->id, $key);

        $this->assertCount(2, $results);
        $successful = array_values(array_filter($results, static fn (array $result): bool => $result['ok']));
        $failed = array_values(array_filter($results, static fn (array $result): bool => ! $result['ok']));
        $this->assertCount(1, $successful, json_encode($results));
        $this->assertCount(1, $failed, json_encode($results));
        $this->assertSame('IDEMPOTENCY_CONFLICT', $failed[0]['error_code'], json_encode($results));
        $this->assertSame(1, VipLogs::query()->where('idempotency_key', $key)->count());
        $this->assertSame(1, User::query()->whereIn('id', [$firstMember->id, $secondMember->id])->whereNotNull('vip_id')->count());
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentWorkers(array $userIds, int $actorId, string $key): array
    {
        DB::beginTransaction();
        foreach ($userIds as $userId) {
            DB::select('select id from users where id = ? for update', [$userId]);
        }

        $processes = [];
        try {
            foreach ($userIds as $userId) {
                $processes[] = $this->startWorker($userId, $actorId, $key);
            }
            usleep(300000);
            DB::commit();

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
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if (is_resource($process['resource'])) {
                    proc_terminate($process['resource']);
                    proc_close($process['resource']);
                }
            }
        }
    }

    /**
     * @return array{resource: resource, pipes: array<int, resource>}
     */
    private function startWorker(int $userId, int $actorId, string $key): array
    {
        $code = sprintf(
            <<<'PHP'
            if ((string) env('TEST_ENV_SENTINEL') !== 'link_saas_test_wrapper') {
                throw new RuntimeException('Unsafe test environment');
            }
            try {
                $log = app(\App\Services\MembershipService::class)->open(
                    \App\Models\User::query()->findOrFail(%d),
                    \App\Models\VipPackage::query()->findOrFail(2),
                    \App\Models\User::query()->findOrFail(%d),
                    '并发开通',
                    '%s',
                );
                echo json_encode(['ok' => true, 'log_id' => $log->id]);
            } catch (Throwable $exception) {
                echo json_encode([
                    'ok' => false,
                    'error_code' => $exception instanceof \App\Exceptions\BusinessRuleException ? $exception->errorCode : get_class($exception),
                    'message' => $exception->getMessage(),
                ]);
            }
            PHP,
            $userId,
            $actorId,
            addslashes($key),
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
}
