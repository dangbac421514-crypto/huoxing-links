<?php

namespace App\Models;

use App\Casts\InstantCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UsagePeriod extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_start' => InstantCast::class,
        'period_end' => InstantCast::class,
        'used_uv' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(UsageVisitor::class);
    }
}
