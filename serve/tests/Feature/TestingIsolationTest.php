<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use Tests\TestCase;

final class TestingIsolationTest extends TestCase
{
    public function test_test_runtime_uses_the_isolated_allowlist(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('predis', (string) env('REDIS_CLIENT'));
        $this->assertSame('predis', (string) config('database.redis.client'));
        $this->assertNotSame('', (string) env('APP_KEY'));
        $this->assertNotSame('', (string) env('APP_VISITOR_HASH_KEY'));
        $this->assertSame(32, strlen((string) base64_decode((string) env('APP_VISITOR_TOKEN_KEY'), true)));
        $this->assertNotSame('', (string) config('app.key'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertStringEndsWith('_test', config('database.connections.mysql.database'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('14', (string) config('database.redis.default.database'));
        $this->assertSame('15', (string) config('database.redis.cache.database'));
        $this->assertSame('link_saas_test_', (string) config('database.redis.options.prefix'));
    }

    public function test_runtime_does_not_expose_a_default_application_key(): void
    {
        $this->assertNotSame('', (string) config('app.key'));
    }

    public function test_business_rule_exception_exposes_code_status_and_message(): void
    {
        $exception = new BusinessRuleException('membership_required', 'Membership is required.', 403);

        $this->assertSame('membership_required', $exception->errorCode);
        $this->assertSame(403, $exception->status);
        $this->assertSame('Membership is required.', $exception->getMessage());
    }
}
