<?php

namespace App\Models;

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
            $link->setAttribute('target_version', 1);
        });

        self::updating(function (Link $link): void {
            $rawVersion = $link->getRawOriginal('target_version');
            $version = is_int($rawVersion)
                ? $rawVersion
                : (is_string($rawVersion) && preg_match('/\A[0-9]+\z/D', $rawVersion) === 1 ? (int) $rawVersion : 1);
            $version = max(1, $version);

            $link->setAttribute('target_version', $version === PHP_INT_MAX ? PHP_INT_MAX : $version + 1);
        });
    }

    protected $casts = [
        'config' => 'json',
        'type' => LinkType::class,
        'price' => Amount::class.':4',
        'cache' => 'json',
        'manual_status' => 'boolean',
        'health_status' => 'boolean',
        'target_version' => 'integer',
    ];

    // 访问记录.
    public function visitLogs(): HasMany
    {
        return $this->hasMany(LinkVisitLog::class, 'link_id');
    }
}
