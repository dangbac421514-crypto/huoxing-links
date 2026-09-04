<?php

namespace Tests\Unit\Services;

use App\Exceptions\BusinessRuleException;
use App\Services\WeComWebhookPolicy;
use App\Support\FeedbackError;
use Tests\TestCase;

final class WeComWebhookPolicyTest extends TestCase
{
    public function test_webhook_policy_accepts_only_exact_wecom_endpoint(): void
    {
        $valid = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=12345678-abcd-1234-abcd-123456789012';
        $this->assertSame($valid, app(WeComWebhookPolicy::class)->assertValid($valid));
        foreach (['http://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=x', 'https://evil.example/cgi-bin/webhook/send?key=x', 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=x&next=y'] as $bad) {
            try {
                app(WeComWebhookPolicy::class)->assertValid($bad);
                $this->fail('unsafe webhook accepted');
            } catch (BusinessRuleException $exception) {
                $this->assertSame(FeedbackError::WEBHOOK_INVALID, $exception->errorCode);
            }
        }
    }

    public function test_webhook_policy_rejects_userinfo_non_443_port_fragment_and_short_key(): void
    {
        $key = '12345678-abcd-1234-abcd-123456789012';
        foreach ([
            'https://user:pass@qyapi.weixin.qq.com/cgi-bin/webhook/send?key='.$key,
            'https://qyapi.weixin.qq.com:8443/cgi-bin/webhook/send?key='.$key,
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key='.$key.'#frag',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=short-key-value',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key='.$key.'&key='.$key,
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send/?key='.$key,
        ] as $bad) {
            try {
                app(WeComWebhookPolicy::class)->assertValid($bad);
                $this->fail('unsafe webhook accepted');
            } catch (BusinessRuleException $exception) {
                $this->assertSame(FeedbackError::WEBHOOK_INVALID, $exception->errorCode);
            }
        }

        $withDefaultPort = 'https://qyapi.weixin.qq.com:443/cgi-bin/webhook/send?key='.$key;
        $this->assertSame($withDefaultPort, app(WeComWebhookPolicy::class)->assertValid($withDefaultPort));
    }
}
