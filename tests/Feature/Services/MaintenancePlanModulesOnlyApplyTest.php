<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use App\Models\Site;
use App\Services\MaintenancePlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3-24: a modules-only apply pushes nothing to the connector (modules are
 * materialized as DB rows). The `pushed` counter used to report true merely
 * because the site was connected — overstating what happened.
 */
class MaintenancePlanModulesOnlyApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_modules_only_apply_reports_pushed_false_on_a_connected_site(): void
    {
        $plan = MaintenancePlan::create([
            'name' => 'Modules Only',
            'is_default' => false,
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

        $site = Site::factory()->create(['is_connected' => true]);

        $result = app(MaintenancePlanService::class)->applyPlanToSite($site, $plan, ['modules']);

        $this->assertTrue($result['connected']);
        $this->assertFalse($result['pushed'], 'modules-only apply pushes nothing, so pushed must be false');
    }
}
