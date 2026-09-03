<?php

namespace Tests\Feature\Security;

use App\Exceptions\QrUnavailable;
use App\Models\Link;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\CreatesApplication;
use Tests\Support\TestEnvironmentGuard;

/**
 * This test deliberately avoids RefreshDatabase. The workers must read the
 * same committed link row while each process owns an independent Redis and
 * database connection.
 */
final class QrRotationConcurrencyTest extends BaseTestCase
{
    use CreatesApplication, CreatesLinkFixtures;

    protected function setUp(): void
    {
        TestEnvironmentGuard::assertSafe();
        parent::setUp();
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])->run();
        Redis::connection('default')->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection('default')->flushdb();
        $this->artisan('migrate:fresh', ['--seed' => true, '--force' => true])->run();
        parent::tearDown();
    }

    public function test_five_independent_php_workers_reserve_exactly_two_codes_at_limit_two(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'concurrent.png', 'uv_limit_num' => 2],
        ]);
        $barrier = $this->createBarrierDirectory();

        try {
            $results = $this->runConcurrentWorkers($link->id, $barrier);
        } finally {
            $this->cleanupBarrierDirectory($barrier);
        }

        $this->assertFalse(is_dir($barrier), 'barrier directory must be cleaned after workers finish');
        $this->assertCount(5, $results, json_encode($results));
        $successful = array_values(array_filter($results, static fn (array $result): bool => $result['ok']));
        $rejected = array_values(array_filter($results, static fn (array $result): bool => ! $result['ok']));
        $this->assertCount(2, $successful, json_encode($results));
        $this->assertCount(3, $rejected, json_encode($results));
        foreach ($successful as $result) {
            $this->assertSame(1, $result['sort'], json_encode($results));
            $this->assertSame(0, $result['exit_code'], json_encode($results));
        }
        foreach ($rejected as $result) {
            $this->assertSame(QrUnavailable::class, $result['exception'], json_encode($results));
            $this->assertSame('QR_UNAVAILABLE', $result['error_code'], json_encode($results));
            $this->assertSame(0, $result['exit_code'], json_encode($results));
        }

        $counterKey = 'link:qr:'.$link->id.':accumulate';
        $this->assertSame('2', (string) Redis::connection('default')->hget($counterKey, '1'));
        $this->assertSame(1, Redis::connection('default')->hlen($counterKey));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runConcurrentWorkers(int $linkId, string $barrier): array
    {
        $processes = [];
        $workerCount = 5;
        try {
            for ($workerId = 1; $workerId <= $workerCount; $workerId++) {
                $processes[] = $this->startWorker($linkId, $barrier, $workerId);
            }
            $readyCount = $this->waitForReadyWorkers($barrier, $workerCount);
            $this->assertSame($workerCount, $readyCount, 'release must wait for every worker ready marker');
            $this->assertFalse(is_file($barrier.'/release'), 'release marker must not pre-exist readiness');
            $this->releaseWorkers($barrier);

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
                    : ['ok' => false, 'exception' => 'WORKER_OUTPUT', 'stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
            }

            return $results;
        } finally {
            $this->stopWorkers($processes);
        }
    }

    /**
     * @return array{resource: resource, pipes: array<int, resource>}
     */
    private function startWorker(int $linkId, string $barrier, int $workerId): array
    {
        $code = sprintf(
            <<<'PHP'
            if ((string) env('TEST_ENV_SENTINEL') !== 'link_saas_test_wrapper') {
                throw new RuntimeException('Unsafe test environment');
            }
            try {
                $readyFile = %s;
                $releaseFile = %s;
                if (!@touch($readyFile)) {
                    throw new RuntimeException('Unable to signal worker readiness');
                }
                $deadline = microtime(true) + 10.0;
                while (!is_file($releaseFile)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Release barrier timeout');
                    }
                    usleep(5000);
                }
                $link = \App\Models\Link::query()->findOrFail(%d);
                $selection = app(\App\Services\QrRotationService::class)->reserve(
                    $link,
                    \Carbon\CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai'),
                );
                echo json_encode(['ok' => true, 'sort' => $selection->sort]);
            } catch (Throwable $exception) {
                echo json_encode([
                    'ok' => false,
                    'exception' => get_class($exception),
                    'error_code' => $exception instanceof \App\Exceptions\LinkResolutionException ? $exception->errorCode : null,
                ]);
            }
            PHP,
            var_export($barrier.'/ready-'.$workerId, true),
            var_export($barrier.'/release', true),
            $linkId,
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

    /** @param array<int, array{resource: resource, pipes: array<int, resource>}> $processes */
    private function stopWorkers(array $processes): void
    {
        foreach ($processes as $process) {
            $resource = $process['resource'];
            if (! is_resource($resource)) {
                continue;
            }

            $status = proc_get_status($resource);
            if ($status['running']) {
                proc_terminate($resource);
            }
            foreach ($process['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($resource);
        }
    }

    private function createBarrierDirectory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qr-rotation-race-');
        $this->assertIsString($path);
        unlink($path);
        $this->assertTrue(mkdir($path, 0700), 'barrier directory must be created');

        return $path;
    }

    private function waitForReadyWorkers(string $barrier, int $workerCount): int
    {
        $deadline = microtime(true) + 10.0;
        do {
            $readyCount = 0;
            for ($workerId = 1; $workerId <= $workerCount; $workerId++) {
                if (is_file($barrier.'/ready-'.$workerId)) {
                    $readyCount++;
                }
            }
            if ($readyCount === $workerCount) {
                return $readyCount;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('Worker readiness barrier timeout');
    }

    private function releaseWorkers(string $barrier): void
    {
        $temporary = tempnam($barrier, 'release-');
        $this->assertIsString($temporary);
        $this->assertTrue(rename($temporary, $barrier.'/release'), 'release marker must be atomic');
    }

    private function cleanupBarrierDirectory(string $barrier): void
    {
        foreach (glob($barrier.'/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
    }

    /** @return array<string, mixed>|null */
    private function decodeWorkerOutput(string $stdout): ?array
    {
        $lines = preg_split('/\R/', trim($stdout));
        $json = $lines === false ? '' : trim((string) end($lines));
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
