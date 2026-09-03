<?php

namespace Tests\Feature;

use App\Enums\LinkType;
use App\Models\Link;
use App\Services\QrRotationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
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
        Redis::connection('default')->flushdb();
    }

    protected function tearDown(): void
    {
        Redis::connection('default')->flushdb();
        parent::tearDown();
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
        $this->deleteJson('/api/links/'.$link->id)->assertNoContent();
        $this->assertDatabaseHas('links', ['id' => $link->id]);

        Sanctum::actingAs($link->user, ['*'], 'api');
        $this->deleteJson('/api/links/'.$link->id)->assertNoContent();
        $this->assertDatabaseMissing('links', ['id' => $link->id]);
        $this->deleteJson('/api/links/'.$link->id)->assertNoContent();
    }

    public function test_dirty_unknown_type_is_serialized_stably_in_list_and_detail(): void
    {
        $owner = $this->activeMemberWithUvLimit(10);
        $id = DB::table('links')->insertGetId([
            'user_id' => $owner->id,
            'title' => 'Dirty row',
            'description' => '',
            'icon' => '/icon.png',
            // The legacy column is unsignedTinyInteger; 255 is an unmapped
            // raw value and exercises the same dirty-row boundary.
            'type' => 255,
            'status' => 1,
            'manual_status' => 1,
            'health_status' => 1,
            'code' => 'dirty999',
            'config' => json_encode(['url' => 'https://example.invalid']),
            'price' => 0,
            'target_version' => (string) Str::uuid(),
            'expired_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($owner, ['*'], 'api');

        $detail = $this->getJson('/api/links/'.$id)->assertOk();
        $detail->assertJsonPath('type', 255)->assertJsonPath('effective_status', false);
        $this->assertSame('https://short.example/?code=dirty999', $detail->json('share_link'));

        $list = $this->getJson('/api/links')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $id);
        $this->assertSame(255, $row['type']);
        $this->assertFalse($row['effective_status']);
    }

    public function test_non_owner_update_is_scoped_before_validation_and_does_not_mutate(): void
    {
        $link = $this->linkForType(LinkType::WORK_WECHAT);
        $before = $link->fresh()->only(['title', 'type', 'icon', 'description', 'config', 'manual_status', 'health_status', 'expired_at']);
        $other = $this->activeMemberWithUvLimit(10);
        Sanctum::actingAs($other, ['*'], 'api');

        foreach ([[], ['type' => 'invalid'], ['title' => 'attacker', 'expired_at' => now()->toDateTimeString()]] as $payload) {
            $this->putJson('/api/links/'.$link->id, $payload)->assertNotFound();
        }
        $this->assertSame($before, $link->fresh()->only(array_keys($before)));
    }

    public function test_update_validates_own_cross_tenant_and_official_pool_min_id(): void
    {
        $link = $this->miniProgramLink();
        $owner = $link->user;
        $other = $this->activeMemberWithUvLimit(10);
        $otherMini = $this->miniProgramFor($other);
        $payload = [
            'title' => 'Updated mini',
            'type' => LinkType::MINI_PROGRAM->value,
            'icon' => '/icon.png',
            'description' => '',
            'config' => ['min_id' => $link->config['min_id']],
        ];
        Sanctum::actingAs($owner, ['*'], 'api');
        $this->putJson('/api/links/'.$link->id, $payload)->assertOk();

        $payload['config']['min_id'] = $otherMini->id;
        $this->putJson('/api/links/'.$link->id, $payload)
            ->assertStatus(403)->assertJsonPath('code', 'MINI_PROGRAM_FORBIDDEN');
        $this->assertSame($link->config['min_id'], $link->fresh()->config['min_id']);

        $official = $this->miniProgramFor($other, true);
        $payload['config']['min_id'] = $official->id;
        $this->putJson('/api/links/'.$link->id, $payload)->assertOk();
        $this->assertSame($official->id, $link->fresh()->config['min_id']);
    }

    public function test_landing_qr_input_is_hardened_and_visit_uv_is_normalized_on_create_and_update(): void
    {
        $owner = $this->activeMemberWithUvLimit(10);
        $mini = $this->miniProgramFor($owner);
        Sanctum::actingAs($owner, ['*'], 'api');
        $payload = [
            'type' => LinkType::LANDING_MINI->value,
            'icon' => '/icon.png',
            'config' => [
                'min_id' => $mini->id,
                'wx' => [
                    'avatar' => '/avatar.png',
                    'title' => 'Landing title',
                    'sub_title' => 'Landing subtitle',
                    'qr' => [[
                        'sort' => 0,
                        'path' => 'qr.png',
                        'name' => 'first',
                        'uv_limit_num' => null,
                        'expired_at' => null,
                        'visit_uv' => 'attacker-controlled',
                    ]],
                    'switch_type' => 1,
                    'uv_limit_type' => 1,
                ],
            ],
        ];

        $created = $this->postJson('/api/links', $payload)->assertCreated();
        $link = Link::query()->findOrFail($created->json('id'));
        $this->assertSame(0, $link->config['wx']['qr'][0]['visit_uv']);

        $payload['config']['wx']['qr'][0]['visit_uv'] = 12345;
        $this->putJson('/api/links/'.$link->id, $payload)->assertOk();
        $this->assertSame(0, $link->fresh()->config['wx']['qr'][0]['visit_uv']);
    }

    public function test_landing_qr_validation_rejects_empty_duplicate_and_invalid_fields(): void
    {
        $owner = $this->activeMemberWithUvLimit(10);
        $mini = $this->miniProgramFor($owner);
        Sanctum::actingAs($owner, ['*'], 'api');
        $base = [
            'type' => LinkType::LANDING_MINI->value,
            'icon' => '/icon.png',
            'config' => [
                'min_id' => $mini->id,
                'wx' => [
                    'title' => 'Title',
                    'sub_title' => 'Subtitle',
                    'qr' => [[
                        'sort' => 0,
                        'path' => 'qr.png',
                        'name' => 'first',
                        'uv_limit_num' => 1,
                        'expired_at' => null,
                    ]],
                    'switch_type' => 1,
                    'uv_limit_type' => 1,
                ],
            ],
        ];

        $empty = $base;
        $empty['config']['wx']['qr'] = [];
        $this->postJson('/api/links', $empty)->assertStatus(422);

        $duplicate = $base;
        $duplicate['config']['wx']['qr'][] = [
            'sort' => 0,
            'path' => 'second.png',
            'name' => 'second',
            'uv_limit_num' => 1,
            'expired_at' => null,
        ];
        $this->postJson('/api/links', $duplicate)->assertStatus(422);

        $badLimit = $base;
        $badLimit['config']['wx']['qr'][0]['uv_limit_num'] = 0;
        $this->postJson('/api/links', $badLimit)->assertStatus(422);

        $badExpiry = $base;
        $badExpiry['config']['wx']['qr'][0]['expired_at'] = '2026-9-02';
        $this->postJson('/api/links', $badExpiry)->assertStatus(422);

        $badCandidate = $base;
        $badCandidate['config']['wx']['qr'] = ['not-an-array'];
        $this->postJson('/api/links', $badCandidate)->assertStatus(422);
    }

    public function test_landing_qr_numeric_fields_require_json_integer_types_on_create_and_update(): void
    {
        $owner = $this->activeMemberWithUvLimit(10);
        $mini = $this->miniProgramFor($owner);
        Sanctum::actingAs($owner, ['*'], 'api');
        $base = [
            'type' => LinkType::LANDING_MINI->value,
            'icon' => '/icon.png',
            'config' => [
                'min_id' => $mini->id,
                'wx' => [
                    'title' => 'Title',
                    'sub_title' => 'Subtitle',
                    'qr' => [[
                        'sort' => 0,
                        'path' => 'qr.png',
                        'name' => 'first',
                        'uv_limit_num' => 1,
                        'expired_at' => null,
                    ]],
                    'switch_type' => 1,
                    'uv_limit_type' => 1,
                ],
            ],
        ];
        $invalidFields = [
            ['kind' => 'qr', 'field' => 'sort', 'values' => [true, false, 1.5, '1', 'not-an-int']],
            ['kind' => 'qr', 'field' => 'uv_limit_num', 'values' => [true, false, 1.5, '1', 'not-an-int']],
            ['kind' => 'wx', 'field' => 'switch_type', 'values' => [true, false, 1.5, '1', 'not-an-enum']],
            ['kind' => 'wx', 'field' => 'uv_limit_type', 'values' => [true, false, 1.5, '1', 'not-an-enum']],
        ];

        $linkCount = Link::query()->count();
        foreach ($invalidFields as $invalidField) {
            foreach ($invalidField['values'] as $value) {
                $payload = $base;
                if ($invalidField['kind'] === 'qr') {
                    $payload['config']['wx']['qr'][0][$invalidField['field']] = $value;
                } else {
                    $payload['config']['wx'][$invalidField['field']] = $value;
                }

                $this->postJson('/api/links', $payload)->assertStatus(422);
                $this->assertSame($linkCount, Link::query()->count());
            }
        }

        $created = $this->postJson('/api/links', $base)->assertCreated();
        $link = Link::query()->findOrFail($created->json('id'));
        $before = $link->fresh()->config;
        foreach ($invalidFields as $invalidField) {
            foreach ($invalidField['values'] as $value) {
                $payload = $base;
                if ($invalidField['kind'] === 'qr') {
                    $payload['config']['wx']['qr'][0][$invalidField['field']] = $value;
                } else {
                    $payload['config']['wx'][$invalidField['field']] = $value;
                }

                $this->putJson('/api/links/'.$link->id, $payload)->assertStatus(422);
                $this->assertSame($before, $link->fresh()->config);
            }
        }
    }

    public function test_landing_delete_forgets_only_its_cumulative_qr_keys_and_keeps_foreign_and_daily_state(): void
    {
        $link = $this->landingLinkWithQrs([
            ['sort' => 1, 'path' => 'persistent.png', 'uv_limit_num' => 3],
        ]);
        $service = app(QrRotationService::class);
        $service->reserve($link, CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Shanghai'));
        $counterKey = 'link:qr:'.$link->id.':accumulate';
        $cursorKey = 'link:qr:'.$link->id.':cursor:accumulate';
        $dailyKey = 'link:qr:'.$link->id.':daily:20260902';
        Redis::connection('default')->hset($dailyKey, '1', 7);
        Redis::connection('default')->expire($dailyKey, 172800);

        $foreign = $this->activeMemberWithUvLimit(10);
        Sanctum::actingAs($foreign, ['*'], 'api');
        $this->deleteJson('/api/links/'.$link->id)->assertNoContent();
        $this->assertDatabaseHas('links', ['id' => $link->id]);
        $this->assertSame('1', (string) Redis::connection('default')->hget($counterKey, '1'));
        $this->assertSame('0', (string) Redis::connection('default')->get($cursorKey));

        Sanctum::actingAs($link->user, ['*'], 'api');
        $this->deleteJson('/api/links/'.$link->id)->assertNoContent();
        $this->assertDatabaseMissing('links', ['id' => $link->id]);
        $this->assertSame(0, Redis::connection('default')->exists($counterKey));
        $this->assertSame(0, Redis::connection('default')->exists($cursorKey));
        $this->assertSame('7', (string) Redis::connection('default')->hget($dailyKey, '1'));
        $this->assertGreaterThan(0, Redis::connection('default')->ttl($dailyKey));
    }
}
