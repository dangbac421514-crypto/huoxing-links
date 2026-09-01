<?php

namespace App\Console\Commands;

use App\Models\Link;
use App\Services\Health\LinkHealthCheckService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class ChkLinkStatus extends Command
{
    protected $signature = 'app:links-health-check {link_id?}';

    protected $aliases = ['app:chk-link'];

    protected $description = '检查链接健康状态';

    public function handle(LinkHealthCheckService $health): int
    {
        $rawId = $this->argument('link_id');
        if ($rawId !== null) {
            return $this->checkOne($rawId, $health);
        }

        $checked = 0;
        $failed = 0;
        $at = CarbonImmutable::now('Asia/Shanghai');
        Link::query()->orderBy('id')->chunkById(100, function ($links) use ($health, $at, &$checked, &$failed): void {
            foreach ($links as $link) {
                try {
                    $health->check($link, $at);
                    $checked++;
                    if (! (bool) $link->fresh()->getAttribute('health_status')) {
                        $failed++;
                    }
                } catch (Throwable) {
                    // Keep all-mode processing alive and expose only a count;
                    // provider/configuration details never reach command IO.
                    $failed++;
                }
            }
        });

        $this->info("checked={$checked} failed={$failed}");

        // A recorded unhealthy result is a successful command run. The
        // persisted health fields carry the provider outcome; a non-zero
        // status is reserved for invalid arguments or processing failures.
        return self::SUCCESS;
    }

    private function checkOne(mixed $rawId, LinkHealthCheckService $health): int
    {
        if (is_int($rawId)) {
            $rawId = (string) $rawId;
        }

        if (! is_string($rawId) || preg_match('/^[1-9][0-9]*$/D', $rawId) !== 1) {
            $this->error('invalid link_id');

            return self::INVALID;
        }

        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            $this->error('invalid link_id');

            return self::INVALID;
        }

        $link = Link::query()->find($id);
        if (! $link) {
            $this->error('link not found');

            return self::INVALID;
        }

        try {
            $health->check($link, CarbonImmutable::now('Asia/Shanghai'));
            $failed = (bool) $link->fresh()->getAttribute('health_status') ? 0 : 1;
            $this->info("checked=1 failed={$failed}");

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('checked=1 failed=1');

            return self::FAILURE;
        }
    }
}
