<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\PerformanceMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

class PerformanceMonitorFactory extends Factory
{
    protected $model = PerformanceMonitor::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'is_active' => true,
            'frequency' => 'daily',
            'test_time' => '04:00',
            'alert_on_score_drop' => true,
            'score_drop_threshold' => 10,
            'alert_on_poor_vitals' => false,
            'latest_mobile_score' => fake()->numberBetween(40, 100),
            'latest_desktop_score' => fake()->numberBetween(60, 100),
            'last_tested_at' => now()->subHours(fake()->numberBetween(1, 24)),
        ];
    }
}
