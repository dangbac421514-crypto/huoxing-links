<?php

namespace Tests\Feature;

use App\Contracts\DnsResolver;
use App\Enums\LinkType;
use App\Enums\UserType;
use App\Models\Link;
use App\Models\MiniProgram;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CardJumpOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.public_origin' => 'https://cards.example',
            'app.allowed_share_hosts' => ['cards.example'],
        ]);
        app()->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        });
    }

    public function test_public_card_jump_does_not_require_membership_or_create_usage_quota_rows(): void
    {
        $user = $this->plainUser('card-public');
        $link = Link::query()->create([
            'user_id' => $user->id,
            'title' => '企业微信卡片',
            'type' => LinkType::WORK_WECHAT,
            'status' => true,
            'manual_status' => true,
            'health_status' => true,
            'icon' => 'icon.png',
            'description' => '直接跳转',
            'config' => ['url' => 'https://work.weixin.qq.com/ca/example'],
            'expired_at' => null,
        ]);

        $this->getJson('/api/link-target/'.$link->code)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.target', 'https://work.weixin.qq.com/ca/example');

        $this->assertDatabaseMissing('usage_periods', ['user_id' => $user->id]);
    }

    public function test_existing_user_without_membership_can_create_a_card(): void
    {
        $user = $this->plainUser('card-owner');
        Sanctum::actingAs($user, ['*'], 'api');

        $response = $this->postJson('/api/links', [
            'title' => '可用卡片',
            'type' => LinkType::WORK_WECHAT->value,
            'icon' => 'icon.png',
            'description' => '无需会员',
            'config' => ['url' => 'https://work.weixin.qq.com/ca/example'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('links', [
            'user_id' => $user->id,
            'title' => '可用卡片',
            'expired_at' => null,
        ]);
    }

    public function test_existing_user_without_membership_can_select_the_official_mini_pool(): void
    {
        $user = $this->plainUser('card-mini-owner');
        $officialOwner = $this->plainUser('official-owner', UserType::Admin);
        $mini = MiniProgram::query()->create([
            'user_id' => $officialOwner->id,
            'name' => '官方落地小程序',
            'app_id' => 'wxofficial123456',
            'secret' => 'test-secret',
            'url' => 'pages/views/tools/news',
            'type' => 1,
            'is_pre_min' => true,
            'is_enable' => true,
        ]);
        Sanctum::actingAs($user, ['*'], 'api');

        $this->getJson('/api/min-programs')
            ->assertOk()
            ->assertJsonFragment(['id' => $mini->id, 'name' => '官方落地小程序']);
    }

    public function test_canonical_share_query_redirects_to_the_working_jump_page(): void
    {
        $this->get('/?code=abc12345')->assertRedirect('/j/abc12345');

        $page = $this->get('/j/abc12345')->assertOk();
        $page->assertSee('data.data', false);
        $page->assertDontSee('火星智慧引流', false);
    }

    private function plainUser(string $username, UserType $type = UserType::MEMBER): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => Hash::make('card-only-password'),
            'status' => true,
            'type' => $type,
        ]);
    }
}
