<?php

namespace App\Services;

use App\DTO\VisitorTokenPayload;
use App\Exceptions\InvalidVisitorToken;
use App\Exceptions\LinkResolutionException;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Throwable;

final class VisitorTokenService
{
    public function issue(string $code, string $visitorId, CarbonImmutable $expiresAt): string
    {
        return $this->encrypter()->encryptString(json_encode([
            'code' => $code,
            'visitor_id' => $visitorId,
            'exp' => $expiresAt->timestamp,
            'jti' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR));
    }

    public function verify(string $token, string $code, CarbonImmutable $at): VisitorTokenPayload
    {
        // Configuration errors must remain distinguishable from user token
        // failures, so this is deliberately outside the catch block.
        $encrypter = $this->encrypter();

        try {
            $decodedToken = base64_decode($token, true);
            if ($decodedToken === false || base64_encode($decodedToken) !== $token) {
                throw $this->invalid();
            }
            $decoded = json_decode($encrypter->decryptString($token), true, 512, JSON_THROW_ON_ERROR);
            $required = ['code', 'visitor_id', 'exp', 'jti'];
            if (
                ! is_array($decoded)
                || count($decoded) !== count($required)
                || array_diff($required, array_keys($decoded)) !== []
                || array_diff(array_keys($decoded), $required) !== []
            ) {
                throw $this->invalid();
            }
            if (
                ! is_string($decoded['code'])
                || $decoded['code'] !== $code
                || ! is_string($decoded['visitor_id'])
                || $decoded['visitor_id'] === ''
                || ! is_int($decoded['exp'])
                || ! is_string($decoded['jti'])
                || ! Str::isUuid($decoded['jti'])
                || $at->timestamp >= $decoded['exp']
            ) {
                throw $this->invalid();
            }

            return new VisitorTokenPayload(
                $decoded['visitor_id'],
                $decoded['code'],
                $decoded['exp'],
                $decoded['jti'],
            );
        } catch (InvalidVisitorToken $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->invalid();
        }
    }

    private function encrypter(): Encrypter
    {
        $encoded = config('app.visitor_token_key');
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new LinkResolutionException('VISITOR_TOKEN_KEY_INVALID', 'Visitor token configuration is invalid.');
        }

        return new Encrypter($key, 'aes-256-gcm');
    }

    private function invalid(): InvalidVisitorToken
    {
        return new InvalidVisitorToken('VISITOR_TOKEN_INVALID', 'Invalid visitor token');
    }
}
