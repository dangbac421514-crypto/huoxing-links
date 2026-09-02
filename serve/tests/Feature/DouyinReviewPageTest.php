<?php

namespace Tests\Feature;

use Tests\TestCase;

final class DouyinReviewPageTest extends TestCase
{
    public function test_review_page_is_direct_static_and_truthful(): void
    {
        $response = $this->get('/douyin/jifeng-assistant');

        $response->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'self'; img-src 'self'; style-src 'unsafe-inline'; script-src 'none'; connect-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            )
            ->assertSee('极风小助手')
            ->assertSee('已审核链接的管理与分享工具')
            ->assertSee('用户主动选择抖音好友或群聊')
            ->assertSee('不自动发送')
            ->assertSee('不读取私信')
            ->assertSee('合肥极兴网络科技有限公司');
    }

    public function test_review_page_has_no_diversion_or_redirect_surface(): void
    {
        $html = $this->get('/douyin/jifeng-assistant')->assertOk()->getContent();

        foreach (['微信', '二维码', '扫码', '加好友', '下载', 'http-equiv="refresh', 'window.location', '<script', '<a '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }
}
