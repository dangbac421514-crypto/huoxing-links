<?php

namespace App\Models;

use App\Traits\BelongToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ugly\Base\Traits\SerializeDate;

class FeedbackEvent extends Model
{
    use BelongToUser, SerializeDate;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['note'];

    protected $casts = [
        'note' => 'encrypted',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(FeedbackTicket::class, 'feedback_ticket_id');
    }
}
