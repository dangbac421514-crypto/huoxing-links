<?php

namespace App\Services;

use Closure;
use Gregwar\Captcha\CaptchaBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ImageCaptchaService
{
    private const TTL_SECONDS = 300;

    /**
     * @var Closure(?string): object
     */
    private readonly Closure $builderFactory;

    /**
     * @param  callable(?string): object|null  $builderFactory
     */
    public function __construct(?callable $builderFactory = null)
    {
        $this->builderFactory = $builderFactory === null
            ? static fn (?string $phrase = null): CaptchaBuilder => new CaptchaBuilder($phrase)
            : Closure::fromCallable($builderFactory);
    }

    /**
     * @return array{key: string, img: string}
     */
    public function issue(): array
    {
        $builder = ($this->builderFactory)();
        $builder->setImageType('png');
        $builder->build();

        $key = Str::random(32);
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        Cache::store('redis')->put('image-captcha:'.$key, [
            'hash' => Hash::make(strtolower((string) $builder->getPhrase())),
            'expires_at' => $expiresAt->timestamp,
        ], $expiresAt);

        return [
            'key' => $key,
            'img' => $builder->inline(),
        ];
    }

    public function verify(string $key, string $answer): bool
    {
        $payload = Cache::store('redis')->pull('image-captcha:'.$key);
        if (! is_array($payload) || ! isset($payload['hash'], $payload['expires_at'])) {
            return false;
        }

        if (now()->timestamp > (int) $payload['expires_at']) {
            return false;
        }

        return Hash::check(strtolower($answer), (string) $payload['hash']);
    }
}
