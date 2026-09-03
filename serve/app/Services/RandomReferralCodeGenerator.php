<?php

namespace App\Services;

use App\Contracts\ReferralCodeGenerator;
use Illuminate\Support\Str;

final class RandomReferralCodeGenerator implements ReferralCodeGenerator
{
    public function generate(): string
    {
        return Str::upper(Str::random(8));
    }
}
