<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use App\Exceptions\BusinessRuleException;
use Closure;
use Overtrue\EasySms\EasySms;

final class AliyunSmsGateway implements SmsGateway
{
    /**
     * @var Closure(array<string, mixed>): object
     */
    private readonly Closure $clientFactory;

    /**
     * @param  callable(array<string, mixed>): object|null  $clientFactory
     */
    public function __construct(
        private readonly SecretConfigService $secrets,
        ?callable $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory === null
            ? static fn (array $config): EasySms => new EasySms($config)
            : Closure::fromCallable($clientFactory);
    }

    public function send(string $recipient, string $code, string $template): void
    {
        $key = (string) $this->secrets->get('ali_sms_key', '');
        $secret = (string) $this->secrets->get('ali_sms_secret', '');
        $signName = trim((string) SystemConfig::get('ali_sms_sign_name', ''));

        if ($key === '' || $secret === '' || $signName === '' || trim($template) === '') {
            throw new BusinessRuleException('SMS_NOT_CONFIGURED', '短信服务尚未配置');
        }

        try {
            $client = ($this->clientFactory)([
                'timeout' => 5,
                'default' => ['gateways' => ['aliyun']],
                'gateways' => [
                    'aliyun' => [
                        'access_key_id' => $key,
                        'access_key_secret' => $secret,
                        'sign_name' => $signName,
                    ],
                ],
            ]);
            $client->send($recipient, [
                'template' => $template,
                'data' => ['code' => $code],
            ], ['aliyun']);
        } catch (BusinessRuleException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new BusinessRuleException('SMS_SEND_FAILED', '短信发送失败', 502);
        }
    }
}
