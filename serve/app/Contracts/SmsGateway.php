<?php

namespace App\Contracts;

interface SmsGateway
{
    public function send(string $recipient, string $code, string $template): void;
}
