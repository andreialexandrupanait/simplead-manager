<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\MonitorStatus;
use App\Jobs\ApplyPlanToSite;
use App\Jobs\FetchSiteFavicon;
use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Services\MaintenancePlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-36: editing a maintenance plan must propagate to the sites already using
 * it. Otherwise their materialized module rows silently drift from the plan.
 */
class MaintenancePlanReapplyTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): MaintenancePlan
    {
        $plan = MaintenancePlan::create([
            'name' => 'Drift Plan',
            'is_default' => false,
            'include_modules' => true,
        ]);

        MaintenancePlanModule::create([
            'maintenance_plan_id' => $plan->id,
            'module_key' => 'uptime',
            'is_enabled' => true,
            'interval_minutes' => 5,
        ]);

        return $plan;
    }

    public function test_editing_a_plan_reapplies_to_each_assigned_site(): void
    {
        Queue::fake();

        $plan = $this->plan(); // non-default → no Site::created auto-apply
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        Site::factory()->create(); // unassigned — must NOT be re-applied

        // Assign via update() (not create) so the Site::created hook doesn't
        // pre-dispatch the same unique ApplyPlanToSite job and mask the re-apply.
        Site::whereIn('id', [$siteA->id, $siteB->id])->update(['maintenance_plan_id' => $plan->id]);

        app(MaintenancePlanService::class)->savePlan(
            [
                'name' => 'Drift Plan (edited)',
                'is_default' => false,
                'sort_order' => 0,
                'include_modules' => true,
                'include_security' => false,
                'include_tweaks' => false,
            ],
            ['uptime' => ['is_enabled' => true]],
            null,
            null,
            $plan->id,
        );

        Queue::assertPushed(ApplyPlanToSite::class, 2);
        Queue::assertPushed(ApplyPlanToSite::class, fn (ApplyPlanToSite $job) => $job->site->id === $siteA->id);
        Queue::assertPushed(ApplyPlanToSite::class, fn (ApplyPlanToSite $job) => $job->site->id === $siteB->id);
    }

    public function test_creating_a_new_plan_does_not_reapply(): void
    {
        Queue::fake();

        app(MaintenancePlanService::class)->savePlan(
            [
                'name' => 'Fresh Plan',
                'is_default' => false,
                'sort_order' => 0,
                'include_modules' => true,
                'include_security' => false,
                'include_tweaks' => false,
            ],
            ['uptime' => ['is_enabled' => true]],
            null,
            null,
            null, // creating — no assigned sites yet
        );

        Queue::assertNotPushed(ApplyPlanToSite::class);
    }

    public function test_reapply_reconciles_a_drifted_module_row(): void
    {
        // Sync queue runs ApplyPlanToSite inline; only suppress the favicon fetch.
        Queue::fake([FetchSiteFavicon::class]);

        $plan = $this->plan();
        $site = Site::factory()->create(['maintenance_plan_id' => $plan->id]);

        // Drift: the site's uptime monitor was manually paused after materialization.
        UptimeMonitor::where('site_id', $site->id)->update(['status' => 'paused']);
        $this->assertSame(MonitorStatus::Paused, UptimeMonitor::where('site_id', $site->id)->first()->status);

        // Editing the plan (which enables uptime) must re-apply and reconcile it.
        app(MaintenancePlanService::class)->savePlan(
            [
                'name' => 'Drift Plan',
                'is_default' => false,
                'sort_order' => 0,
                'include_modules' => true,
                'include_security' => false,
                'include_tweaks' => false,
            ],
            ['uptime' => ['is_enabled' => true]],
            null,
            null,
            $plan->id,
        );

        $this->assertSame(MonitorStatus::Active, UptimeMonitor::where('site_id', $site->id)->first()->status);
    }
}
