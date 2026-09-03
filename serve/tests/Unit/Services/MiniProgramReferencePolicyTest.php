<?php

namespace Tests\Unit\Services;

use App\Enums\MiniType;
use App\Exceptions\MiniProgramForbidden;
use App\Models\MiniProgram;
use App\Models\User;
use App\Services\MiniProgramReferencePolicy;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class MiniProgramReferencePolicyTest extends TestCase
{
    use CreatesLinkFixtures;

    public function test_member_can_reference_only_own_enabled_mini_program(): void
    {
        $owner = $this->activeMemberWithUvLimit(10);
        $mini = $this->miniProgramFor($owner);

        $this->assertSame($mini->id, app(MiniProgramReferencePolicy::class)->assertAllowed($owner, $mini->id)->id);

        $other = $this->activeMemberWithUvLimit(10);
        $otherMini = $this->miniProgramFor($other);
        $this->expectException(MiniProgramForbidden::class);
        app(MiniProgramReferencePolicy::class)->assertAllowed($owner, $otherMini->id);
    }

    public function test_official_pool_requires_real_time_entitlement_and_enabled_row(): void
    {
        $owner = $this->activeMemberWithUvLimit(10);
        $official = MiniProgram::query()->create([
            'user_id' => $owner->id,
            'name' => 'Official',
            'app_id' => 'wxofficial123456',
            'secret' => 'official-secret',
            'url' => 'pages/index/index',
            'type' => MiniType::LANDING,
            'is_pre_min' => true,
            'is_enable' => true,
        ]);

        $this->assertSame($official->id, app(MiniProgramReferencePolicy::class)->assertAllowed($owner, $official->id)->id);

        $official->update(['is_enable' => false]);
        $this->expectException(MiniProgramForbidden::class);
        app(MiniProgramReferencePolicy::class)->assertAllowed($owner, $official->id);
    }

    public function test_admin_can_reference_enabled_cross_tenant_mini_and_emits_only_structured_notice(): void
    {
        Log::spy();
        $admin = User::factory()->create(['type' => 3, 'status' => true]);
        $owner = $this->activeMemberWithUvLimit(10);
        $mini = $this->miniProgramFor($owner, true);

        $this->assertSame($mini->id, app(MiniProgramReferencePolicy::class)->assertAllowed($admin, $mini->id)->id);
        Log::shouldHaveReceived('notice')->once()->with(
            'link.mini_program.cross_tenant_reference',
            ['event' => 'link.mini_program.cross_tenant_reference', 'actor_id' => $admin->id, 'mini_id' => $mini->id, 'owner_id' => $owner->id],
        );
    }
}
