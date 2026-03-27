<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Settings\DataRetentionSettings;
use App\Models\User;
use App\Services\RetentionPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataRetentionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function admin_can_view_data_retention(): void
    {
        Livewire::actingAs($this->admin)
            ->test(DataRetentionSettings::class)
            ->assertOk();
    }

    #[Test]
    public function component_loads_all_category_keys(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(DataRetentionSettings::class)
            ->assertOk();

        $days = $component->get('days');

        foreach (array_keys(RetentionPolicyService::CATEGORIES) as $key) {
            $this->assertArrayHasKey($key, $days, "Expected retention days key '{$key}' to be present.");
        }
    }

    // ─── save() ───────────────────────────────────────────────────────

    #[Test]
    public function admin_can_save_retention_settings(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(DataRetentionSettings::class);

        // Set valid days within the min/max bounds for each category
        $days = [];
        foreach (RetentionPolicyService::CATEGORIES as $key => $config) {
            $days[$key] = $config['default'];
        }

        $component
            ->set('days', $days)
            ->set('enabled', true)
            ->call('save')
            ->assertDispatched('notify');
    }

    #[Test]
    public function save_rejects_days_below_minimum(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(DataRetentionSettings::class);

        $days = $component->get('days');

        // Pick the first category and set a value below its minimum
        $firstKey = array_key_first(RetentionPolicyService::CATEGORIES);
        $min = RetentionPolicyService::CATEGORIES[$firstKey]['min'];
        $days[$firstKey] = $min - 1;

        $component
            ->set('days', $days)
            ->call('save')
            ->assertHasErrors(["days.{$firstKey}"]);
    }

    #[Test]
    public function save_rejects_days_above_maximum(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(DataRetentionSettings::class);

        $days = $component->get('days');

        $firstKey = array_key_first(RetentionPolicyService::CATEGORIES);
        $max = RetentionPolicyService::CATEGORIES[$firstKey]['max'];
        $days[$firstKey] = $max + 1;

        $component
            ->set('days', $days)
            ->call('save')
            ->assertHasErrors(["days.{$firstKey}"]);
    }

    // ─── resetToDefaults() ────────────────────────────────────────────

    #[Test]
    public function reset_to_defaults_restores_default_values(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(DataRetentionSettings::class);

        // Change all values to their maximum
        $days = [];
        foreach (RetentionPolicyService::CATEGORIES as $key => $config) {
            $days[$key] = $config['max'];
        }

        $component->set('days', $days)->call('resetToDefaults');

        $resetDays = $component->get('days');

        foreach (RetentionPolicyService::CATEGORIES as $key => $config) {
            $this->assertEquals(
                $config['default'],
                $resetDays[$key],
                "Expected '{$key}' to be reset to default {$config['default']}."
            );
        }
    }
}
