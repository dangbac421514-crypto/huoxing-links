<?php

namespace Tests\Feature;

use App\Enums\LinkType;
use App\Models\Link;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesLinkFixtures;
use Tests\TestCase;

final class LinkCrudTest extends TestCase
{
    use CreatesLinkFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.public_origin', 'https://short.example');
        Config::set('app.allowed_share_hosts', ['short.example']);
    }

    public function test_create_list_and_detail_expose_permanent_status_and_canonical_share_url(): void
    {
        $owner = $this->activeMemberWithUvLimit(10);
        Sanctum::actingAs($owner, ['*'], 'api');

        $response = $this->postJson('/api/links', [
            'title' => 'Work link',
            'type' => LinkType::WORK_WECHAT->value,
            'icon' => '/icon.png',
            'description' => 'Description',
            'config' => ['url' => 'https://work.weixin.qq.com/ca/example'],
        ]);

        $response->assertCreated();
        $id = $response->json('id');
        $link = Link::query()->findOrFail($id);
        $this->assertNull($link->expired_at);
        $this->assertTrue((bool) $link->manual_status);
        $this->assertTrue((bool) $link->health_status);

        $detail = $this->getJson('/api/links/'.$id)->assertOk()->json();
        $this->assertSame('https://short.example/?code='.$link->code, $detail['share_link']);
        $this->assertTrue($detail['effective_status']);
        $this->assertArrayHasKey('manual_status', $detail);
        $this->assertArrayHasKey('health_status', $detail);

        $list = $this->getJson('/api/links')->assertOk()->json();
        $row = collect($list['data'])->firstWhere('id', $id);
        $this->assertSame($detail['share_link'], $row['share_link']);
        $this->assertTrue($row['effective_status']);
    }

    public function test_status_accepts_only_a_boolean_and_changes_no_other_state(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT);
        $owner = $link->user;
        Sanctum::actingAs($owner, ['*'], 'api');

        $this->patchJson('/api/links/'.$link->id.'/status', ['manual_status' => false])
            ->assertOk()
            ->assertJsonPath('data.manual_status', false);
        $stored = $link->fresh();
        $this->assertFalse((bool) $stored->manual_status);
        $this->assertTrue((bool) $stored->health_status);
        $this->assertSame(1, (int) $stored->status);
        $this->assertNull($stored->expired_at);

        foreach ([['manual_status' => 'false'], ['manual_status' => 0], ['manual_status' => 1], ['manual_status' => null], [], ['manual_status' => true, 'extra' => true]] as $payload) {
            $this->patchJson('/api/links/'.$link->id.'/status', $payload)
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR');
        }
    }

    public function test_owner_scope_and_delete_are_isolated_and_idempotent(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT);
        $other = $this->activeMemberWithUvLimit(10);
        Sanctum::actingAs($other, ['*'], 'api');

        $this->getJson('/api/links/'.$link->id)->assertNotFound();
        $this->deleteJson('/api/links/'.$link->id)->assertNotFound();
        $this->assertDatabaseHas('links', ['id' => $link->id]);

        Sanctum::actingAs($link->user, ['*'], 'api');
        $this->deleteJson('/api/links/'.$link->id)->assertNoContent();
        $this->assertDatabaseMissing('links', ['id' => $link->id]);
        $this->deleteJson('/api/links/'.$link->id)->assertNoContent();
    }
}
