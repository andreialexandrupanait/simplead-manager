<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SecurityCategory;
use App\Enums\SecurityCommandPriority;
use App\Enums\SecurityCommandStatus;
use App\Models\SecurityCommand;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SecurityCommand>
 */
class SecurityCommandFactory extends Factory
{
    protected $model = SecurityCommand::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'category' => fake()->randomElement(SecurityCategory::cases()),
            'action' => fake()->randomElement([
                'disable_xml_rpc',
                'disable_file_editor',
                'enforce_ssl',
                'hide_wp_version',
                'block_author_enum',
                'disable_rest_api_public',
            ]),
            'payload' => null,
            'priority' => SecurityCommandPriority::Normal,
            'status' => SecurityCommandStatus::Pending,
            'picked_up_at' => null,
            'completed_at' => null,
            'result' => null,
            'attempts' => 0,
            'max_attempts' => 3,
        ];
    }

    public function pickedUp(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SecurityCommandStatus::PickedUp,
            'picked_up_at' => now(),
            'attempts' => 1,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SecurityCommandStatus::Completed,
            'picked_up_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'result' => ['success' => true],
            'attempts' => 1,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SecurityCommandStatus::Failed,
            'picked_up_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'result' => ['success' => false, 'error' => 'Connection timeout'],
            'attempts' => 3,
        ]);
    }

    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SecurityCommandStatus::PickedUp,
            'picked_up_at' => now()->subHours(2),
            'attempts' => 1,
        ]);
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => SecurityCommandPriority::High,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => SecurityCommandPriority::Critical,
        ]);
    }
}
