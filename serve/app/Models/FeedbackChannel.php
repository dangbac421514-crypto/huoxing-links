<?php

namespace App\Models;

use App\Services\ProtectedSecretAuditService;
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
        'webhook_configured_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (FeedbackChannel $channel): void {
            if ($channel->wasRecentlyCreated && ! filled($channel->webhook_url)) {
                return;
            }
            if (! $channel->wasRecentlyCreated && ! $channel->wasChanged('webhook_url')) {
                return;
            }

            $audits = app(ProtectedSecretAuditService::class);
            $configured = filled($channel->webhook_url);
            $audits->record(
                $configured ? 'protected_secret.set' : 'protected_secret.invalidated',
                'feedback_channel:'.$channel->getKey().':webhook_url',
                $audits->currentActorId(),
                'feedback_channel',
                [
                    'operation' => $configured ? 'set' : 'invalidated',
                    'resource_type' => 'feedback_channel',
                    'resource_id' => (int) $channel->getKey(),
                ],
            );
        });
    }

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
