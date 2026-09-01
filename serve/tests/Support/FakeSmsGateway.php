<?php

namespace Tests\Support;

use App\Contracts\SmsGateway;

final class FakeSmsGateway implements SmsGateway
{
    /**
     * @var array<int, array{recipient: string, code: string, template: string}>
     */
    public array $messages = [];

    public function send(string $recipient, string $code, string $template): void
    {
        $this->messages[] = compact('recipient', 'code', 'template');
    }

    public function lastCode(string $recipient): string
    {
        return collect($this->messages)
            ->last(fn (array $message): bool => $message['recipient'] === $recipient)['code'];
    }
}
