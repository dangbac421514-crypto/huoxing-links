<?php

namespace App\Models;

use App\Casts\InstantCast;
use App\Enums\VipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ugly\Base\Models\Payment;

class VipLogs extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => VipStatus::class,
        'start_at' => InstantCast::class,
        'end_at' => InstantCast::class,
        'effective_at' => InstantCast::class,
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
    ];

    // 对应的payment
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // 对应的会员套餐
    public function vipPackage(): BelongsTo
    {
        return $this->belongsTo(VipPackage::class, 'vip_id');
    }
}
