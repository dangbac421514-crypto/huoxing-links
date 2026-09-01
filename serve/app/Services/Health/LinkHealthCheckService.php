<?php

namespace App\Services\Health;

use App\Enums\LinkType;
use App\Models\Link;
use App\Support\LinkError;
use App\Support\LinkTypeParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LinkHealthCheckService
{
    /** @var array<int, string> */
    private const ERROR_CODES = [
        LinkType::MINI_PROGRAM->value => 'MINI_PROGRAM_HEALTH_FAILED',
        LinkType::KING_DOC->value => 'KING_DOC_HEALTH_FAILED',
        LinkType::CLI_QR->value => 'CLI_QR_HEALTH_FAILED',
        LinkType::WORK_WECHAT->value => 'WORK_WECHAT_HEALTH_FAILED',
        LinkType::LANDING_MINI->value => 'LANDING_MINI_HEALTH_FAILED',
        LinkType::QR_QQ->value => 'QQ_QR_HEALTH_FAILED',
    ];

    public function __construct(private readonly HealthCheckerRegistry $checkers) {}

    public function check(Link $link, CarbonImmutable $at): void
    {
        $key = $link->getKey();
        if (! $this->validKey($key)) {
            return;
        }

        $type = $this->linkType($link);
        $failureCode = $type === null
            ? LinkError::LINK_TYPE_UNSUPPORTED
            : self::ERROR_CODES[$type->value];
        $result = null;

        try {
            if ($type === null) {
                throw new \RuntimeException('unsupported link type');
            }

            $result = $this->checkers->for($type)->check($link, $at);
        } catch (Throwable) {
            $result = HealthCheckResult::unhealthy($failureCode);
        }

        $healthy = $result instanceof HealthCheckResult && $result->healthy;
        $checkedAt = $at->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');

        // Do not use Eloquent here: Link's updating event rotates
        // target_version and Eloquent timestamps update updated_at. Health
        // checks own exactly these three columns and no others.
        DB::table('links')->where('id', $key)->update([
            'health_status' => $healthy ? 1 : 0,
            'health_checked_at' => $checkedAt,
            'health_error_code' => $healthy ? null : $failureCode,
        ]);
    }

    private function linkType(Link $link): ?LinkType
    {
        $rawType = $link->getRawOriginal('type');

        return $rawType instanceof LinkType ? $rawType : LinkTypeParser::parse($rawType);
    }

    private function validKey(mixed $key): bool
    {
        if (is_int($key)) {
            return $key > 0;
        }

        return is_string($key) && preg_match('/^[1-9][0-9]*$/D', $key) === 1;
    }
}
