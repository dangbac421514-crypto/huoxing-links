<?php

namespace App\DTO;

final readonly class QrSelection
{
    public function __construct(
        public int $sort,
        public string $path,
        public ?string $name = null,
    ) {}
}
