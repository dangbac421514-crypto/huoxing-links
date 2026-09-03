<?php

namespace App\Models;

use App\Casts\InstantCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipChange extends Model
{
    protected $guarded = [];

    protected $casts = [
        'effective_at' => InstantCast::class,
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromPackage(): BelongsTo
    {
        return $this->belongsTo(VipPackage::class, 'from_vip_id');
    }

    public function toPackage(): BelongsTo
    {
        return $this->belongsTo(VipPackage::class, 'to_vip_id');
    }
}
