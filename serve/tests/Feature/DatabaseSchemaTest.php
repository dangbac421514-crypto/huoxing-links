<?php

namespace Tests\Feature;

use App\Models\MembershipChange;
use App\Models\UsagePeriod;
use App\Models\UsageVisitor;
use App\Models\User;
use App\Models\VipLogs;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DatabaseSchemaTest extends TestCase
{
    public function test_foundation_tables_and_constraints_exist(): void
    {
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertTrue(Schema::hasTable('membership_changes'));
        $this->assertTrue(Schema::hasTable('usage_periods'));
        $this->assertTrue(Schema::hasTable('usage_visitors'));
        $this->assertTrue(Schema::hasColumns('vip_logs', [
            'actor_user_id', 'action', 'reason', 'before_snapshot',
            'after_snapshot', 'effective_at', 'idempotency_key',
        ]));
        $this->assertTrue(Schema::hasColumn('users', 'must_change_password'));

        $this->assertHasUniqueIndex('vip_logs', ['idempotency_key']);
        $this->assertHasUniqueIndex('membership_changes', ['idempotency_key']);
        $this->assertHasUniqueIndex('usage_periods', ['user_id', 'period_start']);
        $this->assertHasUniqueIndex('usage_visitors', ['usage_period_id', 'visitor_hash']);
    }

    public function test_database_rejects_duplicate_usage_period_key(): void
    {
        $user = User::factory()->create();
        $periodStart = now()->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonth();

        UsagePeriod::query()->create([
            'user_id' => $user->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
        $this->expectException(QueryException::class);
        UsagePeriod::query()->create([
            'user_id' => $user->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    public function test_database_rejects_duplicate_usage_visitor_key(): void
    {
        $user = User::factory()->create();
        $period = UsagePeriod::query()->create([
            'user_id' => $user->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->startOfMonth()->addMonth(),
        ]);
        UsageVisitor::query()->create([
            'usage_period_id' => $period->id,
            'visitor_hash' => hash('sha256', 'visitor'),
            'first_seen_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        UsageVisitor::query()->create([
            'usage_period_id' => $period->id,
            'visitor_hash' => hash('sha256', 'visitor'),
            'first_seen_at' => now(),
        ]);
    }

    public function test_database_rejects_duplicate_vip_log_idempotency_key(): void
    {
        $user = User::factory()->create();
        VipLogs::query()->create([
            'user_id' => $user->id,
            'status' => 1,
            'start_at' => now(),
            'end_at' => now()->addMonth(),
            'idempotency_key' => 'vip-log-key',
        ]);

        $this->expectException(QueryException::class);
        VipLogs::query()->create([
            'user_id' => $user->id,
            'status' => 1,
            'start_at' => now(),
            'end_at' => now()->addMonth(),
            'idempotency_key' => 'vip-log-key',
        ]);
    }

    public function test_models_expose_the_membership_and_usage_contracts(): void
    {
        $membershipChange = new MembershipChange;
        $usagePeriod = new UsagePeriod;
        $usageVisitor = new UsageVisitor;
        $vipLog = new VipLogs;

        $this->assertInstanceOf(BelongsTo::class, $membershipChange->actor());
        $this->assertInstanceOf(BelongsTo::class, $membershipChange->user());
        $this->assertInstanceOf(BelongsTo::class, $membershipChange->fromPackage());
        $this->assertInstanceOf(BelongsTo::class, $membershipChange->toPackage());
        $this->assertInstanceOf(HasMany::class, $usagePeriod->visitors());
        $this->assertInstanceOf(BelongsTo::class, $usageVisitor->period());
        $this->assertInstanceOf(BelongsTo::class, $vipLog->actor());
        $this->assertInstanceOf(BelongsTo::class, $vipLog->user());
        $this->assertInstanceOf(BelongsTo::class, $vipLog->vipPackage());

        $this->assertSame('datetime', $membershipChange->getCasts()['effective_at']);
        $this->assertSame('datetime', $usagePeriod->getCasts()['period_start']);
        $this->assertSame('datetime', $usagePeriod->getCasts()['period_end']);
        $this->assertSame('datetime', $usageVisitor->getCasts()['first_seen_at']);
        $this->assertSame('datetime', $vipLog->getCasts()['effective_at']);
        $this->assertSame('array', $vipLog->getCasts()['before_snapshot']);
        $this->assertSame('array', $vipLog->getCasts()['after_snapshot']);
        $this->assertSame('boolean', (new User)->getCasts()['must_change_password']);
    }

    public function test_user_factory_uses_real_columns_and_preserves_supplied_referral_code(): void
    {
        $user = User::factory()->make(['referral_code' => 'CUSTOM01']);

        $this->assertSame('CUSTOM01', $user->referral_code);
        $this->assertArrayHasKey('username', $user->getAttributes());
        $this->assertArrayHasKey('password', $user->getAttributes());
        $this->assertArrayHasKey('status', $user->getAttributes());
        $this->assertArrayHasKey('type', $user->getAttributes());
        $this->assertArrayNotHasKey('name', $user->getAttributes());
        $this->assertArrayNotHasKey('email', $user->getAttributes());
        $this->assertContains('password', $user->getHidden());
        $this->assertContains('remember_token', $user->getHidden());
        $this->assertContains('tokens', $user->getHidden());
    }

    private function assertHasUniqueIndex(string $table, array $columns): void
    {
        $indexes = Schema::getIndexes($table);

        $this->assertTrue(collect($indexes)->contains(
            fn (array $index): bool => $index['unique'] && $index['columns'] === $columns,
        ), "Missing unique index on {$table} (".implode(', ', $columns).')');
    }
}
