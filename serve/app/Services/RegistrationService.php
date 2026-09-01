<?php

namespace App\Services;

use App\Contracts\ReferralCodeGenerator;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class RegistrationService
{
    public function __construct(
        private readonly MembershipService $membership,
        UserAccountCreator|ReferralCodeGenerator|null $accounts = null,
    ) {
        $this->accounts = $accounts instanceof ReferralCodeGenerator
            ? new UserAccountCreator($accounts)
            : $accounts ?? app(UserAccountCreator::class);
    }

    private readonly UserAccountCreator $accounts;

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

            $user = $this->accounts->create([
                'username' => $username,
                'password' => Hash::make($plainPassword),
                'type' => UserType::MEMBER,
                'status' => true,
                'parent_id' => $parent?->id,
            ]);

            $this->membership->grantConfiguredTrial($user);

            return $user->refresh();
        });
    }
}
