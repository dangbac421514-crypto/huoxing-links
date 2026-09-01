<?php

namespace App\Services;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class RegistrationService
{
    public function __construct(private readonly MembershipService $membership) {}

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

            try {
                $user = User::query()->create([
                    'username' => $username,
                    'password' => Hash::make($plainPassword),
                    'type' => UserType::MEMBER,
                    'status' => true,
                    'parent_id' => $parent?->id,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'username' => '用户名已存在！',
                ]);
            }

            $this->membership->grantConfiguredTrial($user);

            return $user->refresh();
        });
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            || str_starts_with((string) ($exception->errorInfo[0] ?? ''), '23');
    }
}
