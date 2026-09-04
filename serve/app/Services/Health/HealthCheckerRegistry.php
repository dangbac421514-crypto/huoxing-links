<?php

namespace App\Services\Health;

use App\Enums\LinkType;
use App\Exceptions\LinkResolutionException;
use App\Support\LinkError;
use App\Support\LinkTypeParser;

final class HealthCheckerRegistry
{
    public function __construct(
        private readonly MiniProgramHealthChecker $miniProgram,
        private readonly KingDocHealthChecker $kingDoc,
        private readonly CliQrHealthChecker $cliQr,
        private readonly WorkWechatHealthChecker $workWechat,
        private readonly LandingMiniHealthChecker $landingMini,
        private readonly QqQrHealthChecker $qqQr,
    ) {}

    public function for(mixed $rawType): HealthChecker
    {
        $type = $rawType instanceof LinkType ? $rawType : LinkTypeParser::parse($rawType);
        if (! $type) {
            throw new LinkResolutionException(LinkError::LINK_TYPE_UNSUPPORTED, '链接类型暂不支持', 422);
        }

        return match ($type) {
            LinkType::MINI_PROGRAM => $this->miniProgram,
            LinkType::KING_DOC => $this->kingDoc,
            LinkType::CLI_QR => $this->cliQr,
            LinkType::WORK_WECHAT => $this->workWechat,
            LinkType::LANDING_MINI => $this->landingMini,
            LinkType::QR_QQ => $this->qqQr,
        };
    }
}
