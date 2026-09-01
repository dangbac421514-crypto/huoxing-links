<?php

namespace Tests\Feature;

use App\Models\MiniProgram;
use App\Models\ProtectedSecretAudit;
use App\Models\User;
use App\Services\SecretConfigService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProtectedSecretAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_secret_write_records_actor_and_system_audits_without_values(): void
    {
        $admin = User::factory()->create([
            'type' => 3,
            'status' => true,
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($admin, ['*'], 'api');

        app(SecretConfigService::class)->set('ali_sms_secret', 'actor-only-secret');
        auth('api')->forgetUser();
        app(SecretConfigService::class)->set('mail_password', 'system-only-secret');

        $audits = ProtectedSecretAudit::query()->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame($admin->id, $audits[0]->actor_user_id);
        $this->assertSame('protected_secret.set', $audits[0]->event_name);
        $this->assertSame('ali_sms_secret', $audits[0]->key_identifier);
        $this->assertSame('secret_config', $audits[0]->source);
        $this->assertNull($audits[1]->actor_user_id);
        $this->assertSame('mail_password', $audits[1]->key_identifier);
        $this->assertStringNotContainsString('actor-only-secret', $audits->toJson());
        $this->assertStringNotContainsString('system-only-secret', $audits->toJson());
        $this->assertStringNotContainsString('actor-only-secret', DB::table('protected_secret_audits')->get()->toJson());
        $this->assertStringNotContainsString('system-only-secret', DB::table('protected_secret_audits')->get()->toJson());
    }

    public function test_rolled_back_secret_write_leaves_no_secret_or_audit_row(): void
    {
        try {
            DB::transaction(function (): void {
                app(SecretConfigService::class)->set('ali_sms_secret', 'rolled-back-secret');
                throw new \RuntimeException('rollback sentinel');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback sentinel', $exception->getMessage());
        }

        $this->assertDatabaseMissing('protected_secret_audits', ['key_identifier' => 'ali_sms_secret']);
        $this->assertDatabaseMissing('sys_configs', ['slug' => 'ali_sms_secret', 'value' => 'enc:v1:'.'rolled-back-secret']);
        $this->assertSame('', DB::table('sys_configs')->where('slug', 'ali_sms_secret')->value('value'));
    }

    public function test_mini_program_secret_set_and_invalidation_are_audited_without_serializing_the_secret(): void
    {
        $owner = User::factory()->create(['type' => 1, 'status' => true]);
        $mini = MiniProgram::query()->create([
            'name' => 'audited mini',
            'app_id' => 'audited-app',
            'secret' => 'mini-secret-value',
            'url' => 'pages/index',
            'type' => 1,
            'user_id' => $owner->id,
            'is_pre_min' => false,
            'is_enable' => true,
        ]);
        $mini->delete();

        $audits = ProtectedSecretAudit::query()->where('source', 'mini_program')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame('protected_secret.set', $audits[0]->event_name);
        $this->assertSame('protected_secret.invalidated', $audits[1]->event_name);
        $this->assertSame('mini_program:'.$mini->id.':secret', $audits[0]->key_identifier);
        $this->assertSame('mini_program:'.$mini->id.':secret', $audits[1]->key_identifier);
        $this->assertStringNotContainsString('mini-secret-value', $audits->toJson());
    }
}
