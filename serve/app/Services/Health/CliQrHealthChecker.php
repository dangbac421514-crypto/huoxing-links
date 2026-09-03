<?php

namespace App\Services\Health;

use App\DTO\VisitorContext;
use App\Enums\LinkType;
use App\Models\Link;
use App\Services\Resolvers\CliQrTargetResolver;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;
use Throwable;

final class CliQrHealthChecker implements HealthChecker
{
    private const ERROR_CODE = 'CLI_QR_HEALTH_FAILED';

    public function __construct(private readonly CliQrTargetResolver $resolver) {}

    public function check(Link $link, CarbonImmutable $at): HealthCheckResult
    {
        try {
            if ($this->linkType($link) !== LinkType::CLI_QR) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $this->resolver->resolve($link, VisitorContext::anonymous());

            return HealthCheckResult::healthy();
        } catch (Throwable) {
            return HealthCheckResult::unhealthy(self::ERROR_CODE);
        }
    }

    private function linkType(Link $link): ?LinkType
    {
        $rawType = $link->getRawOriginal('type');

        return $rawType instanceof LinkType ? $rawType : LinkTypeParser::parse($rawType);
    }
}
