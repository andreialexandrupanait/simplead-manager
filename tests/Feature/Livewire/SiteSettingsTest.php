<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\SiteSettings;
use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_site_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteSettings::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function settings_page_loads_with_current_plan(): void
    {
        $plan = MaintenancePlan::create([
            'name' => 'Starter',
            'is_default' => false,
            'sort_order' => 1,
            'include_modules' => true,
            'include_security' => false,
            'include_tweaks' => false,
        ]);
        $this->site->update(['maintenance_plan_id' => $plan->id]);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteSettings::class, ['site' => $this->site]);

        $this->assertEquals($plan->id, $component->get('selectedPlanId'));
    }

    // ─── applyPlan() ──────────────────────────────────────────────────

    #[Test]
    public function user_can_apply_a_maintenance_plan(): void
    {
        $plan = MaintenancePlan::create([
            'name' => 'Basic',
            'is_default' => false,
            'sort_order' => 1,
            'include_modules' => true,
            'include_security' => false,
            'include_tweaks' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteSettings::class, ['site' => $this->site])
            ->set('selectedPlanId', $plan->id)
            ->call('applyPlan')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('sites', [
            'id' => $this->site->id,
            'maintenance_plan_id' => $plan->id,
        ]);
    }

    #[Test]
    public function applying_plan_with_modules_creates_module_records(): void
    {
        $plan = MaintenancePlan::create([
            'name' => 'Pro',
            'is_default' => false,
            'sort_order' => 1,
            'include_modules' => true,
            'include_security' => false,
            'include_tweaks' => false,
        ]);

        MaintenancePlanModule::create([
            'maintenance_plan_id' => $plan->id,
            'module_key' => 'uptime',
            'is_enabled' => true,
            'interval_minutes' => 5,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteSettings::class, ['site' => $this->site])
            ->set('selectedPlanId', $plan->id)
            ->call('applyPlan');

        $this->assertDatabaseHas('uptime_monitors', [
            'site_id' => $this->site->id,
        ]);
    }

    #[Test]
    public function apply_plan_without_selection_dispatches_error(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteSettings::class, ['site' => $this->site])
            ->set('selectedPlanId', null)
            ->call('applyPlan')
            ->assertDispatched('notify');
    }

    // ─── toggleModule() ───────────────────────────────────────────────

    #[Test]
    public function user_can_toggle_backup_module(): void
    {
        // Pre-create a backup config so toggling works
        \App\Models\BackupConfig::create([
            'site_id' => $this->site->id,
            'is_enabled' => false,
            'frequency' => 'daily',
            'time' => '03:00',
            'timezone' => 'UTC',
            'type' => 'full',
            'retention_type' => 'count',
            'retention_value' => 7,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteSettings::class, ['site' => $this->site])
            ->call('toggleModule', 'backup');

        $this->assertDatabaseHas('backup_configs', [
            'site_id' => $this->site->id,
            'is_enabled' => true,
        ]);
    }

    // ─── Module labels computed ────────────────────────────────────────

    #[Test]
    public function module_labels_include_all_expected_keys(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteSettings::class, ['site' => $this->site]);

        $labels = $component->instance()->moduleLabels;

        $this->assertArrayHasKey('uptime', $labels);
        $this->assertArrayHasKey('backup', $labels);
        $this->assertArrayHasKey('database_cleanup', $labels);
    }

    // ─── Plans list ───────────────────────────────────────────────────

    #[Test]
    public function plans_computed_property_returns_ordered_plans(): void
    {
        MaintenancePlan::create(['name' => 'Plan B', 'is_default' => false, 'sort_order' => 2, 'include_modules' => false, 'include_security' => false, 'include_tweaks' => false]);
        MaintenancePlan::create(['name' => 'Plan A', 'is_default' => false, 'sort_order' => 1, 'include_modules' => false, 'include_security' => false, 'include_tweaks' => false]);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteSettings::class, ['site' => $this->site]);

        $plans = $component->instance()->plans;
        $this->assertGreaterThanOrEqual(2, $plans->count());
        $this->assertEquals('Plan A', $plans->first()->name);
    }
}
