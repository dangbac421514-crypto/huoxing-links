<?php

namespace App\Enums;

enum FeedbackDeliveryStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
}
