<?php

namespace App\Services\Health;

final readonly class HealthCheckResult
{
    public function __construct(public bool $healthy, public ?string $errorCode = null)
    {
        if ($healthy && $errorCode !== null) {
            throw new \InvalidArgumentException('A healthy result cannot have an error code.');
        }

        if (! $healthy && ($errorCode === null || trim($errorCode) === '')) {
            throw new \InvalidArgumentException('An unhealthy result requires an error code.');
        }
    }

    public static function healthy(): self
    {
        return new self(true);
    }

    public static function unhealthy(string $errorCode): self
    {
        return new self(false, $errorCode);
    }
}
