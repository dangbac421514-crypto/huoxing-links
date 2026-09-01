<?php

namespace App\Services;

use App\DTO\TargetResult;
use App\Enums\LinkType;
use App\Models\Link;
use App\Support\LinkTypeParser;
use Illuminate\Support\Facades\Cache;

/**
 * Redis-backed cache for the public target fields of non-landing links.
 *
 * The key deliberately contains no short code, URL, title, visitor data or
 * provider configuration. Link updates advance the versioned key through the
 * persisted updated_at value; old entries are left to their short TTL.
 */
final class PublicTargetCache
{
    private const TTL_SECONDS = 300;

    private const KEY_PREFIX = 'link-target:v1:';

    public function __construct(private readonly SanitizedLinkVisitRecorder $sanitizer) {}

    /** @return array<string, scalar|null>|null */
    public function get(Link $link): ?array
    {
        $type = LinkTypeParser::parse($link->getRawOriginal('type'));
        if ($type === null || $type === LinkType::LANDING_MINI) {
            return null;
        }

        $cached = Cache::store('redis')->get($this->key($link, $type->value));
        if (! is_array($cached)) {
            return null;
        }

        $target = $this->sanitizer->publicTarget($cached);
        foreach (['title', 'description', 'icon', 'target'] as $field) {
            if (! array_key_exists($field, $target)) {
                return null;
            }
        }
        if (! isset($target['target']) || ! is_string($target['target']) || $target['target'] === '') {
            return null;
        }

        return $target;
    }

    /**
     * @param  TargetResult|array<string, mixed>  $target
     */
    public function put(Link $link, TargetResult|array $target): void
    {
        $type = LinkTypeParser::parse($link->getRawOriginal('type'));
        if ($type === null || $type === LinkType::LANDING_MINI) {
            return;
        }

        $publicTarget = $this->sanitizer->publicTarget($target);
        if (! isset($publicTarget['target']) || ! is_string($publicTarget['target']) || $publicTarget['target'] === '') {
            return;
        }

        Cache::store('redis')->put(
            $this->key($link, $type->value),
            $publicTarget,
            self::TTL_SECONDS,
        );
    }

    /**
     * Exposed narrowly for deterministic cache/privacy tests; callers should
     * use get/put for normal operations.
     */
    public function key(Link $link, ?int $strictType = null): string
    {
        $type = $strictType ?? LinkTypeParser::parse($link->getRawOriginal('type'))?->value;
        if ($type === null) {
            return self::KEY_PREFIX.'unknown:'.$link->getKey().':'.$this->version($link);
        }

        return self::KEY_PREFIX.$link->getKey().':'.$type.':'.$this->version($link);
    }

    private function version(Link $link): string
    {
        $updatedAt = $link->getRawOriginal('updated_at');

        return hash('sha256', is_scalar($updatedAt) ? (string) $updatedAt : '0');
    }
}
