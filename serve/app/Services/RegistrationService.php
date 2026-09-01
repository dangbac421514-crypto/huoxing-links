<?php

namespace App\Services;

use App\Contracts\ReferralCodeGenerator;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class RegistrationService
{
    private const MAX_REFERRAL_CODE_ATTEMPTS = 3;

    public function __construct(
        private readonly MembershipService $membership,
        private readonly ReferralCodeGenerator $referralCodes,
    ) {}

    public function register(string $username, string $plainPassword, ?string $referralCode): User
    {
        return DB::transaction(function () use ($username, $plainPassword, $referralCode): User {
            $parent = null;
            if ($referralCode !== null && $referralCode !== '') {
                $parent = User::query()
                    ->lockForUpdate()
                    ->where('referral_code', $referralCode)
                    ->first();

                if (! $parent || $parent->username === $username || ! $parent->status) {
                    throw ValidationException::withMessages([
                        'referral_code' => '推荐码无效',
                    ]);
                }
            }

            for ($attempt = 0; $attempt < self::MAX_REFERRAL_CODE_ATTEMPTS; $attempt++) {
                try {
                    $user = User::query()->create([
                        'username' => $username,
                        'password' => Hash::make($plainPassword),
                        'type' => UserType::MEMBER,
                        'status' => true,
                        'parent_id' => $parent?->id,
                        'referral_code' => $this->referralCodes->generate(),
                    ]);
                    break;
                } catch (QueryException $exception) {
                    if ($this->isDuplicateKey($exception, 'users_username_unique')) {
                        throw ValidationException::withMessages([
                            'username' => '用户名已存在！',
                        ]);
                    }
                    if (! $this->isDuplicateKey($exception, 'users_referral_code_unique')
                        || $attempt === self::MAX_REFERRAL_CODE_ATTEMPTS - 1) {
                        throw $exception;
                    }
                }
            }

            $this->membership->grantConfiguredTrial($user);

            return $user->refresh();
        });
    }

    private function isDuplicateKey(QueryException $exception, string $constraint): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains((string) ($exception->errorInfo[2] ?? ''), $constraint);
    }
}
