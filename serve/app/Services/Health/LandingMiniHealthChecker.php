<?php

namespace App\Services\Health;

use App\Enums\LinkType;
use App\Enums\MiniType;
use App\Models\Link;
use App\Services\MiniProgramReferencePolicy;
use App\Services\QrRotationService;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;
use Throwable;

final class LandingMiniHealthChecker implements HealthChecker
{
    private const ERROR_CODE = 'LANDING_MINI_HEALTH_FAILED';

    public function __construct(
        private readonly MiniProgramReferencePolicy $minis,
        private readonly QrRotationService $qrs,
    ) {}

    public function check(Link $link, CarbonImmutable $at): HealthCheckResult
    {
        try {
            if ($this->linkType($link) !== LinkType::LANDING_MINI) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $config = $link->getAttribute('config');
            if (! is_array($config)) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $rawMiniId = $config['min_id'] ?? null;
            if ($rawMiniId === null || $rawMiniId === '') {
                return $this->qrs->configurationHealthy($link, $at)
                    ? HealthCheckResult::healthy()
                    : HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $miniId = $this->positiveInteger($rawMiniId);
            if ($miniId === null) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $mini = $this->minis->assertAllowed($link->user, $miniId);
            if (
                ! (bool) $mini->getAttribute('is_pre_min')
                || $this->miniType($mini->getAttribute('type')) !== MiniType::LANDING
            ) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            return $this->qrs->configurationHealthy($link, $at)
                ? HealthCheckResult::healthy()
                : HealthCheckResult::unhealthy(self::ERROR_CODE);
        } catch (Throwable) {
            return HealthCheckResult::unhealthy(self::ERROR_CODE);
        }
    }

    private function linkType(Link $link): ?LinkType
    {
        $rawType = $link->getRawOriginal('type');
        if ($rawType instanceof LinkType) {
            return $rawType;
        }

        return LinkTypeParser::parse($rawType);
    }

    private function miniType(mixed $rawType): ?MiniType
    {
        if ($rawType instanceof MiniType) {
            return $rawType;
        }
        if (is_int($rawType)) {
            return MiniType::tryFrom($rawType);
        }
        if (is_string($rawType) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $rawType) === 1) {
            return MiniType::tryFrom((int) $rawType);
        }

        return null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $result = filter_var($value, FILTER_VALIDATE_INT);

        return $result === false ? null : (int) $result;
    }
}
