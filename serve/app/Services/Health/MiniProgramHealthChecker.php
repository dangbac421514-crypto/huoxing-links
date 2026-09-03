<?php

namespace App\Services\Health;

use App\Enums\LinkType;
use App\Models\Link;
use App\Services\MiniProgramReferencePolicy;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;
use Throwable;

final class MiniProgramHealthChecker implements HealthChecker
{
    private const ERROR_CODE = 'MINI_PROGRAM_HEALTH_FAILED';

    public function __construct(private readonly MiniProgramReferencePolicy $minis) {}

    public function check(Link $link, CarbonImmutable $at): HealthCheckResult
    {
        try {
            if ($this->linkType($link) !== LinkType::MINI_PROGRAM) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $config = $link->getAttribute('config');
            if (! is_array($config)) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $miniId = $this->positiveInteger($config['min_id'] ?? null);
            if ($miniId === null) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $mini = $this->minis->assertAllowed($link->user, $miniId);
            $path = array_key_exists('url', $config) && $config['url'] !== null && $config['url'] !== ''
                ? $config['url']
                : $mini->getAttribute('url');
            if (! is_string($path) || ! $this->validPagePath($path)) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            return HealthCheckResult::healthy();
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

    private function validPagePath(string $path): bool
    {
        return preg_match('/^pages\/[A-Za-z0-9_\/-]+$/D', $path) === 1
            && ! str_contains($path, '..')
            && ! str_contains($path, '//')
            && preg_match('/[\x00-\x20\x7f?#]/', $path) !== 1;
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
