<?php

namespace App\Contracts;

interface ReferralCodeGenerator
{
    public function generate(): string;
}
