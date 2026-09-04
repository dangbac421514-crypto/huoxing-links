<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Domain;
use App\Models\FeedbackChannel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesFeedbackFixtures;
use Tests\TestCase;

final class FeedbackChannelApiTest extends TestCase
{
    use CreatesFeedbackFixtures;

    public function test_member_creates_channel_with_server_owned_code_and_share_url(): void
    {
        $user = $this->activeFeedbackUser();
        Sanctum::actingAs($user, ['*'], 'api');
        $domain = $this->enabledShareDomain('feedback.example');
        $response = $this->postJson('/api/feedback-channels', $this->validChannelPayload($domain->id));
        $response->assertCreated()
            ->assertJsonPath('domain_id', $domain->id)
            ->assertJsonPath('share_url', fn ($value) => preg_match('#^https://feedback\.example/f/[A-Za-z0-9]{24}$#', $value) === 1)
            ->assertJsonMissingPath('webhook_url');
    }

    public function test_normal_endpoint_never_reads_another_users_channel(): void
    {
        $owner = $this->activeFeedbackUser();
        $other = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($owner);
        Sanctum::actingAs($other, ['*'], 'api');
        $this->getJson('/api/feedback-channels/'.$channel->id)->assertNotFound();
    }

    public function test_list_returns_only_the_authenticated_users_channels(): void
    {
        $owner = $this->activeFeedbackUser();
        $other = $this->activeFeedbackUser();
        $mine = $this->feedbackChannelFor($owner, ['name' => '我的渠道']);
        $this->feedbackChannelFor($other, ['name' => '别人的渠道']);
        Sanctum::actingAs($owner, ['*'], 'api');

        $list = $this->getJson('/api/feedback-channels')->assertOk();
        $this->assertSame(1, $list->json('meta.total'));
        $row = collect($list->json('data'))->firstWhere('id', $mine->id);
        $this->assertNotNull($row);
        $this->assertSame('我的渠道', $row['name']);
        $this->assertMatchesRegularExpression('#^https://feedback\.example/f/[A-Za-z0-9]{24}$#', $row['share_url']);
        $this->assertTrue($row['domain_available']);
        $this->assertFalse($row['webhook_configured']);
        $this->assertArrayNotHasKey('webhook_url', $row);
        $this->assertNull(collect($list->json('data'))->firstWhere('name', '别人的渠道'));
        $this->assertStringNotContainsString('webhook_url', $list->getContent());
    }

    public function test_update_changes_public_configuration_and_keeps_server_owned_fields(): void
    {
        $owner = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($owner);
        $originalCode = $channel->code;
        $nextDomain = $this->enabledShareDomain('feedback-next.example');
        Sanctum::actingAs($owner, ['*'], 'api');

        $payload = $this->validChannelPayload($nextDomain->id);
        $payload['name'] = '改名后的渠道';
        $payload['operator_name'] = '新运营主体';
        $payload['sla_text'] = '24小时内响应';
        $payload['categories'] = ['产品问题', '其他'];
        $payload['contact_required'] = true;
        $payload['retention_days'] = 30;
        $payload['code'] = 'CLIENTCODECLIENTCODE1234';
        $payload['user_id'] = $this->activeFeedbackUser()->id;
        $payload['status'] = false;
        $payload['webhook_url'] = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=update-secret';

        $this->putJson('/api/feedback-channels/'.$channel->id, $payload)
            ->assertOk()
            ->assertJsonPath('name', '改名后的渠道')
            ->assertJsonPath('operator_name', '新运营主体')
            ->assertJsonPath('domain_id', $nextDomain->id)
            ->assertJsonPath('contact_required', true)
            ->assertJsonPath('retention_days', 30)
            ->assertJsonPath('status', true)
            ->assertJsonPath('share_url', fn ($value) => preg_match('#^https://feedback-next\.example/f/'.$originalCode.'$#', $value) === 1)
            ->assertJsonMissingPath('webhook_url');

        $stored = $channel->fresh();
        $this->assertSame($originalCode, $stored->code);
        $this->assertSame($owner->id, $stored->user_id);
        $this->assertTrue((bool) $stored->status);
        $this->assertNull($stored->webhook_url);
        $this->assertSame(['产品问题', '其他'], $stored->categories);
    }

    public function test_status_accepts_only_a_boolean_and_does_not_delete_the_row(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        Sanctum::actingAs($channel->user, ['*'], 'api');

        $this->patchJson('/api/feedback-channels/'.$channel->id.'/status', ['status' => false])
            ->assertOk()
            ->assertJsonPath('status', false)
            ->assertJsonMissingPath('webhook_url');
        $this->assertFalse((bool) $channel->fresh()->status);
        $this->assertDatabaseHas('feedback_channels', ['id' => $channel->id]);

        foreach ([['status' => 'false'], ['status' => 0], ['status' => 1], ['status' => null], [], ['status' => true, 'extra' => true]] as $payload) {
            $this->patchJson('/api/feedback-channels/'.$channel->id.'/status', $payload)
                ->assertStatus(422)
                ->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->assertFalse((bool) $channel->fresh()->status);

        $this->patchJson('/api/feedback-channels/'.$channel->id.'/status', ['status' => true])
            ->assertOk()
            ->assertJsonPath('status', true);
        $this->assertTrue((bool) $channel->fresh()->status);

        $this->deleteJson('/api/feedback-channels/'.$channel->id)->assertStatus(405);
        $this->assertDatabaseHas('feedback_channels', ['id' => $channel->id]);
    }

    public function test_create_ignores_client_owned_code_user_status_and_webhook_url(): void
    {
        $user = $this->activeFeedbackUser();
        $other = $this->activeFeedbackUser();
        Sanctum::actingAs($user, ['*'], 'api');
        $domain = $this->enabledShareDomain('feedback.example');
        $payload = $this->validChannelPayload($domain->id);
        $payload['code'] = 'CLIENTCODECLIENTCODE1234';
        $payload['user_id'] = $other->id;
        $payload['status'] = false;
        $payload['webhook_url'] = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=create-secret';

        $response = $this->postJson('/api/feedback-channels', $payload)
            ->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('webhook_configured', false)
            ->assertJsonMissingPath('webhook_url');
        $this->assertStringNotContainsString('create-secret', $response->getContent());
        $this->assertStringNotContainsString('CLIENTCODECLIENTCODE1234', $response->getContent());
        $this->assertMatchesRegularExpression('#^https://feedback\.example/f/[A-Za-z0-9]{24}$#', $response->json('share_url'));

        $stored = FeedbackChannel::query()->findOrFail($response->json('id'));
        $this->assertNotSame('CLIENTCODECLIENTCODE1234', $stored->code);
        $this->assertSame(24, strlen((string) $stored->code));
        $this->assertSame($user->id, $stored->user_id);
        $this->assertTrue((bool) $stored->status);
        $this->assertNull($stored->webhook_url);
        $this->assertSame($domain->id, $stored->domain_id);
        $raw = DB::table('feedback_channels')->where('id', $stored->id)->first();
        $this->assertTrue($raw->webhook_url === null || $raw->webhook_url === '');
        $this->assertStringNotContainsString('create-secret', (string) $raw->webhook_url);
    }

    public function test_disabled_or_unallowlisted_domain_hides_share_url_without_enumerating_foreign_rows(): void
    {
        $owner = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($owner);
        Sanctum::actingAs($owner, ['*'], 'api');
        $this->getJson('/api/feedback-channels/'.$channel->id)
            ->assertOk()
            ->assertJsonPath('domain_available', true);

        $channel->domain->forceFill(['enable' => false])->save();
        $this->getJson('/api/feedback-channels/'.$channel->id)
            ->assertOk()
            ->assertJsonPath('share_url', null)
            ->assertJsonPath('domain_available', false)
            ->assertJsonMissingPath('webhook_url');

        Config::set('app.allowed_share_hosts', ['other.example']);
        $channel->domain->forceFill(['enable' => true])->save();
        $this->getJson('/api/feedback-channels/'.$channel->id)
            ->assertOk()
            ->assertJsonPath('share_url', null)
            ->assertJsonPath('domain_available', false);

        $disabled = Domain::query()->create([
            'url' => 'https://disabled.example',
            'title' => 'disabled',
            'enable' => false,
        ]);
        $this->postJson('/api/feedback-channels', $this->validChannelPayload($disabled->id))
            ->assertStatus(422);

        $foreign = $this->feedbackChannelFor($this->activeFeedbackUser());
        $this->getJson('/api/feedback-channels/'.$foreign->id)->assertNotFound();
        $this->putJson('/api/feedback-channels/'.$foreign->id, $this->validChannelPayload($channel->domain_id))->assertNotFound();
        $this->patchJson('/api/feedback-channels/'.$foreign->id.'/status', ['status' => false])->assertNotFound();
        $this->assertTrue((bool) $foreign->fresh()->status);
    }

    public function test_admin_account_is_still_scoped_to_own_user_id(): void
    {
        $owner = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($owner);
        $admin = User::factory()->create([
            'type' => UserType::Admin,
            'status' => true,
            'must_change_password' => false,
        ]);
        Sanctum::actingAs($admin, ['*'], 'api');

        $this->getJson('/api/feedback-channels/'.$channel->id)->assertNotFound();
        $this->putJson('/api/feedback-channels/'.$channel->id, $this->validChannelPayload($channel->domain_id))->assertNotFound();
        $this->patchJson('/api/feedback-channels/'.$channel->id.'/status', ['status' => false])->assertNotFound();
        $list = $this->getJson('/api/feedback-channels')->assertOk();
        $this->assertSame(0, $list->json('meta.total'));
        $this->assertDatabaseHas('feedback_channels', ['id' => $channel->id, 'user_id' => $owner->id, 'status' => 1]);
    }

    public function test_expired_member_cannot_create_a_channel(): void
    {
        $user = $this->activeFeedbackUser();
        $user->forceFill([
            'end_at' => CarbonImmutable::now('Asia/Shanghai')->subMinute(),
        ])->save();
        Sanctum::actingAs($user->fresh(), ['*'], 'api');
        $domain = $this->enabledShareDomain('feedback.example');

        $this->postJson('/api/feedback-channels', $this->validChannelPayload($domain->id))
            ->assertStatus(422)
            ->assertJsonPath('code', 'MEMBERSHIP_EXPIRED');
        $this->assertDatabaseCount('feedback_channels', 0);
    }

    public function test_foreign_update_is_404_before_validation_and_does_not_mutate(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $before = $channel->fresh()->only(['name', 'domain_id', 'code', 'status', 'user_id', 'categories', 'webhook_url']);
        Sanctum::actingAs($this->activeFeedbackUser(), ['*'], 'api');

        foreach ([[], ['name' => 'attacker'], $this->validChannelPayload($channel->domain_id)] as $payload) {
            $this->putJson('/api/feedback-channels/'.$channel->id, $payload)->assertNotFound();
        }
        $this->assertSame($before, $channel->fresh()->only(array_keys($before)));
    }
}
