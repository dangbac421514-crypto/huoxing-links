<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\SysConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SecretConfigService
{
    private const SLUGS = [
        'ali_sms_key',
        'ali_sms_secret',
        'mail_password',
        'wechat_pay_secret_key',
        'wechat_pay_private_cert',
        'wechat_pay_certificate',
    ];

    public function get(string $slug, mixed $default = null): mixed
    {
        $this->assertSlug($slug);
        $raw = SysConfig::query()->whereKey($slug)->value('value');

        if ($raw === null || $raw === '') {
            return $default;
        }

        if (! Str::startsWith($raw, 'enc:v1:')) {
            return $raw;
        }

        try {
            return Crypt::decryptString(Str::after($raw, 'enc:v1:'));
        } catch (\Throwable) {
            throw new BusinessRuleException('SECRET_DECRYPTION_FAILED', '受保护配置无法解密', 500);
        }
    }

    public function set(string $slug, string $value): void
    {
        $this->assertSlug($slug);

        if ($value === '') {
            return;
        }

        SysConfig::query()->updateOrCreate(
            ['slug' => $slug],
            ['value' => 'enc:v1:'.Crypt::encryptString($value)]
        );

        $this->forgetSystemConfigCache();
    }

    public function configured(string $slug): bool
    {
        return filled($this->get($slug));
    }

    public function mask(string $slug): ?string
    {
        $value = $this->get($slug);

        return filled($value) ? '********'.substr((string) $value, -4) : null;
    }

    public function secretSlugs(): array
    {
        return self::SLUGS;
    }

    public function canDecryptStoredValue(string $stored): bool
    {
        if (! Str::startsWith($stored, 'enc:v1:')) {
            return false;
        }

        try {
            Crypt::decryptString(Str::after($stored, 'enc:v1:'));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertSlug(string $slug): void
    {
        if (! in_array($slug, self::SLUGS, true)) {
            throw new \InvalidArgumentException('不是受保护配置');
        }
    }

    private function forgetSystemConfigCache(): void
    {
        $forget = static fn (): bool => Cache::forget('_db_system_config_');

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($forget);

            return;
        }

        $forget();
    }
}
