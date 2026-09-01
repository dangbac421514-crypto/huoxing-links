<?php

namespace App\DTO;

final readonly class VisitorIdentity
{
    public function __construct(
        public string $visitorId,
        public string $hash,
        public bool $setCookie,
    ) {}
}
