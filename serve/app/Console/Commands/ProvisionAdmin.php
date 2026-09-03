<?php

namespace App\Console\Commands;

use App\Services\AdminProvisioner;
use Illuminate\Console\Command;

final class ProvisionAdmin extends Command
{
    protected $signature = 'app:admin-provision {username?}';

    protected $description = '通过受控交互创建或更新首个管理员';

    public function handle(AdminProvisioner $provisioner): int
    {
        $username = (string) ($this->argument('username') ?: $this->ask('管理员用户名'));
        $password = (string) $this->secret('管理员密码（至少 12 位）');

        try {
            $admin = $provisioner->provision($username, $password);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('管理员已创建：'.$admin->username.'；首次登录必须修改密码。');

        return self::SUCCESS;
    }
}
