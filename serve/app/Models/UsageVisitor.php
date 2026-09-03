<?php

namespace App\Models;

use App\Casts\InstantCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageVisitor extends Model
{
    protected $guarded = [];

    protected $casts = [
        'first_seen_at' => InstantCast::class,
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(UsagePeriod::class, 'usage_period_id');
    }
}
