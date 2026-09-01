<?php

namespace App\Services;

use App\Contracts\MiniProgramSchemeClient;
use App\Contracts\MiniProgramSchemeGenerator;
use App\Exceptions\LinkResolutionException;
use App\Models\MiniProgram;
use App\Support\LinkError;
use Throwable;

final class EasyWechatMiniProgramSchemeGenerator implements MiniProgramSchemeGenerator
{
    private readonly WeixinSchemePolicy $schemePolicy;

    public function __construct(
        private readonly MiniProgramSchemeClient $client,
        ?WeixinSchemePolicy $schemePolicy = null,
    ) {
        $this->schemePolicy = $schemePolicy ?? new WeixinSchemePolicy;
    }

    public function generate(MiniProgram $mini, string $path, string $query): string
    {
        try {
            $appId = $mini->getAttribute('app_id');
            $secret = $mini->getAttribute('secret');
            if (! is_string($appId) || $appId === '' || ! is_string($secret) || $secret === '' || $path === '' || $query === '') {
                throw new \RuntimeException('invalid mini-program configuration');
            }

            $payload = $this->client->generate($appId, $secret, $path, $query);
            $openlink = $payload['openlink'] ?? null;
            if (! is_string($openlink) || $openlink === '') {
                throw new \RuntimeException('invalid mini-program response');
            }

            return $this->schemePolicy->assert($openlink);
        } catch (Throwable) {
            throw new LinkResolutionException(
                LinkError::MINI_PROGRAM_EXTERNAL_ERROR,
                '小程序跳转暂不可用',
                502,
            );
        }
    }
}
