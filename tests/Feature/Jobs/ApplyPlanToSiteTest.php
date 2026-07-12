<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ApplyPlanToSite;
use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use App\Models\Site;
use App\Services\MaintenancePlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-60: the fleet apply is queued, one job per site, so a large fleet can't
 * tie up the web worker or leave a partial apply. A single site's failure is
 * recorded visibly and never aborts the rest.
 */
class ApplyPlanToSiteTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): MaintenancePlan
    {
        $plan = MaintenancePlan::create([
            'name' => 'Queued Plan',
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

    public function test_apply_to_sites_dispatches_one_job_per_site(): void
    {
        Queue::fake();

        // Non-default plan + no maintenance_plan_id → the created hook queues no
        // ApplyPlanToSite, so the only dispatches come from applyToSites.
        $sites = Site::factory()->count(3)->create(['is_connected' => false]);
        $plan = $this->plan();

        $result = app(MaintenancePlanService::class)->applyToSites($plan, $sites, ['modules']);

        Queue::assertPushed(ApplyPlanToSite::class, 3);
        $this->assertSame(3, $result['queued']);
        $this->assertArrayHasKey('batch_id', $result);
    }

    public function test_job_applies_the_plan_for_its_site(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => false]);
        $plan = $this->plan();

        (new ApplyPlanToSite($site, $plan))->handle(app(MaintenancePlanService::class));

        $this->assertDatabaseHas('uptime_monitors', ['site_id' => $site->id]);
        $this->assertDatabaseHas('dns_monitors', ['site_id' => $site->id, 'is_active' => true]);
        $this->assertSame($plan->id, $site->fresh()->maintenance_plan_id);
    }

    public function test_failed_handler_records_a_visible_error_state(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => false]);
        $plan = $this->plan();

        $batchId = 'batch-under-test';
        Cache::put(MaintenancePlanService::progressKey($batchId), [
            'total' => 1, 'done' => 0, 'failed' => 0, 'plan' => $plan->name,
        ], now()->addHour());

        (new ApplyPlanToSite($site, $plan, null, $batchId))->failed(new \RuntimeException('boom'));

        // Per-site failure reason is visible/pollable.
        $this->assertSame('boom', Cache::get(MaintenancePlanService::failureKey($plan->id, $site->id)));

        // Batch progress reflects the failure and marks the batch complete.
        $progress = MaintenancePlanService::progress($batchId);
        $this->assertNotNull($progress);
        $this->assertSame(1, $progress['failed']);
        $this->assertSame(1, $progress['done']);
        $this->assertTrue($progress['complete']);
    }
}
