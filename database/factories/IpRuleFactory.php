<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\User;
use App\Models\IpRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class IpRuleFactory extends Factory
{
    protected $model = IpRule::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'ip_address' => fake()->ipv4(),
            'type' => fake()->randomElement(['block', 'allow']),
            'reason' => fake()->optional()->sentence(),
            'expires_at' => fake()->optional(0.3)->dateTimeBetween('now', '+30 days'),
            'created_by' => User::factory(),
            'hits_count' => fake()->numberBetween(0, 100),
            'last_hit_at' => fake()->optional()->dateTimeBetween('-7 days', 'now'),
            'is_synced' => fake()->boolean(70),
        ];
    }
}
