<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\CloudflareConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class CloudflareConnectionFactory extends Factory
{
    protected $model = CloudflareConnection::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'api_token' => fake()->sha256(),
            'account_id' => fake()->sha1(),
            'account_email' => fake()->safeEmail(),
            'is_valid' => true,
            'last_validated_at' => now()->subHours(1),
        ];
    }
}
