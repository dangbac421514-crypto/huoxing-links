<?php

namespace Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

final class TestEnvironmentGuard
{
    public static function assertSafe(): void
    {
        $allowed = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'CACHE_STORE' => 'array',
            'CACHE_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];

        foreach ($allowed as $key => $expected) {
            if ((string) env($key) !== $expected) {
                throw new AssertionFailedError("Unsafe test environment: {$key}");
            }
        }

        if (! in_array((string) env('DB_HOST'), ['127.0.0.1', 'localhost', 'mysql'], true)) {
            throw new AssertionFailedError('Unsafe test environment: DB_HOST');
        }
        if (! in_array((string) env('DB_PORT'), ['33067', '3306'], true)) {
            throw new AssertionFailedError('Unsafe test environment: DB_PORT');
        }
        if (! str_ends_with((string) env('DB_DATABASE'), '_test')) {
            throw new AssertionFailedError('Unsafe test environment: DB_DATABASE');
        }
        if (! in_array((string) env('REDIS_HOST'), ['127.0.0.1', 'localhost', 'redis'], true)) {
            throw new AssertionFailedError('Unsafe test environment: REDIS_HOST');
        }
        if (! in_array((string) env('REDIS_PORT'), ['6390', '6379'], true)) {
            throw new AssertionFailedError('Unsafe test environment: REDIS_PORT');
        }
        if ((string) env('REDIS_PREFIX') !== 'link_saas_test_' || (string) env('REDIS_DB') !== '14' || (string) env('REDIS_CACHE_DB') !== '15') {
            throw new AssertionFailedError('Unsafe test environment: Redis database or prefix');
        }
        if (trim((string) env('APP_KEY')) === '') {
            throw new AssertionFailedError('Unsafe test environment: APP_KEY');
        }
        if (trim((string) env('APP_VISITOR_HASH_KEY')) === '') {
            throw new AssertionFailedError('Unsafe test environment: APP_VISITOR_HASH_KEY');
        }
        $visitorTokenKey = base64_decode((string) env('APP_VISITOR_TOKEN_KEY'), true);
        if ($visitorTokenKey === false || strlen($visitorTokenKey) !== 32) {
            throw new AssertionFailedError('Unsafe test environment: APP_VISITOR_TOKEN_KEY');
        }
    }
}
