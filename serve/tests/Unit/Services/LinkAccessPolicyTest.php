<?php

namespace Tests\Unit\Services;

use App\Enums\LinkType;
use App\Models\Link;
use App\Support\LinkError;
use App\Services\LinkAccessPolicy;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class LinkAccessPolicyTest extends TestCase
{
    use CreatesLinkFixtures;

    public function test_missing_and_disabled_states_have_stable_decisions_in_order(): void
    {
        $at = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai');
        $policy = app(LinkAccessPolicy::class);

        $this->assertSame(LinkError::LINK_NOT_FOUND, $policy->check(null, $at)->errorCode);

        $owner = $this->activeMemberWithUvLimit(10);
        $link = Link::query()->create([
            'user_id' => $owner->id,
            'title' => 'Disabled',
            'description' => '',
            'icon' => '/icon.png',
            'type' => LinkType::WORK_WECHAT,
            'status' => 1,
            'manual_status' => 0,
            'health_status' => 0,
            'config' => ['url' => 'https://work.weixin.qq.com/ca/example'],
        ]);

        $this->assertSame(LinkError::LINK_DISABLED, $policy->check($link, $at)->errorCode);
        $link->update(['manual_status' => 1]);
        $this->assertSame(LinkError::LINK_DISABLED, $policy->check($link->fresh(), $at)->errorCode);
        $link->update(['health_status' => 1]);
        $this->assertTrue($policy->check($link->fresh(), $at)->allowed);
    }

    public function test_expired_at_is_not_an_access_gate_and_unknown_type_is_stable(): void
    {
        $at = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai');
        $owner = $this->activeMemberWithUvLimit(10);
        $link = Link::query()->create([
            'user_id' => $owner->id,
            'title' => 'Permanent',
            'description' => '',
            'icon' => '/icon.png',
            'type' => LinkType::WORK_WECHAT,
            'status' => 1,
            'manual_status' => 1,
            'health_status' => 1,
            'expired_at' => $at->subDay(),
            'config' => ['url' => 'https://work.weixin.qq.com/ca/example'],
        ]);

        $this->assertTrue(app(LinkAccessPolicy::class)->check($link, $at)->allowed);

        $link->setRawAttributes(array_merge($link->getAttributes(), ['type' => 999]));
        $link->syncOriginal();
        $this->assertSame(LinkError::LINK_TYPE_UNSUPPORTED, app(LinkAccessPolicy::class)->check($link, $at)->errorCode);
    }

    public function test_disabled_owner_membership_failure_and_known_disallowed_type_are_distinct(): void
    {
        $at = CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai');
        $owner = $this->activeMemberWithUvLimit(10);
        $link = Link::query()->create([
            'user_id' => $owner->id,
            'title' => 'Policy',
            'description' => '',
            'icon' => '/icon.png',
            'type' => LinkType::WORK_WECHAT,
            'status' => 1,
            'manual_status' => 1,
            'health_status' => 1,
            'config' => ['url' => 'https://work.weixin.qq.com/ca/example'],
        ]);
        $owner->update(['status' => false]);
        $this->assertSame(LinkError::USER_DISABLED, app(LinkAccessPolicy::class)->check($link->fresh(), $at)->errorCode);

        $owner->update(['status' => true]);
        $owner->vipPackage->update(['config' => array_merge($owner->vipPackage->config, [
            'allow_type' => array_replace(array_fill_keys(LinkType::getAllType(), false), ['MINI_PROGRAM' => true]),
        ])]);
        $this->assertSame(LinkError::LINK_TYPE_FORBIDDEN, app(LinkAccessPolicy::class)->check($link->fresh(), $at)->errorCode);

        $owner->vipPackage->update(['config' => ['invalid' => true]]);
        $this->assertSame(LinkError::MEMBERSHIP_EXPIRED, app(LinkAccessPolicy::class)->check($link->fresh(), $at)->errorCode);
    }

}
