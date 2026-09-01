<?php

namespace Database\Seeders;

use App\Models\SysConfig;
use App\Services\SystemConfig;
use Illuminate\Database\Seeder;

final class SystemConfigSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['slug' => 'ali_sms_key', 'value' => '', 'desc' => '阿里短信 key 从受控配置注入'],
            ['slug' => 'ali_sms_secret', 'value' => '', 'desc' => '阿里短信 secret 从受控配置注入'],
            ['slug' => 'ali_sms_sign_name', 'value' => '', 'desc' => '阿里短信签名从受控配置注入'],
            ['slug' => 'give_vip_days', 'value' => '3', 'desc' => '注册赠送会员有效期/天'],
            ['slug' => 'give_vip_id', 'value' => '1', 'desc' => '赠送套餐'],
            ['slug' => 'is_give_vip', 'value' => '1', 'desc' => '是否开启注册赠送会员'],
            ['slug' => 'mail_from_address', 'value' => '', 'desc' => '发信地址'],
            ['slug' => 'mail_from_name', 'value' => '', 'desc' => '发信名称'],
            ['slug' => 'mail_host', 'value' => '', 'desc' => '服务器地址'],
            ['slug' => 'mail_password', 'value' => '', 'desc' => '邮件密码从受控配置注入'],
            ['slug' => 'mail_port', 'value' => '', 'desc' => '端口'],
            ['slug' => 'mail_username', 'value' => '', 'desc' => '发信账号'],
            ['slug' => 'send_code_mode', 'value' => '2', 'desc' => '发送验证码类型'],
            ['slug' => 'verify_code_is_open', 'value' => '0', 'desc' => '是否开启验证码'],
            ['slug' => 'web_site_bottom_logo', 'value' => '/image/toplogo.png', 'desc' => '网站 logo 深色'],
            ['slug' => 'web_site_customer_service', 'value' => '', 'desc' => '客服二维码'],
            ['slug' => 'web_site_logo', 'value' => '', 'desc' => '网站 logo 浅色'],
            ['slug' => 'web_site_title', 'value' => '卡片跳转', 'desc' => '网站名称'],
            ['slug' => 'wechat_pay_app_id', 'value' => '', 'desc' => '微信商户 appId'],
            ['slug' => 'wechat_pay_certificate', 'value' => '', 'desc' => '微信支付公钥证书从受控配置注入'],
            ['slug' => 'wechat_pay_mch_id', 'value' => '', 'desc' => '微信商户号'],
            ['slug' => 'wechat_pay_private_cert', 'value' => '', 'desc' => '微信支付私钥证书从受控配置注入'],
            ['slug' => 'wechat_pay_secret_key', 'value' => '', 'desc' => '微信支付密钥从受控配置注入'],
        ];

        foreach ($rows as $row) {
            SysConfig::query()->firstOrCreate(
                ['slug' => $row['slug']],
                ['value' => $row['value'], 'desc' => $row['desc']],
            );
        }

        SystemConfig::forgetCache();
    }
}
