<?php

namespace App\Services;

use App\Enums\UserType;
use App\Exceptions\MiniProgramForbidden;
use App\Models\MiniProgram;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class MiniProgramReferencePolicy
{
    public function assertAllowed(User $actor, int $miniId): MiniProgram
    {
        $mini = MiniProgram::query()->find($miniId);
        if (! $mini || ! (bool) $mini->getAttribute('is_enable')) {
            throw new MiniProgramForbidden;
        }

        $ownerId = (int) $mini->getAttribute('user_id');
        if ($ownerId === (int) $actor->getKey()) {
            return $mini;
        }

        if ($this->isAdmin($actor)) {
            Log::notice('link.mini_program.cross_tenant_reference', [
                'event' => 'link.mini_program.cross_tenant_reference',
                'actor_id' => (int) $actor->getKey(),
                'mini_id' => (int) $mini->getKey(),
                'owner_id' => $ownerId,
            ]);

            return $mini;
        }

        if (! (bool) $mini->getAttribute('is_pre_min')) {
            throw new MiniProgramForbidden;
        }

        return $mini;
    }

    private function isAdmin(User $user): bool
    {
        $type = $user->getAttribute('type');

        return $type === UserType::Admin
            || ((is_int($type) || is_string($type)) && (int) $type === UserType::Admin->value);
    }
}
