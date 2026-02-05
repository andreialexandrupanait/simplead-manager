<?php

namespace Database\Factories;

use App\Models\StatusPageIncident;
use App\Models\StatusPageIncidentUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

class StatusPageIncidentUpdateFactory extends Factory
{
    protected $model = StatusPageIncidentUpdate::class;

    public function definition(): array
    {
        return [
            'status_page_incident_id' => StatusPageIncident::factory(),
            'status' => fake()->randomElement(['investigating', 'identified', 'monitoring', 'resolved']),
            'message' => fake()->paragraph(),
        ];
    }
}
