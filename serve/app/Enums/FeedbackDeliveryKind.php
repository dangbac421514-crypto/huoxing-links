<?php

namespace App\Enums;

enum FeedbackDeliveryKind: string
{
    case TICKET = 'ticket';
    case TEST = 'test';
}
