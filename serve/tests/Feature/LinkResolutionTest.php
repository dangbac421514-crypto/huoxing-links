<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Link;
use App\Models\LinkVisitLog;
use App\Models\UsagePeriod;
use App\Services\UsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class LinkResolutionTest extends TestCase
{
    use CreatesLinkFixtures;

    public function test_schema_keeps_links_without_an_expiry_gate_and_sanitizes_visit_logs(): void
    {
        $this->assertTrue(Schema::hasColumns('links', ['manual_status', 'health_status']));
        $this->assertTrue(Schema::hasColumns('link_visit_logs', ['visitor_hash', 'ip_hash', 'user_agent_hash']));
        $this->assertTrue(Schema::hasColumns('links', ['status', 'expired_at']));
        $this->assertTrue(Schema::hasColumns('link_visit_logs', ['ip', 'device_uid', 'cache']));
        $this->assertSame(0, DB::table('links')->whereNotNull('expired_at')->count());
    }

    public function test_link_models_cast_new_status_fields_and_reject_legacy_identity_mass_assignment(): void
    {
        $link = new Link(['manual_status' => 0, 'health_status' => 1]);
        $this->assertFalse($link->manual_status);
        $this->assertTrue($link->health_status);

        $log = new LinkVisitLog([
            'link_id' => 1,
            'user_id' => 2,
            'visitor_hash' => str_repeat('a', 64),
            'ip_hash' => str_repeat('b', 64),
            'user_agent_hash' => str_repeat('c', 64),
            'cache' => ['target' => 'https://example.test'],
            'ip' => '192.0.2.1',
            'device_uid' => 'client-supplied-device',
        ]);

        $this->assertSame(['link_id', 'user_id', 'visitor_hash', 'ip_hash', 'user_agent_hash', 'cache'], $log->getFillable());
        $this->assertFalse($log->isFillable('ip'));
        $this->assertFalse($log->isFillable('device_uid'));
        $this->assertArrayNotHasKey('ip', $log->getAttributes());
        $this->assertArrayNotHasKey('device_uid', $log->getAttributes());
        $this->assertSame('https://example.test', $log->cache['target']);
    }

    public function test_upgrade_copies_legacy_status_clears_expiry_and_preserves_legacy_columns(): void
    {
        $migrationPath = database_path('migrations/2026_09_01_000101_link_resolution_stability.php');
        $migration = require $migrationPath;
        $migration->down();

        $legacyLinkId = DB::table('links')->insertGetId([
            'user_id' => 1,
            'title' => 'Legacy link',
            'type' => 1,
            'status' => 0,
            'icon' => '',
            'description' => null,
            'remark' => null,
            'code' => 'legacy-upgrade',
            'config' => json_encode([]),
            'price' => 0,
            'expired_at' => '2030-01-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $upgraded = DB::table('links')->where('id', $legacyLinkId)->first();
        $this->assertSame(0, (int) $upgraded->manual_status);
        $this->assertSame(1, (int) $upgraded->health_status);
        $this->assertNull($upgraded->expired_at);
        $this->assertTrue(Schema::hasColumns('links', ['status', 'expired_at']));
        $this->assertTrue(Schema::hasColumns('link_visit_logs', ['ip', 'device_uid', 'cache']));
    }

    public function test_task_one_indexes_cover_status_and_sanitized_visit_lookup(): void
    {
        $linkIndexes = Schema::getIndexes('links');
        $visitIndexes = Schema::getIndexes('link_visit_logs');

        $this->assertTrue(collect($linkIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['user_id', 'manual_status'],
        ));
        $this->assertTrue(collect($visitIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['link_id', 'visitor_hash'],
        ));
        $this->assertTrue(collect($visitIndexes)->contains(
            fn (array $index): bool => $index['columns'] === ['link_id', 'created_at'],
        ));
    }

    public function test_account_uv_passes_raw_server_visitor_once_to_foundation_meter(): void
    {
        $user = $this->activeMemberWithUvLimit(1);
        $at = CarbonImmutable::now('Asia/Shanghai');
        $meter = app(UsageMeter::class);
        $visitorId = (string) Str::uuid();
        $otherVisitorId = (string) Str::uuid();

        $meter->consume($user, $visitorId, $at);
        $meter->consume($user, $visitorId, $at);

        try {
            $meter->consume($user, $otherVisitorId, $at);
            $this->fail('a different visitor must be rejected at the full account quota');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('QUOTA_EXCEEDED', $exception->errorCode);
        }

        $period = UsagePeriod::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1, (int) $period->used_uv);
        $this->assertDatabaseHas('usage_visitors', [
            'usage_period_id' => $period->id,
            'visitor_hash' => hash('sha256', $visitorId),
        ]);
        $this->assertDatabaseMissing('usage_visitors', [
            'usage_period_id' => $period->id,
            'visitor_hash' => hash('sha256', $otherVisitorId),
        ]);
    }
}
