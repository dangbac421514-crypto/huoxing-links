<?php

namespace Tests\Feature;

use App\Models\MaterialCategory;
use App\Models\SysConfig;
use App\Models\VipPackage;
use App\Services\SecretConfigService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeederIdempotencyTest extends TestCase
{
    public function test_database_seeder_is_idempotent_and_has_approved_baseline_entitlements(): void
    {
        $this->seed();
        $this->seed();

        $trial = VipPackage::query()->findOrFail(1);

        $this->assertSame(3, VipPackage::query()->count());
        $this->assertSame(2, MaterialCategory::query()->count());
        $this->assertSame(1, SysConfig::query()->where('slug', 'give_vip_days')->count());
        $this->assertSame(0, VipPackage::query()->where('price', '>', 0)->count());
        $this->assertSame(3, (int) SysConfig::query()->findOrFail('give_vip_days')->value);
        $this->assertSame(1, $trial->config['count_limit']);
        $this->assertSame(500, $trial->config['uv_limit']);
        $this->assertSame(0, $trial->config['min_count_limit']);
        $this->assertTrue($trial->config['pre_min']);
        $allowTypeKeys = array_keys($trial->config['allow_type']);
        sort($allowTypeKeys);
        $expectedAllowTypeKeys = ['CLI_QR', 'KING_DOC', 'LANDING_MINI', 'MINI_PROGRAM', 'QQ_QR', 'WORK_WECHAT'];
        sort($expectedAllowTypeKeys);
        $this->assertSame($expectedAllowTypeKeys, $allowTypeKeys);
        $this->assertSame(
            ['MINI_PROGRAM'],
            array_keys(array_filter($trial->config['allow_type'])),
        );
        $this->assertDatabaseCount('users', 0);
    }

    public function test_rerunning_seeders_preserves_edits_and_restores_missing_baseline_rows(): void
    {
        $this->seed();

        VipPackage::query()->findOrFail(2)->update([
            'name' => '管理员自定义套餐',
            'price' => 1234,
            'config' => ['custom' => true],
        ]);
        MaterialCategory::query()->findOrFail(1)->update(['name' => '管理员素材分类']);
        SysConfig::query()->findOrFail('web_site_title')->update(['value' => '管理员品牌']);

        app(SecretConfigService::class)->set('ali_sms_secret', 'keep-encrypted-secret');
        $storedSecret = (string) DB::table('sys_configs')->where('slug', 'ali_sms_secret')->value('value');

        VipPackage::query()->whereKey(3)->delete();
        MaterialCategory::query()->whereKey(2)->delete();
        SysConfig::query()->whereKey('send_code_mode')->delete();

        $this->seed();

        $this->assertSame('管理员自定义套餐', VipPackage::query()->findOrFail(2)->name);
        $this->assertSame(1234, (int) VipPackage::query()->findOrFail(2)->price);
        $this->assertSame(['custom' => true], VipPackage::query()->findOrFail(2)->config);
        $this->assertSame('管理员素材分类', MaterialCategory::query()->findOrFail(1)->name);
        $this->assertSame('管理员品牌', SysConfig::query()->findOrFail('web_site_title')->value);
        $this->assertSame($storedSecret, DB::table('sys_configs')->where('slug', 'ali_sms_secret')->value('value'));
        $this->assertSame('2', (string) SysConfig::query()->findOrFail('send_code_mode')->value);
        $this->assertDatabaseHas('vip_packages', ['id' => 3]);
        $this->assertDatabaseHas('material_categories', ['id' => 2]);
    }
}
