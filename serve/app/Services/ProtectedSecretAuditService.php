<?php

namespace App\Services;

use App\Models\ProtectedSecretAudit;

final class ProtectedSecretAuditService
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(
        string $eventName,
        string $keyIdentifier,
        ?int $actorUserId,
        string $source,
        array $context = [],
    ): ProtectedSecretAudit {
        $safeContext = [];
        foreach (['operation', 'resource_type', 'resource_id'] as $key) {
            if (array_key_exists($key, $context) && is_scalar($context[$key])) {
                $safeContext[$key] = $context[$key];
            }
        }

        $now = now();

        return ProtectedSecretAudit::query()->create([
            'actor_user_id' => $actorUserId,
            'event_name' => $eventName,
            'key_identifier' => $keyIdentifier,
            'source' => $source,
            'context' => $safeContext === [] ? null : $safeContext,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function currentActorId(): ?int
    {
        foreach (['api', 'web'] as $guard) {
            try {
                $id = auth($guard)->id();
            } catch (\Throwable) {
                $id = null;
            }

            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }
}
