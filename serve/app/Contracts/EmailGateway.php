<?php

namespace App\Contracts;

interface EmailGateway
{
    public function send(string $recipient, string $code, string $template): void;
}
