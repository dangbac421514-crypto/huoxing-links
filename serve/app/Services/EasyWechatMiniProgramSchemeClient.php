<?php

namespace App\Services;

use App\Contracts\MiniProgramSchemeClient;
use EasyWeChat\MiniApp\Application as WechatMiniApp;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class EasyWechatMiniProgramSchemeClient implements MiniProgramSchemeClient
{
    private const API_BASE_URI = 'https://api.weixin.qq.com/';

    private const REQUEST_TIMEOUT = 3.0;

    private const REQUEST_MAX_DURATION = 8.0;

    private const MAX_RETRIES = 2;

    public function __construct(private readonly ?HttpClientInterface $httpClient = null) {}

    /** @return array<string, mixed> */
    public function generate(string $appId, string $secret, string $path, string $query): array
    {
        try {
            $transport = ($this->httpClient ?? HttpClient::create())->withOptions([
                'base_uri' => self::API_BASE_URI,
                // Symfony's timeout is the inactivity timeout; the connect
                // duration is bounded separately and the overall request is
                // capped by max_duration.
                'timeout' => self::REQUEST_TIMEOUT,
                'max_connect_duration' => self::REQUEST_TIMEOUT,
                'max_duration' => self::REQUEST_MAX_DURATION,
            ]);
            $transport = new RetryableHttpClient(
                $transport,
                new GenericRetryStrategy(
                    $this->retryStatusCodes(),
                    0,
                    1.0,
                    0,
                    0.0,
                ),
                self::MAX_RETRIES,
            );
            $application = new WechatMiniApp([
                'app_id' => $appId,
                'secret' => $secret,
                'http' => [
                    'timeout' => self::REQUEST_TIMEOUT,
                    'max_connect_duration' => self::REQUEST_TIMEOUT,
                    'max_duration' => self::REQUEST_MAX_DURATION,
                    'throw' => true,
                ],
            ]);
            $application->setHttpClient($transport);

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

    /** @return array<int, list<string>> */
    private function retryStatusCodes(): array
    {
        $statusCodes = [0 => ['GET', 'POST']];
        foreach (range(500, 599) as $statusCode) {
            $statusCodes[$statusCode] = ['GET', 'POST'];
        }

        return $statusCodes;
    }
}
