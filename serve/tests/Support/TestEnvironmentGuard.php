<?php

namespace Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

final class TestEnvironmentGuard
{
    public static function assertSafe(): void
    {
        if ((string) env('TEST_ENV_SENTINEL') !== 'link_saas_test_wrapper') {
            throw new AssertionFailedError('Unsafe test environment: TEST_ENV_SENTINEL');
        }

        $allowed = [
            'APP_ENV' => 'testing',
            'APP_TIMEZONE' => 'Asia/Shanghai',
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
        $dbHost = (string) env('DB_HOST');
        $dbPort = (string) env('DB_PORT');
        if (($dbHost === 'mysql' && $dbPort !== '3306') || ($dbHost !== 'mysql' && $dbPort !== '33067')) {
            throw new AssertionFailedError('Unsafe test environment: DB destination');
        }
        if (! str_ends_with((string) env('DB_DATABASE'), '_test')) {
            throw new AssertionFailedError('Unsafe test environment: DB_DATABASE');
        }
        if ((string) env('DB_TIMEZONE') !== '+08:00') {
            throw new AssertionFailedError('Unsafe test environment: DB_TIMEZONE');
        }
        if (! in_array((string) env('REDIS_HOST'), ['127.0.0.1', 'localhost', 'redis'], true)) {
            throw new AssertionFailedError('Unsafe test environment: REDIS_HOST');
        }
        if (! in_array((string) env('REDIS_PORT'), ['6390', '6379'], true)) {
            throw new AssertionFailedError('Unsafe test environment: REDIS_PORT');
        }
        $redisHost = (string) env('REDIS_HOST');
        $redisPort = (string) env('REDIS_PORT');
        if (($redisHost === 'redis' && $redisPort !== '6379') || ($redisHost !== 'redis' && $redisPort !== '6390')) {
            throw new AssertionFailedError('Unsafe test environment: Redis destination');
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
        if (strlen(trim((string) env('APP_FEEDBACK_HASH_KEY'))) < 32) {
            throw new AssertionFailedError('Unsafe test environment: APP_FEEDBACK_HASH_KEY');
        }
        $visitorTokenKey = base64_decode((string) env('APP_VISITOR_TOKEN_KEY'), true);
        if ($visitorTokenKey === false || strlen($visitorTokenKey) !== 32) {
            throw new AssertionFailedError('Unsafe test environment: APP_VISITOR_TOKEN_KEY');
        }
    }
}
