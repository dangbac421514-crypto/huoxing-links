<?php

namespace App\Exceptions;

use App\Support\LinkError;

final class QrUnavailable extends LinkResolutionException
{
    public function __construct()
    {
        parent::__construct(LinkError::QR_UNAVAILABLE);
    }
}
