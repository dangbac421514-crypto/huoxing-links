<?php

namespace Tests\Concerns;

use App\Enums\FeedbackTicketStatus;
use App\Enums\LinkType;
use App\Enums\UserType;
use App\Models\Domain;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackChannel;
use App\Models\FeedbackTicket;
use App\Models\User;
use App\Models\VipPackage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

trait CreatesFeedbackFixtures
{
    protected function activeFeedbackUser(): User
    {
        $package = VipPackage::query()->create([
            'name' => 'Feedback package '.Str::lower(Str::random(8)),
            'price' => 0,
            'level' => 1,
            'config' => [
                'pre_min' => true,
                'support' => true,
                'uv_limit' => 100,
                'cur_index' => true,
                'count_limit' => 20,
                'min_count_limit' => 20,
                'min_disabled_check' => true,
                'allow_type' => array_fill_keys(LinkType::getAllType(), true),
            ],
        ]);
        $start = CarbonImmutable::now('Asia/Shanghai')->subMinute();

        return User::factory()->create([
            'type' => UserType::MEMBER,
            'status' => true,
            'must_change_password' => false,
            'vip_id' => $package->id,
            'start_at' => $start,
            'end_at' => $start->addMonth(),
        ]);
    }

    protected function enabledShareDomain(string $host = 'feedback.example'): Domain
    {
        $allowed = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            array_merge((array) config('app.allowed_share_hosts', []), [$host]),
        ))));
        Config::set('app.allowed_share_hosts', $allowed);

        return Domain::query()->create([
            'url' => 'https://'.$host,
            'title' => $host,
            'enable' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function feedbackChannelFor(User $user, array $overrides = []): FeedbackChannel
    {
        $domain = $overrides['domain'] ?? null;
        if ($domain instanceof Domain) {
            unset($overrides['domain']);
        } else {
            $domainId = $overrides['domain_id'] ?? null;
            $domain = $domainId
                ? Domain::query()->findOrFail($domainId)
                : $this->enabledShareDomain();
        }

        $defaults = [
            'user_id' => $user->id,
            'domain_id' => $domain->id,
            'code' => Str::random(24),
            'status' => true,
            'name' => '售后反馈',
            'operator_name' => '测试运营主体',
            'intro' => '',
            'sla_text' => '2小时内响应',
            'categories' => ['其他'],
            'contact_required' => false,
            'retention_days' => 180,
        ];

        return FeedbackChannel::query()->create(array_replace($defaults, $overrides))->fresh();
    }

    /** @param array<string, mixed> $overrides */
    protected function feedbackTicketFor(User $user, array $overrides = []): FeedbackTicket
    {
        $channel = $overrides['channel'] ?? null;
        if ($channel instanceof FeedbackChannel) {
            unset($overrides['channel']);
        } else {
            $channelId = $overrides['feedback_channel_id'] ?? null;
            $channel = $channelId
                ? FeedbackChannel::query()->findOrFail($channelId)
                : $this->feedbackChannelFor($user);
        }

        $defaults = [
            'user_id' => $user->id,
            'feedback_channel_id' => $channel->id,
            'public_no' => 'FB-'.Str::upper(Str::random(16)),
            'category' => $channel->categories[0] ?? '其他',
            'status' => FeedbackTicketStatus::PENDING,
            'contact' => null,
            'content' => 'fixture content',
            'idempotency_key' => (string) Str::uuid(),
            'submitted_at' => CarbonImmutable::now('Asia/Shanghai'),
        ];

        return FeedbackTicket::query()->create(array_replace($defaults, $overrides))->fresh();
    }

    /** @return array<string, mixed> */
    protected function validChannelPayload(int $domainId): array
    {
        return [
            'domain_id' => $domainId,
            'name' => '售后反馈',
            'operator_name' => '测试运营主体',
            'intro' => '欢迎提交售后问题',
            'service_phone' => '4000000000',
            'sla_text' => '2小时内响应',
            'categories' => ['售前承诺', '订单履约', '退款售后', '服务态度', '产品问题', '其他'],
            'contact_required' => false,
            'retention_days' => 180,
        ];
    }

    /** @param array<string, mixed> $overrides */
    protected function validSubmission(array $overrides = []): array
    {
        return array_replace([
            'category' => '其他',
            'content' => 'content-secret-123',
            'contact' => 'contact-secret',
            'idempotency_key' => (string) Str::uuid(),
            'privacy_accepted' => true,
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    protected function postToChannelHost(FeedbackChannel $channel, array $payload): TestResponse
    {
        $channel->loadMissing('domain');
        $host = parse_url((string) $channel->domain->url, PHP_URL_HOST);

        return $this->withServerVariables(['HTTP_HOST' => $host])
            ->post('/f/'.$channel->code.'/tickets', $payload);
    }

    protected function actingAsFeedbackOwner(FeedbackTicket $ticket): void
    {
        $ticket->loadMissing('user');
        Sanctum::actingAs($ticket->user, ['*'], 'api');
    }

    protected function expiredFeedbackTicket(int $retentionDays): FeedbackTicket
    {
        $user = $this->activeFeedbackUser();
        $channel = $this->feedbackChannelFor($user, ['retention_days' => $retentionDays]);
        $submittedAt = CarbonImmutable::now('Asia/Shanghai')->subDays($retentionDays + 1);

        return $this->feedbackTicketFor($user, [
            'feedback_channel_id' => $channel->id,
            'submitted_at' => $submittedAt,
        ]);
    }

    protected function attachPrivateFixture(FeedbackTicket $ticket): FeedbackAttachment
    {
        $path = $ticket->user_id.'/'.$ticket->id.'/'.(string) Str::uuid().'.png';
        Storage::disk('feedback_private')->put($path, 'fixture-bytes');

        return FeedbackAttachment::query()->create([
            'user_id' => $ticket->user_id,
            'feedback_ticket_id' => $ticket->id,
            'disk' => 'feedback_private',
            'path' => $path,
            'original_name' => 'evidence.png',
            'mime' => 'image/png',
            'size' => 13,
            'sha256' => hash('sha256', 'fixture-bytes'),
        ])->fresh();
    }
}
