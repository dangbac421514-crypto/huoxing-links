<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Services\SecretConfigService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SecretRotationTest extends TestCase
{
    protected function resetEncrypter(): void
    {
        Facade::clearResolvedInstance('encrypter');
        $this->app->forgetInstance('encrypter');
        $this->app->forgetScopedInstances();
    }

    public function test_previous_key_can_read_and_current_key_reencrypts(): void
    {
        app(SecretConfigService::class)->set('mail_password', 'old-password');
        $cipher = DB::table('sys_configs')->where('slug', 'mail_password')->value('value');
        $oldKey = config('app.key');
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        config(['app.previous_keys' => [$oldKey], 'app.key' => $newKey]);
        $this->resetEncrypter();

        $this->assertContains(base64_decode(substr($oldKey, 7)), Crypt::getPreviousKeys());
        $this->assertSame('old-password', app(SecretConfigService::class)->get('mail_password'));
        app(SecretConfigService::class)->set('mail_password', 'new-password');
        $newCipher = DB::table('sys_configs')->where('slug', 'mail_password')->value('value');
        $this->assertNotSame($cipher, $newCipher);
        $this->assertSame('new-password', app(SecretConfigService::class)->get('mail_password'));

        $oldEncrypter = new Encrypter(base64_decode(substr($oldKey, 7)), 'AES-256-CBC');
        $this->expectException(DecryptException::class);
        $oldEncrypter->decryptString(Str::after($newCipher, 'enc:v1:'));
    }

    public function test_wrong_key_has_stable_error_without_secret(): void
    {
        app(SecretConfigService::class)->set('ali_sms_secret', 'do-not-leak');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => []]);
        $this->resetEncrypter();

        try {
            app(SecretConfigService::class)->get('ali_sms_secret');
            $this->fail('错误密钥必须失败');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('SECRET_DECRYPTION_FAILED', $exception->errorCode);
            $this->assertSame('受保护配置无法解密', $exception->getMessage());
            $this->assertStringNotContainsString('do-not-leak', $exception->getMessage());
        }
    }
}
