<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecretStorageReleaseGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_plaintext_protected_values_make_status_incompatible_even_when_other_values_decrypt(): void
    {
        $owner = User::factory()->create(['type' => 1, 'status' => true]);
        DB::table('sys_configs')->updateOrInsert(
            ['slug' => 'ali_sms_secret'],
            ['value' => 'plaintext-system-secret', 'desc' => 'protected'],
        );
        $validSystemCiphertext = 'enc:v1:'.Crypt::encryptString('valid-system-secret');
        DB::table('sys_configs')->updateOrInsert(
            ['slug' => 'ali_sms_key'],
            ['value' => $validSystemCiphertext, 'desc' => 'protected'],
        );
        DB::table('mini_programs')->insert([
            'name' => 'valid-mini',
            'app_id' => 'valid-app',
            'secret' => Crypt::encryptString('valid-mini-secret'),
            'url' => 'pages/index',
            'type' => 1,
            'user_id' => $owner->id,
            'is_pre_min' => false,
            'is_enable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('mini_programs')->insert([
            'name' => 'plaintext-mini',
            'app_id' => 'plaintext-app',
            'secret' => 'plaintext-mini-secret',
            'url' => 'pages/index',
            'type' => 1,
            'user_id' => $owner->id,
            'is_pre_min' => false,
            'is_enable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('app:secret-storage-status', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertSame([
            'plaintext_count' => 2,
            'encrypted_count' => 2,
            'compatible' => false,
        ], json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('plaintext-system-secret', $output);
        $this->assertStringNotContainsString('valid-system-secret', $output);
        $this->assertStringNotContainsString('valid-mini-secret', $output);
        $this->assertStringNotContainsString('plaintext-mini-secret', $output);
        $this->assertSame(['plaintext_count', 'encrypted_count', 'compatible'], array_keys(json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR)));
    }

    public function test_unreadable_mini_program_ciphertext_is_encrypted_but_incompatible_without_leaking_ciphertext(): void
    {
        $owner = User::factory()->create(['type' => 1, 'status' => true]);
        $wrongKey = new Encrypter(random_bytes(32), 'AES-256-CBC');
        $ciphertext = $wrongKey->encryptString('wrong-key-mini-secret');
        DB::table('mini_programs')->insert([
            'name' => 'wrong-key-mini',
            'app_id' => 'wrong-key-app',
            'secret' => $ciphertext,
            'url' => 'pages/index',
            'type' => 1,
            'user_id' => $owner->id,
            'is_pre_min' => false,
            'is_enable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('app:secret-storage-status', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertSame([
            'plaintext_count' => 0,
            'encrypted_count' => 1,
            'compatible' => false,
        ], json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($ciphertext, $output);
    }
}
