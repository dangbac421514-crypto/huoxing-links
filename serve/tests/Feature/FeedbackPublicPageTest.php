<?php

namespace Tests\Feature;

use App\Models\FeedbackChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesFeedbackFixtures;
use Tests\TestCase;

final class FeedbackPublicPageTest extends TestCase
{
    use CreatesFeedbackFixtures;

    public function test_public_page_requires_selected_host_and_safe_merchant_copy(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser());
        $host = parse_url($channel->domain->url, PHP_URL_HOST);
        $response = $this->getFeedbackPage($channel->code, $host)
            ->assertOk()
            ->assertSee($channel->operator_name)
            ->assertSee('商家售后反馈')
            ->assertSee('本页面由上述商家运营，并非企业微信官方投诉入口')
            ->assertCookie('visitor_id');
        $this->assertStringNotContainsString(
            '企业微信官方投诉',
            str_replace('本页面由上述商家运营，并非企业微信官方投诉入口', '', $response->getContent()),
        );
        $this->getFeedbackPage($channel->code, 'other.example')->assertNotFound();
    }

    public function test_missing_code_is_not_found(): void
    {
        $this->getFeedbackPage(str_repeat('z', 24), 'feedback.example')->assertNotFound();
    }

    public function test_another_allowlisted_domain_pool_host_is_not_found(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'domain' => $this->enabledShareDomain('feedback.example'),
        ]);
        $this->enabledShareDomain('other.example');

        $this->getFeedbackPage($channel->code, 'other.example')->assertNotFound();
        $this->getOnChannelHost($channel)->assertOk()->assertSee('商家售后反馈');
    }

    public function test_script_like_configured_text_is_escaped(): void
    {
        $operator = '<script>alert("op")</script>';
        $intro = '<img src=x onerror=alert(1)>';
        $category = '<script>x</script>';
        $sla = '<svg onload=alert(2)>';
        $phone = '<script>alert(3)</script>';
        $name = '<script>alert(4)</script>';
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'name' => $name,
            'operator_name' => $operator,
            'intro' => $intro,
            'sla_text' => $sla,
            'service_phone' => $phone,
            'categories' => [$category, '其他'],
        ]);

        $response = $this->getOnChannelHost($channel)->assertOk();
        foreach ([$operator, $intro, $category, $sla, $phone, $name] as $raw) {
            $response->assertSee($raw)->assertDontSee($raw, false);
        }
        $this->assertDoesNotMatchRegularExpression(
            '/<script[^>]+src\s*=\s*[\'"]https?:\/\//i',
            $response->getContent(),
        );
    }

    public function test_form_exposes_fixed_controls_and_posts_to_ticket_path(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'operator_name' => '星河商贸',
            'intro' => '请描述您遇到的问题',
            'service_phone' => '4000000000',
            'sla_text' => '2小时内响应',
            'categories' => ['售前承诺', '其他'],
            'contact_required' => false,
            'retention_days' => 180,
        ]);

        $response = $this->getOnChannelHost($channel)->assertOk();
        $html = $response->getContent();
        $response->assertSee('星河商贸')
            ->assertSee('请描述您遇到的问题')
            ->assertSee('4000000000')
            ->assertSee('2小时内响应')
            ->assertSee('售前承诺')
            ->assertSee('180')
            ->assertSee('name="category"', false)
            ->assertSee('name="content"', false)
            ->assertSee('minlength="10"', false)
            ->assertSee('maxlength="2000"', false)
            ->assertSee('name="contact"', false)
            ->assertSee('name="attachments[]"', false)
            ->assertSee('name="privacy_accepted"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('type="submit"', false)
            ->assertSee('action="/f/'.$channel->code.'/tickets"', false)
            ->assertSee('method="post"', false);
        $this->assertSame(3, substr_count($html, 'name="attachments[]"'));
        $this->assertSame(3, substr_count($html, 'accept="image/jpeg,image/png,image/webp"'));
        $this->assertDoesNotMatchRegularExpression('/name="contact"[^>]*\brequired\b/i', $html);
        $this->assertStringNotContainsString('{!!', $html);
    }

    public function test_contact_is_required_when_channel_requires_it(): void
    {
        $channel = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'contact_required' => true,
        ]);
        $html = $this->getOnChannelHost($channel)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="contact"[^>]*\brequired\b/i', $html);
    }

    public function test_disabled_channel_domain_owner_and_entitlement_share_generic_unavailable_page(): void
    {
        $pages = [];

        $disabledChannel = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'status' => false,
            'operator_name' => '停用渠道主体',
        ]);
        $pages[] = $this->assertGenericUnavailable(
            $this->getOnChannelHost($disabledChannel),
            '停用渠道主体',
        );

        $disabledDomain = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'operator_name' => '停用域名主体',
        ]);
        $disabledDomain->domain->forceFill(['enable' => false])->save();
        $pages[] = $this->assertGenericUnavailable(
            $this->getOnChannelHost($disabledDomain),
            '停用域名主体',
        );

        $unlisted = $this->feedbackChannelFor($this->activeFeedbackUser(), [
            'domain' => $this->enabledShareDomain('feedback.example'),
            'operator_name' => '未放行域名主体',
        ]);
        Config::set('app.allowed_share_hosts', ['other.example']);
        $pages[] = $this->assertGenericUnavailable(
            $this->getFeedbackPage($unlisted->code, 'feedback.example'),
            '未放行域名主体',
        );
        $this->getFeedbackPage($unlisted->code, 'other.example')->assertNotFound();

        $disabledOwner = $this->activeFeedbackUser();
        $disabledOwnerChannel = $this->feedbackChannelFor($disabledOwner, [
            'operator_name' => '停用账号主体',
        ]);
        $disabledOwner->forceFill(['status' => false])->save();
        $pages[] = $this->assertGenericUnavailable(
            $this->getOnChannelHost($disabledOwnerChannel),
            '停用账号主体',
        );

        $expiredOwner = $this->activeFeedbackUser();
        $expiredChannel = $this->feedbackChannelFor($expiredOwner, [
            'operator_name' => '过期会员主体',
        ]);
        $expiredOwner->forceFill([
            'end_at' => CarbonImmutable::now('Asia/Shanghai')->subMinute(),
        ])->save();
        $pages[] = $this->assertGenericUnavailable(
            $this->getOnChannelHost($expiredChannel),
            '过期会员主体',
        );

        $this->assertCount(1, array_unique($pages));
    }

    public function test_blade_templates_escape_values_and_do_not_load_remote_scripts(): void
    {
        foreach (['show', 'unavailable'] as $view) {
            $source = file_get_contents(resource_path('views/feedback/'.$view.'.blade.php'));
            $this->assertIsString($source);
            $this->assertStringNotContainsString('{!!', $source);
            $this->assertDoesNotMatchRegularExpression(
                '/<script[^>]+src\s*=\s*[\'"]https?:\/\//i',
                $source,
            );
        }
    }

    private function getOnChannelHost(FeedbackChannel $channel): TestResponse
    {
        $channel->loadMissing('domain');

        return $this->getFeedbackPage(
            $channel->code,
            parse_url((string) $channel->domain->url, PHP_URL_HOST),
        );
    }

    private function getFeedbackPage(string $code, string $host): TestResponse
    {
        return $this->withServerVariables(['HTTP_HOST' => $host])
            ->get('https://'.$host.'/f/'.$code);
    }

    private function assertGenericUnavailable(TestResponse $response, string $secret): string
    {
        $response->assertOk()
            ->assertSee('页面暂不可用')
            ->assertSee('当前反馈入口暂不可用，请稍后再试。')
            ->assertDontSee($secret)
            ->assertDontSee('CHANNEL_DISABLED', false)
            ->assertDontSee('DOMAIN_UNAVAILABLE', false)
            ->assertDontSee('MEMBERSHIP_EXPIRED', false)
            ->assertDontSee('NO_ENTITLEMENT', false)
            ->assertDontSee('<form', false);

        return $response->getContent();
    }
}
