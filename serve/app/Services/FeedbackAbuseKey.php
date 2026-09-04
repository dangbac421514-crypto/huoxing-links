<?php

namespace App\Services;

use Illuminate\Http\Request;

final class FeedbackAbuseKey
{
    private const MIN_KEY_LENGTH = 32;

    public function forRequest(Request $request): string
    {
        $code = (string) $request->route('code');
        $ip = (string) $request->ip();

        return hash_hmac('sha256', 'feedback:'.$code.':'.$ip, $this->secret());
    }

    private function secret(): string
    {
        $key = config('app.feedback_hash_key');
        if (! is_string($key) || strlen($key) < self::MIN_KEY_LENGTH) {
            throw new \InvalidArgumentException('Feedback hash configuration is invalid.');
        }

        return $key;
    }
}
