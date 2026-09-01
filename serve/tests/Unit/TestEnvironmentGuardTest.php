<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Env;
use Tests\Support\TestEnvironmentGuard;

final class TestEnvironmentGuardTest extends TestCase
{
    /** @var array<string, array{bool, string|null, string|null, string|null}> */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['DB_HOST', 'DB_PORT', 'REDIS_HOST', 'REDIS_PORT', 'TEST_ENV_SENTINEL'] as $key) {
            $this->environment[$key] = [
                getenv($key) !== false,
                getenv($key) === false ? null : (string) getenv($key),
                array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null,
                array_key_exists($key, $_SERVER) ? (string) $_SERVER[$key] : null,
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => [$wasSet, $value, $envValue, $serverValue]) {
            if ($wasSet) {
                putenv("{$key}={$value}");
            } else {
                putenv($key);
            }

            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }

            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }
        }

        Env::enablePutenv();

        parent::tearDown();
    }

    #[DataProvider('allowedDestinations')]
    public function test_accepts_only_the_explicit_local_or_ci_destination_tuples(
        string $dbHost,
        string $dbPort,
        string $redisHost,
        string $redisPort,
    ): void {
        $this->setEnvironment([
            'DB_HOST' => $dbHost,
            'DB_PORT' => $dbPort,
            'REDIS_HOST' => $redisHost,
            'REDIS_PORT' => $redisPort,
        ]);

        TestEnvironmentGuard::assertSafe();

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function allowedDestinations(): array
    {
        return [
            'local' => ['127.0.0.1', '33067', '127.0.0.1', '6390'],
            'localhost' => ['localhost', '33067', 'localhost', '6390'],
            'ci' => ['mysql', '3306', 'redis', '6379'],
        ];
    }

    #[DataProvider('crossPairedDestinations')]
    public function test_rejects_cross_paired_database_or_redis_destinations(
        string $dbHost,
        string $dbPort,
        string $redisHost,
        string $redisPort,
        string $message,
    ): void {
        $this->setEnvironment([
            'DB_HOST' => $dbHost,
            'DB_PORT' => $dbPort,
            'REDIS_HOST' => $redisHost,
            'REDIS_PORT' => $redisPort,
        ]);

        $exception = null;
        try {
            TestEnvironmentGuard::assertSafe();
        } catch (AssertionFailedError $exception) {
        }

        $this->assertInstanceOf(AssertionFailedError::class, $exception);
        $this->assertSame($message, $exception->getMessage());
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function crossPairedDestinations(): array
    {
        return [
            'local db host with ci db port' => ['127.0.0.1', '3306', '127.0.0.1', '6390', 'Unsafe test environment: DB destination'],
            'ci db host with local db port' => ['mysql', '33067', 'redis', '6379', 'Unsafe test environment: DB destination'],
            'local redis host with ci redis port' => ['127.0.0.1', '33067', '127.0.0.1', '6379', 'Unsafe test environment: Redis destination'],
            'ci redis host with local redis port' => ['127.0.0.1', '33067', 'redis', '6390', 'Unsafe test environment: Redis destination'],
        ];
    }

    public function test_requires_the_wrapper_sentinel(): void
    {
        $this->setEnvironment(['TEST_ENV_SENTINEL' => '']);

        $exception = null;
        try {
            TestEnvironmentGuard::assertSafe();
        } catch (AssertionFailedError $exception) {
        }

        $this->assertInstanceOf(AssertionFailedError::class, $exception);
        $this->assertSame('Unsafe test environment: TEST_ENV_SENTINEL', $exception->getMessage());
    }

    /** @param array<string, string> $values */
    private function setEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        Env::enablePutenv();
    }
}
