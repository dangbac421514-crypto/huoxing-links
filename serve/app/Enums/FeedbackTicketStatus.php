<?php

namespace App\Enums;

enum FeedbackTicketStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';
}
