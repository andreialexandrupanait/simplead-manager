<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Manager,
            'is_admin' => false,
            'timezone' => fake()->timezone(),
            'date_format' => 'Y-m-d',
            'language' => 'en',
            'two_factor_enabled' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate the user is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
            'is_admin' => true,
        ]);
    }

    /**
     * Indicate the user is a manager.
     */
    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Manager,
            'is_admin' => false,
        ]);
    }

    /**
     * Indicate the user is a viewer.
     */
    public function viewer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Viewer,
            'is_admin' => false,
        ]);
    }

    /**
     * Indicate the user has two-factor authentication enabled.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_enabled' => true,
            'two_factor_secret' => Str::random(32),
            'two_factor_recovery_codes' => [Str::random(10), Str::random(10), Str::random(10)],
        ]);
    }
}
