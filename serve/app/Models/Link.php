<?php

namespace App\Models;

use App\Casts\InstantCast;
use App\Enums\LinkType;
use App\Traits\BelongToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Ugly\Base\Casts\Amount;
use Ugly\Base\Traits\SearchModel;
use Ugly\Base\Traits\SerializeDate;

class Link extends Model
{
    use BelongToUser,SearchModel,SerializeDate;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(function (Link $link) {
            $link->code = Str::random(8);
            $link->setAttribute('target_version', (string) Str::uuid());
        });

        self::updating(function (Link $link): void {
            // target_version is server-owned. A caller-supplied value is
            // ignored and every normal Eloquent update gets a new revision.
            $link->setAttribute('target_version', (string) Str::uuid());
        });
    }

    protected $casts = [
        'config' => 'json',
        'type' => LinkType::class,
        'price' => Amount::class.':4',
        'cache' => 'json',
        'manual_status' => 'boolean',
        'health_status' => 'boolean',
        'health_checked_at' => InstantCast::class,
        'health_error_code' => 'string',
        'target_version' => 'string',
    ];

    // 访问记录.
    public function visitLogs(): HasMany
    {
        return $this->hasMany(LinkVisitLog::class, 'link_id');
    }
}
