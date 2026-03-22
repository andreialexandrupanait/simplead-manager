<?php

namespace Database\Factories;

use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BackupConfig>
 */
class BackupConfigFactory extends Factory
{
    protected $model = BackupConfig::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'is_enabled' => true,
            'frequency' => fake()->randomElement(['daily', 'weekly', 'monthly']),
            'time' => fake()->time('H:i'),
            'day_of_week' => fake()->numberBetween(0, 6),
            'day_of_month' => fake()->numberBetween(1, 28),
            'timezone' => fake()->timezone(),
            'type' => fake()->randomElement(['full', 'files', 'database']),
            'exclude_paths' => [],
            'exclude_tables' => [],
            'storage_destination_id' => StorageDestination::factory(),
            'retention_type' => fake()->randomElement(['count', 'days']),
            'retention_value' => fake()->randomElement([7, 14, 30, 90]),
            'backup_before_updates' => fake()->boolean(70),
            'last_backup_at' => fake()->optional()->dateTimeBetween('-7 days', 'now'),
            'next_backup_at' => fake()->optional()->dateTimeBetween('now', '+7 days'),
            'last_backup_status' => fake()->randomElement(['completed', 'failed', null]),
        ];
    }

    /**
     * Indicate the backup config is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => false,
        ]);
    }

    /**
     * Indicate daily backups.
     */
    public function daily(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'daily',
            'time' => '02:00',
        ]);
    }

    /**
     * Indicate weekly backups.
     */
    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'weekly',
            'day_of_week' => 0, // Sunday
            'time' => '03:00',
        ]);
    }

    /**
     * Indicate monthly backups.
     */
    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'time' => '04:00',
        ]);
    }
}
