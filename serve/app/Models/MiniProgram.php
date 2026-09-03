<?php

namespace App\Models;

use App\Enums\MiniType;
use App\Services\ProtectedSecretAuditService;
use App\Traits\BelongToUser;
use Illuminate\Database\Eloquent\Model;
use Ugly\Base\Traits\SearchModel;
use Ugly\Base\Traits\SerializeDate;

class MiniProgram extends Model
{
    use BelongToUser, SearchModel, SerializeDate;

    protected $guarded = [];

    protected $casts = [
        'type' => MiniType::class,
        'secret' => 'encrypted',
    ];

    protected $hidden = ['secret'];

    protected static function booted(): void
    {
        static::saved(function (MiniProgram $miniProgram): void {
            if (
                (! $miniProgram->wasRecentlyCreated && ! $miniProgram->wasChanged('secret'))
                || ($miniProgram->wasRecentlyCreated && ! filled($miniProgram->getAttributes()['secret'] ?? null))
            ) {
                return;
            }

            $audits = app(ProtectedSecretAuditService::class);
            $audits->record(
                'protected_secret.set',
                'mini_program:'.$miniProgram->getKey().':secret',
                $audits->currentActorId(),
                'mini_program',
                [
                    'operation' => 'set',
                    'resource_type' => 'mini_program',
                    'resource_id' => (int) $miniProgram->getKey(),
                ],
            );
        });

        static::deleted(function (MiniProgram $miniProgram): void {
            if (! filled($miniProgram->getRawOriginal('secret'))) {
                return;
            }

            $audits = app(ProtectedSecretAuditService::class);
            $audits->record(
                'protected_secret.invalidated',
                'mini_program:'.$miniProgram->getKey().':secret',
                $audits->currentActorId(),
                'mini_program',
                [
                    'operation' => 'invalidated',
                    'resource_type' => 'mini_program',
                    'resource_id' => (int) $miniProgram->getKey(),
                ],
            );
        });
    }
}
