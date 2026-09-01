<?php

namespace App\Exceptions;

use App\Support\LinkError;

final class MiniProgramForbidden extends LinkResolutionException
{
    public function __construct(string $message = '无权引用该小程序')
    {
        parent::__construct(LinkError::MINI_PROGRAM_FORBIDDEN, $message, 403);
    }
}
