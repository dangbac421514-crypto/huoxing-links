<?php

namespace App\Services;

use App\DTO\TargetResult;
use App\Models\LinkVisitLog;

final class SanitizedLinkVisitRecorder
{
    /**
     * Public visit data is deliberately a small, top-level scalar whitelist.
     * Legacy cache payloads may still be read by migration tooling, but they
     * are never copied into a newly resolved visit row.
     *
     * @var list<string>
     */
    private const PUBLIC_FIELDS = ['title', 'description', 'icon', 'target'];

    /**
     * @param  TargetResult|array<string, mixed>  $result
     * @return array<string, scalar|null>
     */
    public function publicTarget(TargetResult|array $result): array
    {
        if ($result instanceof TargetResult) {
            $result = [
                'title' => $result->title,
                'description' => $result->description,
                'icon' => $result->icon,
                'target' => $result->target,
            ];
        }

        $sanitized = [];
        foreach (self::PUBLIC_FIELDS as $field) {
            if (array_key_exists($field, $result) && (is_scalar($result[$field]) || $result[$field] === null)) {
                $sanitized[$field] = $result[$field];
            }
        }

        return $sanitized;
    }

    public function sanitize(mixed $cache): array
    {
        return is_array($cache) ? $this->publicTarget($cache) : [];
    }

    /**
     * Persist one already-resolved, fully sanitized visit.
     *
     * @param  TargetResult|array<string, mixed>  $publicTarget
     */
    public function createSanitized(
        int $linkId,
        int $userId,
        string $visitorHash,
        ?string $ipHash,
        ?string $userAgentHash,
        TargetResult|array $publicTarget,
    ): LinkVisitLog {
        return LinkVisitLog::query()->create([
            'link_id' => $linkId,
            'user_id' => $userId,
            'visitor_hash' => $visitorHash,
            'ip_hash' => $ipHash,
            'user_agent_hash' => $userAgentHash,
            'cache' => $this->publicTarget($publicTarget),
        ]);
    }

    /**
     * Descriptive alias for callers that prefer the domain operation name.
     *
     * @param  TargetResult|array<string, mixed>  $publicTarget
     */
    public function recordResolved(
        int $linkId,
        int $userId,
        string $visitorHash,
        ?string $ipHash,
        ?string $userAgentHash,
        TargetResult|array $publicTarget,
    ): LinkVisitLog {
        return $this->createSanitized(
            $linkId,
            $userId,
            $visitorHash,
            $ipHash,
            $userAgentHash,
            $publicTarget,
        );
    }

    /**
     * Explicit name for integrations that describe the operation as a visit.
     *
     * @param  TargetResult|array<string, mixed>  $publicTarget
     */
    public function recordResolvedVisit(
        int $linkId,
        int $userId,
        string $visitorHash,
        ?string $ipHash,
        ?string $userAgentHash,
        TargetResult|array $publicTarget,
    ): LinkVisitLog {
        return $this->recordResolved(
            $linkId,
            $userId,
            $visitorHash,
            $ipHash,
            $userAgentHash,
            $publicTarget,
        );
    }

    public function record(array $attributes): LinkVisitLog
    {
        if (isset($attributes['cache']) && is_array($attributes['cache'])) {
            $attributes['cache'] = $this->sanitize($attributes['cache']);
        }

        return LinkVisitLog::query()->create($attributes);
    }
}
