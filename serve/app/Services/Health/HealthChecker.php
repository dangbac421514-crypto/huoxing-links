<?php

namespace App\Services\Health;

use App\Models\Link;
use Carbon\CarbonImmutable;

interface HealthChecker
{
    public function check(Link $link, CarbonImmutable $at): HealthCheckResult;
}
