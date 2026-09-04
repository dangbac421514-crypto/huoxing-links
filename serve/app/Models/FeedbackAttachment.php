<?php

namespace App\Models;

use App\Traits\BelongToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ugly\Base\Traits\SerializeDate;

class FeedbackAttachment extends Model
{
    use BelongToUser, SerializeDate;

    protected $guarded = [];

    protected $hidden = ['original_name'];

    protected $casts = [
        'original_name' => 'encrypted',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(FeedbackTicket::class, 'feedback_ticket_id');
    }
}
