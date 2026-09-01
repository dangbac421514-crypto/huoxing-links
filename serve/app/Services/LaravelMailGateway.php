<?php

namespace App\Services;

use App\Contracts\EmailGateway;
use App\Exceptions\BusinessRuleException;
use App\Mail\SendEmail;
use Illuminate\Support\Facades\Mail;

final class LaravelMailGateway implements EmailGateway
{
    public function __construct(private readonly SecretConfigService $secrets) {}

    public function send(string $recipient, string $code, string $template): void
    {
        config(['mail.mailers.runtime_smtp.password' => null]);
        $host = trim((string) SystemConfig::get('mail_host', ''));
        $portValue = SystemConfig::get('mail_port');
        $username = trim((string) SystemConfig::get('mail_username', ''));
        $fromAddress = trim((string) SystemConfig::get('mail_from_address', ''));
        $fromName = (string) SystemConfig::get('mail_from_name', config('app.name'));
        $encryption = strtolower(trim((string) SystemConfig::get('mail_encryption', 'tls')));
        $password = (string) $this->secrets->get('mail_password', '');

        $port = filter_var($portValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($host === '' || $port === false || $username === '' || $fromAddress === '' || $password === '' || ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || ! in_array($encryption, ['tls', 'ssl'], true) || trim($template) === '') {
            throw new BusinessRuleException('EMAIL_NOT_CONFIGURED', 'SMTP 服务尚未配置');
        }

        try {
            config([
                'mail.mailers.runtime_smtp' => [
                    'transport' => 'smtp',
                    'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                    'host' => $host,
                    'port' => $port,
                    'username' => $username,
                    'password' => $password,
                    'timeout' => 8,
                    'from' => [
                        'address' => $fromAddress,
                        'name' => $fromName,
                    ],
                ],
                'mail.from.address' => $fromAddress,
                'mail.from.name' => $fromName,
            ]);

            Mail::purge('runtime_smtp');
            Mail::mailer('runtime_smtp')->to($recipient)->send(new SendEmail($code, $template));
        } catch (BusinessRuleException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new BusinessRuleException('EMAIL_SEND_FAILED', '邮件发送失败', 502);
        } finally {
            Mail::purge('runtime_smtp');
            config([
                'mail.mailers.runtime_smtp.password' => null,
            ]);
        }
    }
}
