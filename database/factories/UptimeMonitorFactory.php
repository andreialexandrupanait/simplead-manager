<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\UptimeMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

class UptimeMonitorFactory extends Factory
{
    protected $model = UptimeMonitor::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'url' => fake()->url(),
            'method' => 'GET',
            'interval_minutes' => fake()->randomElement([1, 5, 10, 15, 30]),
            'timeout_seconds' => 30,
            'is_active' => true,
            'current_state' => fake()->randomElement(['up', 'down', 'degraded']),
            'last_checked_at' => now()->subMinutes(fake()->numberBetween(1, 60)),
            'next_check_at' => now()->addMinutes(5),
            'last_response_time' => fake()->numberBetween(100, 2000),
            'http_status_code' => 200,
            'consecutive_failures' => 0,
        ];
    }
}
