<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['uptime', 'backup', 'update', 'security', 'performance']),
            'severity' => fake()->randomElement(['info', 'warning', 'error', 'critical']),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'metadata' => null,
        ];
    }
}
