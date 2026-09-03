<?php

namespace App\Services;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use LogicException;

final class AdminProvisioner
{
    public function __construct(private readonly UserAccountCreator $accounts) {}

    public function provision(string $username, string $plainPassword): User
    {
        $username = trim($username);

        if ($username === '' || mb_strlen($username) > 255) {
            throw new InvalidArgumentException('管理员用户名不能为空且不能超过 255 个字符');
        }

        if (mb_strlen($plainPassword) < 12) {
            throw new InvalidArgumentException('管理员密码至少 12 位');
        }

        return DB::transaction(function () use ($username, $plainPassword): User {
            $user = User::query()->lockForUpdate()->where('username', $username)->first();

            if ($user && $user->type !== UserType::Admin) {
                throw new LogicException('该账号已存在且不是管理员');
            }

            if (! $user) {
                $user = $this->accounts->create([
                    'username' => $username,
                    'password' => Hash::make($plainPassword),
                    'type' => UserType::Admin,
                    'status' => true,
                    'must_change_password' => true,
                ]);
            } else {
                $user->forceFill([
                    'username' => $username,
                    'password' => Hash::make($plainPassword),
                    'type' => UserType::Admin,
                    'status' => true,
                    'must_change_password' => true,
                ])->save();
            }

            return $user->refresh();
        });
    }
}
