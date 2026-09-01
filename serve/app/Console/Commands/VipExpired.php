<?php

namespace App\Console\Commands;

use App\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class VipExpired extends Command
{
    protected $signature = 'app:vip-expired {--at= : Asia/Shanghai evaluation time for deterministic tests}';

    protected $description = '处理会员到期、待生效变更和状态投影';

    public function handle(MembershipService $memberships): int
    {
        $at = $this->option('at')
            ? CarbonImmutable::parse($this->option('at'), 'Asia/Shanghai')
            : CarbonImmutable::now('Asia/Shanghai');
        $processed = $memberships->expireDue($at) + $memberships->applyDueChanges($at);
        $this->info("processed={$processed}");

        return self::SUCCESS;
    }
}
