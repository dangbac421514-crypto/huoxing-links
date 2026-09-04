<?php

namespace App\Models;

use App\Traits\BelongToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ugly\Base\Traits\SerializeDate;

class FeedbackChannel extends Model
{
    use BelongToUser, SerializeDate;

    protected $guarded = [];

    protected $hidden = ['webhook_url'];

    protected $casts = [
        'status' => 'boolean',
        'contact_required' => 'boolean',
        'categories' => 'array',
        'retention_days' => 'integer',
        'webhook_url' => 'encrypted',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FeedbackTicket::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(FeedbackDelivery::class);
    }
}
