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
        $barrier = tempnam(sys_get_temp_dir(), 'qr-rotation-race-');
        $this->assertIsString($barrier);

        try {
            $results = $this->runConcurrentWorkers($link->id, $barrier);
        } finally {
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }

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
        for ($i = 0; $i < 5; $i++) {
            $processes[] = $this->startWorker($linkId, $barrier);
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
                : ['ok' => false, 'exception' => 'WORKER_OUTPUT', 'stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode];
        }

        return $results;
    }

    /**
     * @return array{resource: resource, pipes: array<int, resource>}
     */
    private function startWorker(int $linkId, string $barrier): array
    {
        $code = sprintf(
            <<<'PHP'
            if ((string) env('TEST_ENV_SENTINEL') !== 'link_saas_test_wrapper') {
                throw new RuntimeException('Unsafe test environment');
            }
            while (!is_file(%s)) { usleep(5000); }
            try {
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
            var_export($barrier, true),
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

    /** @return array<string, mixed>|null */
    private function decodeWorkerOutput(string $stdout): ?array
    {
        $lines = preg_split('/\R/', trim($stdout));
        $json = $lines === false ? '' : trim((string) end($lines));
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
