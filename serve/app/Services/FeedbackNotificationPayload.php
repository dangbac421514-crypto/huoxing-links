<?php

namespace App\Services;

use App\Models\FeedbackChannel;
use App\Models\FeedbackTicket;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class FeedbackNotificationPayload
{
    private const TEST_MARKER = '测试通知';

    private const DEFAULT_ADMIN_PATH = '/#/feedback';

    /**
     * @return array{msgtype: string, text: array{content: string}}
     */
    public function forTicket(FeedbackTicket $ticket): array
    {
        $ticket->loadMissing('channel');
        $submitted = $ticket->submitted_at instanceof DateTimeInterface
            ? CarbonImmutable::instance($ticket->submitted_at)
                ->timezone((string) config('app.timezone', 'Asia/Shanghai'))
                ->format('Y-m-d H:i:s')
            : '';

        return $this->text(implode("\n", [
            '运营主体：'.$ticket->channel->operator_name,
            '工单号：'.$ticket->public_no,
            '分类：'.$ticket->category,
            '提交时间：'.$submitted,
            '后台链接：'.$this->adminTicketUrl($ticket),
        ]));
    }

    /**
     * @return array{msgtype: string, text: array{content: string}}
     */
    public function forTest(FeedbackChannel $channel): array
    {
        return $this->text(implode("\n", [
            self::TEST_MARKER,
            '运营主体：'.$channel->operator_name,
            '渠道：'.$channel->name,
        ]));
    }

    /**
     * @return array{msgtype: string, text: array{content: string}}
     */
    private function text(string $content): array
    {
        return [
            'msgtype' => 'text',
            'text' => ['content' => $content],
        ];
    }

    private function adminTicketUrl(FeedbackTicket $ticket): string
    {
        $origin = rtrim((string) (config('app.admin_url') ?: config('app.url')), '/');
        $path = (string) config('app.feedback_admin_path', self::DEFAULT_ADMIN_PATH);
        if ($path === '' || ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        $separator = str_contains($path, '?') ? '&' : '?';

        return $origin.$path.$separator.'ticket='.$ticket->id;
    }
}
