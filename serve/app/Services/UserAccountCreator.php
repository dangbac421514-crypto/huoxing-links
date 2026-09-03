<?php

namespace App\Services;

use App\Contracts\ReferralCodeGenerator;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class UserAccountCreator
{
    private const MAX_REFERRAL_CODE_ATTEMPTS = 3;

    public function __construct(private readonly ReferralCodeGenerator $referralCodes) {}

    /**
     * Create a user and retry only generated referral-code collisions.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): User
    {
        $hasSuppliedReferralCode = isset($attributes['referral_code'])
            && $attributes['referral_code'] !== '';

        for ($attempt = 0; $attempt < self::MAX_REFERRAL_CODE_ATTEMPTS; $attempt++) {
            $payload = $attributes;
            if (! $hasSuppliedReferralCode) {
                $payload['referral_code'] = $this->referralCodes->generate();
            }

            try {
                return User::query()->create($payload);
            } catch (QueryException $exception) {
                if ($this->isDuplicateKey($exception, 'users_username_unique')) {
                    throw ValidationException::withMessages([
                        'username' => '用户名已存在！',
                    ]);
                }

                if ($hasSuppliedReferralCode
                    || ! $this->isDuplicateKey($exception, 'users_referral_code_unique')
                    || $attempt === self::MAX_REFERRAL_CODE_ATTEMPTS - 1) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('用户创建失败');
    }

    private function isDuplicateKey(QueryException $exception, string $constraint): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains((string) ($exception->errorInfo[2] ?? ''), $constraint);
    }
}
