<?php

namespace App\DTO;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * The complete public response produced by the link coordinator.
 *
 * Keeping the envelope and status together means the HTTP controller has no
 * link-resolution branches of its own. The optional cookie is only attached
 * by the adapter when the server minted a new visitor identity.
 */
final readonly class CoordinatorResponse
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
        public int $status = 200,
        public ?Cookie $cookie = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function success(array $data): self
    {
        return new self([
            'code' => 0,
            'data' => $data,
        ]);
    }

    public static function error(string $code, int $status, string $message = '请求暂时无法处理'): self
    {
        return new self([
            'code' => $code,
            'message' => $message,
        ], $status);
    }

    public function withCookie(Cookie $cookie): self
    {
        return new self($this->payload, $this->status, $cookie);
    }
}
