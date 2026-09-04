<?php

namespace App\Models;

use App\Enums\FeedbackDeliveryKind;
use App\Enums\FeedbackDeliveryStatus;
use App\Traits\BelongToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ugly\Base\Traits\SerializeDate;

class FeedbackDelivery extends Model
{
    use BelongToUser, SerializeDate;

    protected $guarded = [];

    protected $casts = [
        'kind' => FeedbackDeliveryKind::class,
        'status' => FeedbackDeliveryStatus::class,
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(FeedbackChannel::class, 'feedback_channel_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(FeedbackTicket::class, 'feedback_ticket_id');
    }
}
