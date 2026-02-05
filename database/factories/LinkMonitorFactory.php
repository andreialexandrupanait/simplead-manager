<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\LinkMonitor;
use Illuminate\Database\Eloquent\Factories\Factory;

class LinkMonitorFactory extends Factory
{
    protected $model = LinkMonitor::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'is_active' => true,
            'frequency' => 'weekly',
            'scan_time' => '02:00',
            'day_of_week' => 0,
            'max_pages' => 200,
            'max_depth' => 5,
            'check_external' => true,
            'check_images' => true,
            'timeout_seconds' => 30,
            'alert_on_broken' => true,
            'alert_threshold' => 1,
            'total_links' => fake()->numberBetween(50, 500),
            'broken_links' => fake()->numberBetween(0, 10),
            'redirects' => fake()->numberBetween(0, 20),
            'pages_scanned' => fake()->numberBetween(10, 200),
            'last_scan_at' => now()->subDays(fake()->numberBetween(1, 7)),
        ];
    }
}
