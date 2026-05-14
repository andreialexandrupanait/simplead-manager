<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SecurityCategory;
use App\Models\SecuritySetting;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SecuritySetting>
 */
class SecuritySettingFactory extends Factory
{
    protected $model = SecuritySetting::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'category' => fake()->randomElement(SecurityCategory::cases()),
            'setting_key' => fake()->randomElement([
                'disable_xml_rpc',
                'disable_file_editor',
                'enforce_ssl',
                'hide_wp_version',
                'block_author_enum',
            ]),
            'setting_value' => null,
            'is_enabled' => false,
            'applied_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => true,
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => true,
            'applied_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
        ]);
    }

    public function failed(string $reason = 'Connection timeout'): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => true,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
