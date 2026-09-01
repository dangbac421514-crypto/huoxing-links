<?php

namespace App\Services;

use App\Contracts\EmailGateway;
use App\Contracts\SmsGateway;
use App\Enums\CodeMode;
use App\Exceptions\BusinessRuleException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

final class VerificationCodeService
{
    private const CODE_TTL_SECONDS = 300;

    private const RATE_TTL_SECONDS = 60;

    private const MAX_ATTEMPTS = 5;

    private const PURPOSES = ['register', 'reset_password'];

    public function __construct(
        private readonly SmsGateway $sms,
        private readonly EmailGateway $email,
    ) {}

    public function send(CodeMode $mode, string $recipient, string $ip, string $purpose, string $template): void
    {
        $this->assertPurpose($purpose);
        $prefix = $this->prefix($mode);
        $identity = $this->identity($recipient);
        $ipHash = $this->identity($ip);
        $throttleKey = "verification:rate:{$prefix}:{$purpose}:{$identity}:{$ipHash}";

        $ownerToken = bin2hex(random_bytes(16));
        if (! Redis::set($throttleKey, $ownerToken, 'EX', self::RATE_TTL_SECONDS, 'NX')) {
            throw new BusinessRuleException($prefix.'_RATE_LIMITED', '验证码发送过于频繁');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        try {
            $gateway = $mode === CodeMode::SMS ? $this->sms : $this->email;
            $gateway->send($recipient, $code, $template);
        } catch (BusinessRuleException $exception) {
            $this->releaseThrottle($throttleKey, $ownerToken);
            throw $exception;
        } catch (\Throwable) {
            $this->releaseThrottle($throttleKey, $ownerToken);
            throw new BusinessRuleException($prefix.'_SEND_FAILED', '验证码发送失败', 502);
        }

        $expiresAt = now()->addSeconds(self::CODE_TTL_SECONDS);
        Cache::store('redis')->put($this->codeKey($prefix, $purpose, $identity), [
            'hash' => Hash::make($code),
            'ip_hash' => $ipHash,
            'expires_at' => $expiresAt->timestamp,
            'attempts' => 0,
        ], $expiresAt);
    }

    public function consume(CodeMode $mode, string $recipient, string $ip, string $purpose, string $code): void
    {
        $this->assertPurpose($purpose);
        $prefix = $this->prefix($mode);
        $key = $this->codeKey($prefix, $purpose, $this->identity($recipient));
        $cache = Cache::store('redis');

        try {
            $cache->lock('verification:lock:'.$key, 5)->block(1, function () use ($cache, $key, $prefix, $ip, $code): void {
                $payload = $cache->get($key);
                if (! is_array($payload) || ! isset($payload['hash'], $payload['ip_hash'], $payload['expires_at'], $payload['attempts'])) {
                    throw new BusinessRuleException($prefix.'_CODE_EXPIRED', '验证码已过期或已使用');
                }

                if (! hash_equals((string) $payload['ip_hash'], $this->identity($ip))) {
                    throw new BusinessRuleException($prefix.'_CODE_INVALID', '验证码错误');
                }

                $expiresAt = (int) $payload['expires_at'];
                $remaining = $expiresAt - now()->timestamp;
                if ($remaining <= 0) {
                    $cache->forget($key);
                    throw new BusinessRuleException($prefix.'_CODE_EXPIRED', '验证码已过期');
                }

                if (! Hash::check($code, (string) $payload['hash'])) {
                    $payload['attempts'] = (int) $payload['attempts'] + 1;
                    if ($payload['attempts'] >= self::MAX_ATTEMPTS) {
                        $cache->forget($key);
                        throw new BusinessRuleException($prefix.'_CODE_EXPIRED', '验证码尝试次数已用尽');
                    }

                    $cache->put($key, $payload, Carbon::now()->addSeconds($remaining));
                    throw new BusinessRuleException($prefix.'_CODE_INVALID', '验证码错误');
                }

                $cache->forget($key);
            });
        } catch (LockTimeoutException) {
            throw new BusinessRuleException($prefix.'_CODE_BUSY', '验证码校验繁忙', 503);
        }
    }

    private function prefix(CodeMode $mode): string
    {
        return $mode === CodeMode::SMS ? 'SMS' : 'EMAIL';
    }

    private function identity(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function codeKey(string $prefix, string $purpose, string $identity): string
    {
        return "verification:{$prefix}:{$purpose}:{$identity}";
    }

    private function assertPurpose(string $purpose): void
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new BusinessRuleException('VERIFICATION_PURPOSE_INVALID', '验证码用途无效');
        }
    }

    private function releaseThrottle(string $key, string $ownerToken): void
    {
        Redis::eval(<<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
    return redis.call('del', KEYS[1])
end
return 0
LUA, 1, $key, $ownerToken);
    }
}
