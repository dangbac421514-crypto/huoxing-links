<?php

namespace App\Services\Health;

use App\Enums\LinkType;
use App\Models\Link;
use App\Services\Http\SafeHttpClient;
use App\Services\Http\UrlPolicy;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;
use Throwable;

final class WorkWechatHealthChecker implements HealthChecker
{
    private const ERROR_CODE = 'WORK_WECHAT_HEALTH_FAILED';

    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly UrlPolicy $policy,
    ) {}

    public function check(Link $link, CarbonImmutable $at): HealthCheckResult
    {
        try {
            if ($this->linkType($link) !== LinkType::WORK_WECHAT) {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $config = $link->getAttribute('config');
            $url = is_array($config) ? ($config['url'] ?? null) : null;
            if (! is_string($url) || $url === '') {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            $uri = $this->policy->assertExternal($url, ['work.weixin.qq.com']);
            if ($uri->getPath() === '' || $uri->getPath() === '/') {
                return HealthCheckResult::unhealthy(self::ERROR_CODE);
            }

            // SafeHttpClient applies connection/response timeouts, bounded
            // retries, body limits, allowlist and redirect checks.
            $this->http->getText((string) $uri, ['work.weixin.qq.com']);

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
