<?php

namespace Tests\Feature;

use App\Enums\LinkType;
use App\Enums\MiniType;
use App\Enums\UserType;
use App\Models\Domain;
use App\Models\MiniProgram;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ManagementEntitlementTest extends TestCase
{
    private User $member;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Date::setTestNow(CarbonImmutable::parse('2026-10-15 09:00:00', 'Asia/Shanghai'));
        $this->admin = User::factory()->create([
            'type' => UserType::Admin,
            'status' => true,
            'must_change_password' => false,
        ]);
        $this->member = User::factory()->create([
            'type' => UserType::MEMBER,
            'status' => true,
            'must_change_password' => false,
            'vip_id' => 2,
            'start_at' => CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Shanghai'),
            'end_at' => CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Shanghai'),
        ]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    public function test_existing_account_can_create_a_link_without_membership_gate(): void
    {
        $domain = Domain::query()->create(['url' => 'https://share.example.test', 'title' => 'share', 'enable' => true]);
        Sanctum::actingAs($this->member, ['*'], 'api');

        $response = $this->postJson('/api/links', [
            'title' => 'expired link',
            'type' => LinkType::KING_DOC->value,
            'icon' => '/icon.png',
            'description' => '',
            'config' => [
                'domain_id' => $domain->id,
                'url' => 'https://kdocs.cn/l/expired',
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('links', ['user_id' => $this->member->id, 'title' => 'expired link']);
    }

    public function test_existing_account_can_create_a_mini_program_and_read_the_official_pool(): void
    {
        MiniProgram::query()->create([
            'name' => 'official pool',
            'app_id' => 'official-app',
            'secret' => 'official-secret',
            'url' => 'pages/index',
            'type' => MiniType::LANDING,
            'user_id' => $this->admin->id,
            'is_pre_min' => true,
            'is_enable' => true,
        ]);
        Sanctum::actingAs($this->member, ['*'], 'api');

        $create = $this->postJson('/api/min-program', [
            'name' => 'expired mini',
            'app_id' => 'expired-app',
            'secret' => 'expired-secret',
            'url' => 'pages/index',
            'type' => MiniType::OWN->value,
            'is_enable' => true,
        ]);
        $create->assertCreated();

        $pool = $this->getJson('/api/min-programs');
        $pool->assertOk()->assertJsonFragment(['name' => 'official pool']);
    }

    public function test_existing_account_home_config_includes_the_official_pool_and_admin_can_manage(): void
    {
        MiniProgram::query()->create([
            'name' => 'official pool',
            'app_id' => 'official-app',
            'secret' => 'official-secret',
            'url' => 'pages/index',
            'type' => MiniType::LANDING,
            'user_id' => $this->admin->id,
            'is_pre_min' => true,
            'is_enable' => true,
        ]);
        Sanctum::actingAs($this->member, ['*'], 'api');

        $config = $this->getJson('/api/config')->assertOk()->json();
        $this->assertSame('official pool', $config['mini_programs'][0]['name'] ?? null);

        Sanctum::actingAs($this->admin, ['*'], 'api');
        $domain = Domain::query()->create(['url' => 'https://share.example.test', 'title' => 'share', 'enable' => true]);
        $this->postJson('/api/links', [
            'title' => 'admin link',
            'type' => LinkType::KING_DOC->value,
            'icon' => '/icon.png',
            'config' => [
                'domain_id' => $domain->id,
                'url' => 'https://kdocs.cn/l/admin',
            ],
        ])->assertCreated();
    }
}
