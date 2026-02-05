<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SecurityScan;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecurityScanFactory extends Factory
{
    protected $model = SecurityScan::class;

    public function definition(): array
    {
        $critical = fake()->numberBetween(0, 2);
        $high = fake()->numberBetween(0, 5);
        $medium = fake()->numberBetween(0, 8);
        $low = fake()->numberBetween(0, 10);

        return [
            'site_id' => Site::factory(),
            'score' => fake()->numberBetween(40, 100),
            'scores_breakdown' => [
                'headers' => fake()->numberBetween(0, 15),
                'ssl' => fake()->numberBetween(0, 15),
                'core_integrity' => fake()->numberBetween(0, 15),
                'recommendations' => fake()->numberBetween(0, 20),
                'vulnerabilities' => fake()->numberBetween(0, 20),
                'firewall' => fake()->numberBetween(0, 10),
                'updates' => fake()->numberBetween(0, 5),
            ],
            'critical_count' => $critical,
            'high_count' => $high,
            'medium_count' => $medium,
            'low_count' => $low,
            'scan_duration' => fake()->numberBetween(5, 60),
            'scanned_at' => now()->subHours(fake()->numberBetween(1, 48)),
        ];
    }
}
