<?php

namespace Database\Factories;

use App\Models\GoogleConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GoogleConnection>
 */
class GoogleConnectionFactory extends Factory
{
    protected $model = GoogleConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_id' => fake()->numerify('################'),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'avatar_url' => fake()->optional()->imageUrl(96, 96, 'people'),
            'access_token' => Str::random(64),
            'refresh_token' => Str::random(64),
            'token_expires_at' => now()->addHour(),
            'scopes' => [
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/webmasters.readonly',
            ],
            'is_active' => true,
            'last_used_at' => fake()->optional()->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Indicate the connection is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'token_expires_at' => now()->addHour(),
        ]);
    }

    /**
     * Indicate the connection is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate the token has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'token_expires_at' => now()->subHour(),
        ]);
    }

    /**
     * Indicate the connection has analytics scope only.
     */
    public function analyticsOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'scopes' => ['https://www.googleapis.com/auth/analytics.readonly'],
        ]);
    }

    /**
     * Indicate the connection has search console scope only.
     */
    public function searchConsoleOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'scopes' => ['https://www.googleapis.com/auth/webmasters.readonly'],
        ]);
    }
}
