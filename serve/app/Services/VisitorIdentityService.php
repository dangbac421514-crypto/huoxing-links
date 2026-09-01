<?php

namespace App\Services;

use App\DTO\VisitorIdentity;
use App\Exceptions\LinkResolutionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

final class VisitorIdentityService
{
    private const COOKIE_NAME = 'visitor_id';

    private const COOKIE_MINUTES = 365 * 24 * 60;

    public function resolve(Request $request): VisitorIdentity
    {
        $key = $this->hashKey();
        $cookie = $request->cookie(self::COOKIE_NAME);
        $visitorId = is_string($cookie) && Str::isUuid($cookie)
            ? $cookie
            : (string) Str::uuid();

        return new VisitorIdentity(
            $visitorId,
            hash_hmac('sha256', $visitorId, $key),
            ! (is_string($cookie) && Str::isUuid($cookie)),
        );
    }

    public function cookie(VisitorIdentity $identity): SymfonyCookie
    {
        return Cookie::make(
            self::COOKIE_NAME,
            $identity->visitorId,
            self::COOKIE_MINUTES,
            '/',
            null,
            true,
            true,
            false,
            SymfonyCookie::SAMESITE_LAX,
        );
    }

    private function hashKey(): string
    {
        $key = config('app.visitor_hash_key');
        if (! is_string($key) || $key === '' || strlen($key) < 32) {
            throw new LinkResolutionException(
                'VISITOR_HASH_KEY_INVALID',
                'Visitor hash configuration is invalid.',
            );
        }

        return $key;
    }
}
