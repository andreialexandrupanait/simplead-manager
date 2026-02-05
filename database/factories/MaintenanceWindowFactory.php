<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\User;
use App\Models\MaintenanceWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaintenanceWindowFactory extends Factory
{
    protected $model = MaintenanceWindow::class;

    public function definition(): array
    {
        $start = now()->addHours(fake()->numberBetween(1, 48));
        return [
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addHours(fake()->numberBetween(1, 4)),
            'status' => 'scheduled',
            'pause_uptime' => true,
            'pause_ssl' => false,
            'pause_performance' => false,
            'pause_backups' => false,
            'pause_links' => false,
            'notify_on_start' => true,
            'notify_on_end' => true,
        ];
    }
}
