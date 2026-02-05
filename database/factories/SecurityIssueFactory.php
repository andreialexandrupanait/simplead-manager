<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SecurityScan;
use App\Models\SecurityIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityIssueFactory extends Factory
{
    protected $model = SecurityIssue::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'security_scan_id' => SecurityScan::factory(),
            'category' => fake()->randomElement(['headers', 'ssl', 'configuration', 'file_permissions']),
            'type' => fake()->unique()->slug(3),
            'severity' => fake()->randomElement(['critical', 'high', 'medium', 'low']),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'recommendation' => fake()->sentence(),
            'is_fixed' => false,
            'is_ignored' => false,
            'first_detected_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ];
    }
}
