<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->numerify('138########'),
            'password' => static::$password ??= Hash::make('password'),
            'status' => true,
            'type' => UserType::MEMBER,
            'referral_code' => fake()->unique()->regexify('[A-Z0-9]{8}'),
        ];
    }
}
