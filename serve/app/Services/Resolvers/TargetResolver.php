<?php

namespace App\Services\Resolvers;

use App\DTO\TargetResult;
use App\DTO\VisitorContext;
use App\Models\Link;

interface TargetResolver
{
    public function resolve(Link $link, VisitorContext $visitor): TargetResult;
}
