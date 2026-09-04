<?php

namespace App\Models;

use App\Enums\FeedbackTicketStatus;
use App\Traits\BelongToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Ugly\Base\Traits\SerializeDate;

class FeedbackTicket extends Model
{
    use BelongToUser, SerializeDate;

    protected $guarded = [];

    protected $hidden = ['contact', 'content'];

    protected $casts = [
        'status' => FeedbackTicketStatus::class,
        'contact' => 'encrypted',
        'content' => 'encrypted',
        'submitted_at' => 'datetime',
        'resolved_at' => 'datetime',
        'anonymized_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(FeedbackChannel::class, 'feedback_channel_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FeedbackAttachment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(FeedbackEvent::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(FeedbackDelivery::class);
    }
}
