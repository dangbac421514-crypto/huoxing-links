<?php

namespace App\Services;

use App\DTO\TargetResult;
use App\Enums\LinkType;
use App\Models\Link;
use App\Support\LinkTypeParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Redis-backed cache for the public target fields of non-landing links.
 *
 * The key deliberately contains no short code, URL, title, visitor data or
 * provider configuration. Link updates advance the versioned key through the
 * persisted target_version value; old entries are left to their short TTL.
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

        $key = $this->key($link, $type->value);
        if ($key === null) {
            return null;
        }

        $cached = Cache::store('redis')->get($key);
        if (! is_array($cached)) {
            if ($cached !== null) {
                Cache::store('redis')->forget($key);
            }

            return null;
        }

        $target = $this->sanitizer->publicTarget($cached);
        foreach (['title', 'description', 'icon', 'target'] as $field) {
            if (! array_key_exists($field, $target)) {
                Cache::store('redis')->forget($key);

                return null;
            }
        }
        if (! isset($target['target']) || ! is_string($target['target']) || $target['target'] === '') {
            Cache::store('redis')->forget($key);

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

        $key = $this->key($link, $type->value);
        if ($key === null) {
            return;
        }

        Cache::store('redis')->put(
            $key,
            $publicTarget,
            self::TTL_SECONDS,
        );
    }

    public function forget(Link $link): void
    {
        $type = LinkTypeParser::parse($link->getRawOriginal('type'));
        if ($type === null || $type === LinkType::LANDING_MINI) {
            return;
        }

        $key = $this->key($link, $type->value);
        if ($key === null) {
            return;
        }

        Cache::store('redis')->forget($key);
    }

    /**
     * Exposed narrowly for deterministic cache/privacy tests; callers should
     * use get/put for normal operations.
     */
    public function key(Link $link, ?int $strictType = null): ?string
    {
        $parsedType = LinkTypeParser::parse($strictType ?? $link->getRawOriginal('type'));
        $version = $this->version($link);
        if ($parsedType === null || $parsedType === LinkType::LANDING_MINI || $version === null) {
            return null;
        }

        return self::KEY_PREFIX.$link->getKey().':'.$parsedType->value.':'.$version;
    }

    private function version(Link $link): ?string
    {
        $rawVersion = $link->getRawOriginal('target_version');
        if (! is_string($rawVersion) || ! Str::isUuid($rawVersion)) {
            return null;
        }

        return strtolower($rawVersion);
    }
}
