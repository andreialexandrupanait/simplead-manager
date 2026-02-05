<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\StatusPage;
use Illuminate\Database\Eloquent\Factories\Factory;

class StatusPageFactory extends Factory
{
    protected $model = StatusPage::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'slug' => fake()->unique()->slug(2),
            'title' => fake()->company() . ' Status',
            'description' => fake()->optional()->sentence(),
            'primary_color' => '#7C3AED',
            'is_public' => true,
            'show_uptime_percentage' => true,
            'show_response_time' => false,
            'show_incident_history' => true,
            'incident_history_days' => 90,
            'auto_incidents' => true,
        ];
    }
}
