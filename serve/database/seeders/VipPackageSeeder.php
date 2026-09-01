<?php

namespace Database\Seeders;

use App\Models\VipPackage;
use Illuminate\Database\Seeder;

final class VipPackageSeeder extends Seeder
{
    public function run(): void
    {
        $allowTypes = [
            'CLI_QR',
            'KING_DOC',
            'LANDING_MINI',
            'MINI_PROGRAM',
            'QR_QQ',
            'WORK_WECHAT',
        ];

        $trialAllowTypes = array_fill_keys($allowTypes, false);
        $trialAllowTypes['MINI_PROGRAM'] = true;

        $rows = [
            [
                'id' => 1,
                'name' => '体验套餐',
                'price' => 0,
                'level' => 0,
                'config' => [
                    'pre_min' => true,
                    'support' => true,
                    'uv_limit' => 500,
                    'cur_index' => false,
                    'count_limit' => 1,
                    'min_count_limit' => 0,
                    'min_disabled_check' => true,
                    'allow_type' => $trialAllowTypes,
                ],
            ],
            [
                'id' => 2,
                'name' => '初级会员',
                'price' => 0,
                'level' => 1,
                'config' => [
                    'pre_min' => true,
                    'support' => true,
                    'uv_limit' => 50,
                    'cur_index' => true,
                    'count_limit' => 5,
                    'min_count_limit' => 5,
                    'min_disabled_check' => true,
                    'allow_type' => array_fill_keys($allowTypes, true),
                ],
            ],
            [
                'id' => 3,
                'name' => '高级会员',
                'price' => 0,
                'level' => 2,
                'config' => [
                    'pre_min' => true,
                    'support' => true,
                    'uv_limit' => 100000,
                    'cur_index' => true,
                    'count_limit' => 50,
                    'min_count_limit' => 10,
                    'min_disabled_check' => true,
                    'allow_type' => array_fill_keys($allowTypes, true),
                ],
            ],
        ];

        foreach ($rows as $row) {
            VipPackage::query()->firstOrCreate(['id' => $row['id']], $row);
        }
    }
}
