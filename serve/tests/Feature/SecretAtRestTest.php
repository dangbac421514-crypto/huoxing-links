<?php

namespace Tests\Feature;

use App\Enums\LinkType;
use App\Enums\UserType;
use App\Forms\BaseConfig;
use App\Models\Link;
use App\Models\MiniProgram;
use App\Models\User;
use App\Services\SanitizedLinkVisitRecorder;
use App\Services\SecretConfigService;
use App\Services\SystemConfig;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SecretAtRestTest extends TestCase
{
    private array $backupFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->backupFiles as $path) {
            File::delete($path);
        }

        $directory = storage_path('app/private/secret-backups');
        if (is_dir($directory) && File::files($directory) === []) {
            @rmdir($directory);
        }
        parent::tearDown();
    }

    public function test_legacy_value_is_backed_up_then_encrypted_and_never_returned_raw(): void
    {
        $secret = 'legacy-secret';
        DB::table('sys_configs')->updateOrInsert(
            ['slug' => 'ali_sms_secret'],
            ['value' => $secret, 'desc' => 'secret']
        );

        $this->artisan('app:encrypt-legacy-secrets')->assertExitCode(0);
        $stored = DB::table('sys_configs')->where('slug', 'ali_sms_secret')->value('value');

        $this->assertStringStartsWith('enc:v1:', $stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame($secret, app(SecretConfigService::class)->get('ali_sms_secret'));
        $this->assertStringStartsWith('********', (string) app(SecretConfigService::class)->mask('ali_sms_secret'));

        $files = File::glob(storage_path('app/private/secret-backups/*.json.enc'));
        $this->assertCount(1, $files);
        $this->backupFiles = $files;
        $this->assertSame(0700, fileperms(dirname($files[0])) & 0777);
        $this->assertSame(0600, fileperms($files[0]) & 0777);
        $backup = json_decode(Crypt::decryptString(File::get($files[0])), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($secret, $backup['sys_config:ali_sms_secret']);
        $this->assertStringNotContainsString($secret, json_encode(['stored' => $stored, 'mask' => app(SecretConfigService::class)->mask('ali_sms_secret')]));
    }

    public function test_empty_update_does_not_overwrite_existing_secret(): void
    {
        app(SecretConfigService::class)->set('ali_sms_key', 'keep-me');
        app(SecretConfigService::class)->set('ali_sms_key', '');

        $this->assertSame('keep-me', app(SecretConfigService::class)->get('ali_sms_key'));
        $this->assertStringStartsWith('enc:v1:', (string) DB::table('sys_configs')->where('slug', 'ali_sms_key')->value('value'));
    }

    public function test_system_config_cache_never_contains_protected_values(): void
    {
        $secret = 'cache-must-not-leak';
        DB::table('sys_configs')->updateOrInsert(
            ['slug' => 'ali_sms_secret'],
            ['value' => $secret, 'desc' => 'secret']
        );

        $cached = SystemConfig::get();

        $this->assertArrayNotHasKey('ali_sms_secret', $cached);
        $this->assertStringNotContainsString($secret, serialize($cached));
    }

    public function test_system_config_protected_write_uses_encrypted_boundary(): void
    {
        SystemConfig::set('ali_sms_secret', 'system-config-secret');

        $this->assertStringStartsWith('enc:v1:', (string) DB::table('sys_configs')->where('slug', 'ali_sms_secret')->value('value'));
        $this->assertSame('system-config-secret', app(SecretConfigService::class)->get('ali_sms_secret'));
    }

    public function test_explicit_protected_system_config_reads_decrypt_for_legacy_consumers(): void
    {
        app(SecretConfigService::class)->set('wechat_pay_secret_key', 'payment-secret');

        $this->assertSame('payment-secret', SystemConfig::get('wechat_pay_secret_key'));
        $this->assertArrayNotHasKey('wechat_pay_secret_key', SystemConfig::get());
    }

    public function test_status_rejects_unreadable_marked_ciphertext_without_leaking_it(): void
    {
        $malformed = 'enc:v1:not-a-ciphertext';
        DB::table('sys_configs')->updateOrInsert(
            ['slug' => 'ali_sms_secret'],
            ['value' => $malformed, 'desc' => 'secret']
        );

        $this->assertSame(1, Artisan::call('app:secret-storage-status', ['--json' => true]));
        $output = Artisan::output();
        $this->assertSame([
            'plaintext_count' => 0,
            'encrypted_count' => 1,
            'compatible' => false,
        ], json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($malformed, $output);
    }

    public function test_visit_recorder_strips_secret_before_persisting_real_db_row(): void
    {
        $cache = app(SanitizedLinkVisitRecorder::class)->sanitize([
            'title' => 'safe',
            'params' => [
                'appid' => 'appid',
                'path' => 'pages/index',
                'secret' => 'visit-secret',
            ],
        ]);
        $log = app(SanitizedLinkVisitRecorder::class)->record([
            'link_id' => 1,
            'user_id' => 1,
            'device_uid' => 'device',
            'cache' => $cache,
        ]);

        $stored = DB::table('link_visit_logs')->where('id', $log->id)->value('cache');
        $this->assertStringNotContainsString('visit-secret', $stored);
        $this->assertSame('appid', json_decode($stored, true)['params']['appid']);
        $this->assertArrayNotHasKey('secret', json_decode($stored, true)['params']);
    }

    public function test_legacy_encryption_scrubs_persisted_visit_secrets(): void
    {
        $secret = 'legacy-visit-secret';
        DB::table('link_visit_logs')->insert([
            'link_id' => 1,
            'user_id' => 1,
            'device_uid' => 'device',
            'cache' => json_encode(['params' => ['appid' => 'appid', 'secret' => $secret]], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('app:encrypt-legacy-secrets')->assertExitCode(0);
        $stored = DB::table('link_visit_logs')->value('cache');
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertArrayNotHasKey('secret', json_decode($stored, true)['params']);
    }

    public function test_jump_controller_persists_only_sanitized_visit_params(): void
    {
        $user = User::query()->create([
            'username' => 'jump-admin',
            'password' => 'password',
            'status' => true,
            'type' => UserType::Admin,
        ]);
        $link = Link::query()->create([
            'user_id' => $user->id,
            'title' => 'safe jump',
            'type' => LinkType::WORK_WECHAT,
            'status' => true,
            'icon' => '',
            'description' => '',
            'config' => ['url' => 'https://example.test/target'],
            'expired_at' => now()->addHour(),
        ]);

        $response = $this->getJson('/api/link-target/'.$link->code.'?device_uid=controller-device');
        $response->assertOk();

        $stored = DB::table('link_visit_logs')->where('link_id', $link->id)->value('cache');
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('secret', $stored);
        $this->assertStringNotContainsString('plaintext', $response->getContent());
    }

    public function test_deterministic_json_sorts_maps_recursively_but_preserves_lists(): void
    {
        $value = [
            'z' => ['b' => '斜杠/值', 'a' => 1.0],
            'list' => [['z' => 2, 'a' => '二'], '第三'],
            'a' => '中文',
        ];

        SystemConfig::set('json_config', $value);

        $this->assertSame(
            '{"a":"中文","list":[{"a":"二","z":2},"第三"],"z":{"a":1.0,"b":"斜杠/值"}}',
            DB::table('sys_configs')->where('slug', 'json_config')->value('value')
        );
        $this->assertSame([
            'a' => '中文',
            'list' => [['a' => '二', 'z' => 2], '第三'],
            'z' => ['a' => 1.0, 'b' => '斜杠/值'],
        ], SystemConfig::get('json_config'));
    }

    public function test_status_is_machine_readable_without_secret_values_and_migration_is_idempotent(): void
    {
        $secret = 'status-only-secret';
        DB::table('sys_configs')->updateOrInsert(
            ['slug' => 'ali_sms_key'],
            ['value' => $secret, 'desc' => 'secret']
        );
        DB::table('sys_configs')->updateOrInsert(
            ['slug' => 'ali_sms_secret'],
            ['value' => 'enc:v1:'.Crypt::encryptString('already-encrypted'), 'desc' => 'secret']
        );

        $this->assertSame(1, Artisan::call('app:secret-storage-status', ['--json' => true]));
        $output = Artisan::output();
        $this->assertStringNotContainsString($secret, $output);
        $this->assertStringNotContainsString('already-encrypted', $output);
        $this->assertSame(['plaintext_count' => 1, 'encrypted_count' => 1, 'compatible' => false], json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR));

        $this->artisan('app:encrypt-legacy-secrets')->assertExitCode(0);
        $files = File::glob(storage_path('app/private/secret-backups/*.json.enc'));
        $this->assertCount(1, $files);
        $this->backupFiles = $files;
        $this->artisan('app:encrypt-legacy-secrets')->assertExitCode(0);
        $this->assertCount(1, File::glob(storage_path('app/private/secret-backups/*.json.enc')));
        $this->assertSame(0, Artisan::call('app:secret-storage-status', ['--json' => true]));
        $this->assertStringNotContainsString($secret, (string) DB::table('sys_configs')->where('slug', 'ali_sms_key')->value('value'));
    }

    public function test_base_config_exposes_only_secret_status_and_preserves_empty_secret_input(): void
    {
        app(SecretConfigService::class)->set('ali_sms_secret', 'do-not-return');
        $form = new BaseConfig;

        $form->handle(['ali_sms_secret' => '', 'ali_sms_sign_name' => 'New Sign']);
        $default = $form->default();

        $this->assertSame('do-not-return', app(SecretConfigService::class)->get('ali_sms_secret'));
        $this->assertSame(['configured' => true, 'mask' => '********turn'], $default['ali_sms_secret']);
        $this->assertStringNotContainsString('do-not-return', json_encode($default));
        $this->assertSame('New Sign', SystemConfig::get('ali_sms_sign_name'));
    }

    public function test_model_secret_is_encrypted_at_rest_and_does_not_serialize_plaintext(): void
    {
        $model = MiniProgram::query()->create([
            'name' => 'test',
            'app_id' => 'app-id',
            'secret' => 'mini-secret',
            'url' => 'pages/index',
            'type' => 1,
            'user_id' => 1,
            'is_enable' => true,
        ]);

        $raw = DB::table('mini_programs')->where('id', $model->id)->value('secret');
        $this->assertNotSame('mini-secret', $raw);
        $this->assertSame('mini-secret', $model->fresh()->secret);
        $this->assertStringNotContainsString('mini-secret', json_encode($model->toArray()));
    }

    public function test_secret_column_is_wide_enough_and_rollback_never_narrows_it(): void
    {
        $this->assertSame('text', Schema::getColumnType('mini_programs', 'secret'));

        $migration = require database_path('migrations/2026_09_01_000005_expand_secret_columns.php');
        $migration->down();

        $this->assertSame('text', Schema::getColumnType('mini_programs', 'secret'));
    }
}
