<?php

namespace Database\Seeders;

use App\Models\MaterialCategory;
use Illuminate\Database\Seeder;

final class MaterialCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['id' => 1, 'name' => '系统', 'sort' => 1, 'type' => 1],
            ['id' => 2, 'name' => '其他', 'sort' => 0, 'type' => 0],
        ] as $row) {
            MaterialCategory::query()->firstOrCreate(['id' => $row['id']], $row);
        }
    }
}
