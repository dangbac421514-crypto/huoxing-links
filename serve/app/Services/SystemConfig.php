<?php

namespace App\Services;

use App\Models\SysConfig;
use Illuminate\Support\Facades\Cache;

class SystemConfig
{
    private const PROTECTED_SLUGS = [
        'ali_sms_key',
        'ali_sms_secret',
        'mail_password',
        'wechat_pay_secret_key',
        'wechat_pay_private_cert',
        'wechat_pay_certificate',
    ];

    /**
     * 获取系统配置.
     */
    public static function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key !== null && in_array($key, self::PROTECTED_SLUGS, true)) {
            return app(SecretConfigService::class)->get($key, $default);
        }

        $config = Cache::get('_db_system_config_', function () {
            $db_config = [];
            $list = SysConfig::query()->get();
            foreach ($list as $item) {
                if (in_array($item->slug, self::PROTECTED_SLUGS, true)) {
                    continue;
                }

                $db_config[$item->slug] = $item->value;
            }
            Cache::put('_db_system_config_', $db_config);

            return $db_config;
        });

        if ($key === null) {
            return $config;
        }

        return data_get($config, $key, $default);
    }

    /**
     * 设置系统配置.
     */
    public static function set(string|array $key, mixed $value = null): void
    {
        $data = is_array($key) ? $key : [$key => $value];
        foreach ($data as $k => $v) {
            if (in_array($k, self::PROTECTED_SLUGS, true)) {
                if (is_string($v)) {
                    app(SecretConfigService::class)->set($k, $v);
                }

                continue;
            }

            SysConfig::query()
                ->updateOrCreate([
                    'slug' => $k,
                ], [
                    'slug' => $k,
                    'value' => $v,
                ]);
        }

        // 清除缓存
        Cache::forget('_db_system_config_');
    }

    public static function forgetCache(): void
    {
        Cache::forget('_db_system_config_');
    }
}
