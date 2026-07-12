<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\CreateSiteWizard;
use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1-56: sites created through the wizard never got a DNS monitor (no plan
 * carries an explicit `dns` module), and the selected plan's modules were only
 * materialized via the Site::created hook. Verify a wizard-created site gets its
 * plan modules AND a DNS monitor by default.
 */
class CreateSiteWizardModulesTest extends TestCase
{
    use RefreshDatabase;

    private function planWithUptime(): MaintenancePlan
    {
        $plan = MaintenancePlan::create([
            'name' => 'Wizard Test Plan',
            'is_default' => true,
            'include_modules' => true,
        ]);

        // Intentionally NO 'dns' module — proving DNS is materialized by default.
        MaintenancePlanModule::create([
            'maintenance_plan_id' => $plan->id,
            'module_key' => 'uptime',
            'is_enabled' => true,
            'interval_minutes' => 5,
        ]);

        return $plan;
    }

    public function test_wizard_creates_dns_monitor_and_plan_modules(): void
    {
        Queue::fake(); // suppress FetchSiteFavicon on Site::created

        $plan = $this->planWithUptime();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(CreateSiteWizard::class)
            ->set('form.url', 'https://newsite.example.com')
            ->set('form.name', 'New Site')
            ->set('form.planId', $plan->id)
            ->call('createSite')
            ->assertHasNoErrors();

        $site = Site::where('url', 'https://newsite.example.com')->first();
        $this->assertNotNull($site, 'The wizard should have created the site.');

        // Plan module materialized.
        $this->assertDatabaseHas('uptime_monitors', ['site_id' => $site->id]);

        // DNS monitor materialized by default even though the plan has no dns module.
        $this->assertDatabaseHas('dns_monitors', [
            'site_id' => $site->id,
            'is_active' => true,
        ]);
    }
}
