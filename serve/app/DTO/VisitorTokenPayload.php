<?php

namespace App\DTO;

final readonly class VisitorTokenPayload
{
    public function __construct(
        public string $visitorId,
        public string $code,
        public int $expiresAt,
        public string $jti,
    ) {}
}
