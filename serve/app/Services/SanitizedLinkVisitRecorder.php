<?php

namespace App\Services;

use App\Models\LinkVisitLog;

final class SanitizedLinkVisitRecorder
{
    public function sanitize(mixed $cache): array
    {
        if (! is_array($cache)) {
            return [];
        }

        if (isset($cache['params']) && is_array($cache['params'])) {
            unset($cache['params']['secret']);
        }

        return $cache;
    }

    public function record(array $attributes): LinkVisitLog
    {
        if (isset($attributes['cache']) && is_array($attributes['cache'])) {
            $attributes['cache'] = $this->sanitize($attributes['cache']);
        }

        return LinkVisitLog::query()->create($attributes);
    }
}
