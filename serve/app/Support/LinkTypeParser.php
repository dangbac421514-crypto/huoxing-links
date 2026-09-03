<?php

namespace App\Support;

use App\Enums\LinkType;

/** Parse persisted/requested link types without PHP's loose numeric coercion. */
final class LinkTypeParser
{
    public static function parse(mixed $raw): ?LinkType
    {
        if (is_int($raw)) {
            return LinkType::tryFrom($raw);
        }

        if (! is_string($raw) || ! preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw)) {
            return null;
        }

        return LinkType::tryFrom((int) $raw);
    }

    public static function normalize(mixed $raw): mixed
    {
        if (is_int($raw)) {
            return $raw;
        }

        return is_string($raw) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw)
            ? (int) $raw
            : $raw;
    }
}
