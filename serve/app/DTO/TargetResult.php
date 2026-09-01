<?php

namespace App\DTO;

/** Public, secret-free result produced by a later target resolver. */
final readonly class TargetResult
{
    public function __construct(
        public string $title,
        public string $description,
        public ?string $icon,
        public string $target,
        public ?array $qr = null,
        public ?string $visitorToken = null,
    ) {}

    /** @param array<string, mixed> $attributes */
    public static function from(array $attributes): self
    {
        return new self(
            (string) ($attributes['title'] ?? ''),
            (string) ($attributes['description'] ?? ''),
            isset($attributes['icon']) ? (string) $attributes['icon'] : null,
            (string) ($attributes['target'] ?? ''),
            self::safeQr($attributes['qr'] ?? null),
            isset($attributes['visitorToken']) && is_string($attributes['visitorToken']) ? $attributes['visitorToken'] : null,
        );
    }

    /** @return ?array<string, scalar|null> */
    private static function safeQr(mixed $qr): ?array
    {
        if (! is_array($qr)) {
            return null;
        }

        $safe = [];
        foreach (['id', 'name', 'path', 'sort', 'avatar', 'title', 'sub_title', 'url'] as $key) {
            if (array_key_exists($key, $qr) && (is_scalar($qr[$key]) || $qr[$key] === null)) {
                $safe[$key] = $qr[$key];
            }
        }

        return $safe;
    }
}
