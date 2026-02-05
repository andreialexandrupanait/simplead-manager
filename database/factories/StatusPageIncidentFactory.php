<?php

namespace Database\Factories;

use App\Models\StatusPage;
use App\Models\Site;
use App\Models\StatusPageIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

class StatusPageIncidentFactory extends Factory
{
    protected $model = StatusPageIncident::class;

    public function definition(): array
    {
        return [
            'status_page_id' => StatusPage::factory(),
            'site_id' => Site::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => fake()->randomElement(['investigating', 'identified', 'monitoring', 'resolved']),
            'severity' => fake()->randomElement(['minor', 'major', 'critical']),
            'is_scheduled' => false,
            'started_at' => now()->subHours(fake()->numberBetween(1, 24)),
            'auto_created' => false,
        ];
    }
}
