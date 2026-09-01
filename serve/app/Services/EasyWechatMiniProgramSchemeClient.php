<?php

namespace App\Services;

use App\Contracts\MiniProgramSchemeClient;
use EasyWeChat\MiniApp\Application as WechatMiniApp;
use Throwable;

final class EasyWechatMiniProgramSchemeClient implements MiniProgramSchemeClient
{
    /** @return array<string, mixed> */
    public function generate(string $appId, string $secret, string $path, string $query): array
    {
        try {
            $application = new WechatMiniApp([
                'app_id' => $appId,
                'secret' => $secret,
            ]);

            $response = $application->getClient()->postJson('wxa/generatescheme', [
                'jump_wxa' => [
                    'path' => $path,
                    'query' => $query,
                ],
            ]);

            $payload = $response->toArray();
            $openlink = is_array($payload) ? ($payload['openlink'] ?? null) : null;
            if (! is_string($openlink) || $openlink === '') {
                throw new \RuntimeException('invalid mini-program response');
            }

            // Do not let provider fields or error payloads cross the adapter
            // boundary; the generator receives only the openlink value.
            return ['openlink' => $openlink];
        } catch (Throwable) {
            throw new \RuntimeException('Mini program provider request failed');
        }
    }
}
