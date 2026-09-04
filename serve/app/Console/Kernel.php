<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 会员到期
        $schedule->command('app:vip-expired')->hourly()->withoutOverlapping();
        // 链接健康检查
        $schedule->command('app:links-health-check')->everyTenMinutes()->withoutOverlapping(9);
        // 客诉个人信息到期清理
        $schedule->command('app:feedback-purge')->dailyAt('02:30')->timezone('Asia/Shanghai')->withoutOverlapping(120);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
