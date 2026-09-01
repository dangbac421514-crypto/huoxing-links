<?php

namespace App\Models;

use App\Traits\BelongToUser;
use Illuminate\Database\Eloquent\Model;

class LinkVisitLog extends Model
{
    use BelongToUser;

    /**
     * Only the sanitized visit representation is mass assignable.
     *
     * Legacy identity columns remain readable for old records, but are not
     * writable through the new visit-recording path.
     *
     * @var list<string>
     */
    protected $fillable = [
        'link_id',
        'user_id',
        'visitor_hash',
        'ip_hash',
        'user_agent_hash',
        'cache',
    ];

    protected $casts = [
        'cache' => 'json',
    ];
}
