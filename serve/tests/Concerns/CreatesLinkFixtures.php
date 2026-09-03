<?php

namespace Tests\Concerns;

use App\Enums\LinkType;
use App\Enums\MiniType;
use App\Models\Link;
use App\Models\MiniProgram;
use App\Models\User;
use App\Models\VipPackage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait CreatesLinkFixtures
{
    protected function activeMemberWithUvLimit(int $limit, ?CarbonImmutable $activeAt = null): User
    {
        $package = VipPackage::query()->create([
            'name' => 'Task 2 package '.Str::lower(Str::random(8)),
            'price' => 0,
            'level' => 1,
            'config' => [
                'pre_min' => true,
                'support' => true,
                'uv_limit' => $limit,
                'cur_index' => true,
                'count_limit' => 20,
                'min_count_limit' => 20,
                'min_disabled_check' => true,
                'allow_type' => array_fill_keys(LinkType::getAllType(), true),
            ],
        ]);
        $start = ($activeAt ?? CarbonImmutable::now('Asia/Shanghai'))->subMinute();

        return User::factory()->create([
            'status' => true,
            'vip_id' => $package->id,
            'start_at' => $start,
            'end_at' => $start->addMonth(),
        ]);
    }

    /** @param array<string, mixed> $config */
    protected function linkForType(LinkType $type, array $config = [], ?CarbonImmutable $activeAt = null): Link
    {
        $owner = $this->activeMemberWithUvLimit(100, $activeAt);
        $defaults = match ($type) {
            LinkType::MINI_PROGRAM, LinkType::LANDING_MINI => ['min_id' => $this->miniProgramFor($owner)->id],
            LinkType::WORK_WECHAT => ['url' => 'https://work.weixin.qq.com/ca/example'],
            LinkType::KING_DOC => ['url' => 'https://kdocs.cn/l/example'],
            LinkType::CLI_QR => ['url' => 'https://qr61.cn/example/id'],
            LinkType::QR_QQ => ['url' => 'https://ym.link/example'],
        };

        return Link::query()->create([
            'user_id' => $owner->id,
            'title' => 'Task 2 link',
            'description' => 'Task 2 description',
            'icon' => '/icon.png',
            'type' => $type,
            'status' => 1,
            'manual_status' => 1,
            'health_status' => 1,
            'expired_at' => null,
            'config' => array_replace($defaults, $config),
        ]);
    }

    protected function miniProgramLink(?CarbonImmutable $activeAt = null): Link
    {
        return $this->linkForType(LinkType::MINI_PROGRAM, [], $activeAt);
    }

    /** @param array<int, array<string, mixed>> $qrs */
    protected function landingLinkWithQrs(array $qrs): Link
    {
        $link = $this->linkForType(LinkType::LANDING_MINI, [
            'wx' => [
                'avatar' => '/avatar.png',
                'title' => 'Landing title',
                'sub_title' => 'Landing subtitle',
                'qr' => $qrs,
                'switch_type' => 1,
                'uv_limit_type' => 1,
            ],
        ]);
        $link->title = 'Landing title';
        $link->description = 'Landing subtitle';
        $link->save();

        return $link;
    }

    protected function miniProgramFor(User $owner, bool $official = false, bool $enabled = true): MiniProgram
    {
        return MiniProgram::query()->create([
            'user_id' => $owner->id,
            'name' => 'Task 2 mini '.Str::lower(Str::random(8)),
            'app_id' => 'wx'.Str::lower(Str::random(16)),
            'secret' => 'task-2-secret',
            'url' => 'pages/index/index',
            'type' => $official ? MiniType::LANDING : MiniType::OWN,
            'is_pre_min' => $official,
            'is_enable' => $enabled,
        ]);
    }
}
