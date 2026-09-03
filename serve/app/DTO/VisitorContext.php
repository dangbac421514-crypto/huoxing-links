<?php

namespace App\DTO;

final readonly class VisitorContext
{
    public function __construct(
        public string $visitorId,
        public string $hash,
        public ?string $token = null,
    ) {}

    public static function fromIdentity(VisitorIdentity $identity): self
    {
        return new self($identity->visitorId, $identity->hash);
    }

    public static function anonymous(): self
    {
        return new self('anonymous', hash('sha256', 'anonymous'));
    }

    public function isAnonymous(): bool
    {
        return $this->visitorId === 'anonymous';
    }
}
