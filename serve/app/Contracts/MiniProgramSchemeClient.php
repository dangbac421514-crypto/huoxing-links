<?php

namespace App\Contracts;

interface MiniProgramSchemeClient
{
    /**
     * @return array<string, mixed> The provider response, consumed only by
     *                              the EasyWeChat adapter.
     */
    public function generate(string $appId, string $secret, string $path, string $query): array;
}
