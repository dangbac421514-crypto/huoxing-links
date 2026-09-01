<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class LandingSelectionStore
{
    private const TTL_SECONDS = 600;

    private const KEY_PREFIX = 'landing-selection:';

    private const ALLOWED_FIELDS = [
        'id', 'avatar', 'title', 'sub_title', 'qr', 'path', 'name', 'sort',
    ];

    /** @param array<string, mixed> $selection */
    public function put(string $token, array $selection): void
    {
        Cache::store('redis')->put(
            $this->key($token),
            $this->normalize($selection),
            self::TTL_SECONDS,
        );
    }

    /** @return array<string, scalar|null>|null */
    public function get(string $token): ?array
    {
        $selection = Cache::store('redis')->get($this->key($token));
        if (! is_array($selection)) {
            return null;
        }

        return $this->normalize($selection);
    }

    private function key(string $token): string
    {
        return self::KEY_PREFIX.hash('sha256', $token);
    }

    /** @param array<string, mixed> $selection */
    /** @return array<string, scalar|null> */
    private function normalize(array $selection): array
    {
        $normalized = [];
        foreach ($selection as $field => $value) {
            if (in_array($field, self::ALLOWED_FIELDS, true) && (is_scalar($value) || $value === null)) {
                $normalized[$field] = $value;
            }
        }

        return $normalized;
    }
}
