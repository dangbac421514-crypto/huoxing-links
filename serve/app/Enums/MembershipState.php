<?php

namespace App\Enums;

enum MembershipState: string
{
    case ADMIN = 'admin';
    case TRIAL = 'trial';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case NONE = 'none';
}
