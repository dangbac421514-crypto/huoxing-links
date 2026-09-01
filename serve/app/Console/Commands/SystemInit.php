<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SystemInit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:system-init';

    /**
     * The console command desc.
     *
     * @var string
     */
    protected $description = '显示系统初始化指引';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('请先执行 php artisan migrate --seed 初始化基础数据。');
        $this->line('然后执行 php artisan app:admin-provision 创建受控管理员。');

        return self::SUCCESS;
    }
}
