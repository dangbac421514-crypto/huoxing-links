<?php

namespace App\Exceptions;

use App\Support\LinkError;

final class UnsafeUrl extends LinkResolutionException
{
    public function __construct(string $message = '')
    {
        parent::__construct(LinkError::UNSAFE_URL, $message !== '' ? $message : 'External target is unavailable.', 422);
    }
}
