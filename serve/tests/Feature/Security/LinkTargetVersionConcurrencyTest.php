<?php

namespace Tests\Feature\Security;

use App\Models\Link;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\CreatesApplication;
use Tests\Support\TestEnvironmentGuard;

/**
 * This test deliberately avoids RefreshDatabase. Workers must load one shared
 * snapshot before the barrier and then write through independent connections.
 */
final class LinkTargetVersionConcurrencyTest extends BaseTestCase
{
    use CreatesApplication, CreatesLinkFixtures;

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

    public function test_two_workers_from_one_snapshot_write_distinct_uuid_revisions(): void
    {
        $link = $this->miniProgramLink();
        $barrier = $this->createBarrierDirectory();

        try {
            $results = $this->runConcurrentWorkers($link->id, $barrier);
        } finally {
            $this->cleanupBarrierDirectory($barrier);
        }

        $this->assertFalse(is_dir($barrier), 'barrier directory must be cleaned after workers finish');
        $this->assertCount(2, $results, json_encode($results));
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
            $this->assertTrue(Str::isUuid((string) $result['old_revision']), json_encode($results));
            $this->assertTrue(Str::isUuid((string) $result['revision']), json_encode($results));
            $this->assertSame(0, $result['exit_code'], json_encode($results));
        }
        $this->assertSame($results[0]['old_revision'], $results[1]['old_revision'], json_encode($results));
        $this->assertNotSame($results[0]['revision'], $results[1]['revision'], json_encode($results));

        $stored = Link::query()->findOrFail($link->id);
        $winning = collect($results)->first(
            fn (array $result): bool => $result['marker'] === data_get($stored->config, 'worker_marker'),
        );
        $this->assertIsArray($winning, json_encode($results));
        $this->assertSame($winning['revision'], $stored->target_version, json_encode($results));
        $this->assertSame($winning['marker'], $stored->title, json_encode($results));
    }

    /** @return array<int, array<string, mixed>> */
    private function runConcurrentWorkers(int $linkId, string $barrier): array
    {
        $processes = [];
        try {
            for ($workerId = 1; $workerId <= 2; $workerId++) {
                $processes[] = $this->startWorker($linkId, $barrier, $workerId);
            }
            $this->assertSame(2, $this->waitForReadyWorkers($barrier), 'release must wait for both workers');
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
                    : ['ok' => false, 'stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
            }

            return $results;
        } finally {
            $this->stopWorkers($processes);
        }
    }

    /** @return array{resource: resource, pipes: array<int, resource>} */
    private function startWorker(int $linkId, string $barrier, int $workerId): array
    {
        $marker = 'worker-'.$workerId;
        $code = sprintf(
            <<<'PHP'
            if ((string) env('TEST_ENV_SENTINEL') !== 'link_saas_test_wrapper') {
                throw new RuntimeException('Unsafe test environment');
            }
            try {
                $link = \App\Models\Link::query()->findOrFail(%d);
                $oldRevision = (string) $link->target_version;
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
                $marker = %s;
                $link->title = $marker;
                $config = is_array($link->config) ? $link->config : [];
                $config['worker_marker'] = $marker;
                $link->config = $config;
                $link->save();
                echo json_encode([
                    'ok' => true,
                    'old_revision' => $oldRevision,
                    'revision' => (string) $link->target_version,
                    'marker' => $marker,
                ]);
            } catch (Throwable $exception) {
                echo json_encode([
                    'ok' => false,
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ]);
            }
            PHP,
            $linkId,
            var_export($barrier.'/ready-'.$workerId, true),
            var_export($barrier.'/release', true),
            var_export($marker, true),
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
        $path = tempnam(sys_get_temp_dir(), 'link-version-race-');
        $this->assertIsString($path);
        unlink($path);
        $this->assertTrue(mkdir($path, 0700));

        return $path;
    }

    private function waitForReadyWorkers(string $barrier): int
    {
        $deadline = microtime(true) + 10.0;
        do {
            $readyCount = 0;
            for ($workerId = 1; $workerId <= 2; $workerId++) {
                if (is_file($barrier.'/ready-'.$workerId)) {
                    $readyCount++;
                }
            }
            if ($readyCount === 2) {
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
        $this->assertTrue(rename($temporary, $barrier.'/release'));
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
