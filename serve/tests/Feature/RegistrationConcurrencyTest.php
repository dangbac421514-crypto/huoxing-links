<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VipLogs;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Tests\CreatesApplication;
use Tests\Support\TestEnvironmentGuard;

final class RegistrationConcurrencyTest extends BaseTestCase
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
        $this->assertSame(0, User::query()->count(), '并发注册测试不得遗留用户');
        $this->assertSame(0, VipLogs::query()->count(), '并发注册测试不得遗留会员流水');
        parent::tearDown();
    }

    public function test_two_independent_processes_register_the_same_username_once_with_one_trial(): void
    {
        $username = '13800000020';
        $results = $this->runConcurrentWorkers($username);

        $successful = array_values(array_filter($results, static fn (array $result): bool => $result['ok']));
        $failed = array_values(array_filter($results, static fn (array $result): bool => ! $result['ok']));

        $this->assertCount(2, $results, json_encode($results));
        $this->assertCount(1, $successful, json_encode($results));
        $this->assertCount(1, $failed, json_encode($results));
        $this->assertSame('validation', $failed[0]['error_code'], json_encode($results));
        $this->assertSame(1, User::query()->where('username', $username)->count());
        $this->assertSame(1, VipLogs::query()->where('action', 'trial')->count());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentWorkers(string $username): array
    {
        $barrier = storage_path('framework/registration-concurrency-'.Str::uuid()->toString());
        mkdir($barrier, 0700, true);
        $processes = [
            $this->startWorker($username, $barrier, 1),
            $this->startWorker($username, $barrier, 2),
        ];

        try {
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
                    : ['ok' => false, 'error_code' => 'worker_output', 'stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
            }

            return $results;
        } finally {
            foreach (glob($barrier.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($barrier);
        }
    }

    /**
     * @return array{resource: resource, pipes: array<int, resource>}
     */
    private function startWorker(string $username, string $barrier, int $slot): array
    {
        $code = sprintf(
            <<<'PHP'
            if ((string) env('TEST_ENV_SENTINEL') !== 'link_saas_test_wrapper') {
                throw new RuntimeException('Unsafe test environment');
            }
            $barrier = '%s';
            file_put_contents($barrier.'/ready-%d', 'ready');
            $deadline = microtime(true) + 5;
            while (count(glob($barrier.'/ready-*') ?: []) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (count(glob($barrier.'/ready-*') ?: []) < 2) {
                throw new RuntimeException('Registration barrier timed out');
            }
            try {
                app(\App\Services\RegistrationService::class)->register('%s', 'password', null);
                echo json_encode(['ok' => true]);
            } catch (Throwable $exception) {
                echo json_encode([
                    'ok' => false,
                    'error_code' => $exception instanceof \Illuminate\Validation\ValidationException ? 'validation' : get_class($exception),
                    'message' => $exception->getMessage(),
                ]);
            }
            PHP,
            addslashes($barrier),
            $slot,
            addslashes($username),
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
