<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\FetchSiteFavicon;
use App\Models\MaintenancePlan;
use App\Models\MaintenancePlanModule;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\MaintenancePlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-58: "apply this plan to this site" must mean the SAME thing regardless of
 * entry point. This proves the Site::created hook and the bulk applyToSites
 * path funnel through one canonical operation and yield identical module,
 * security and tweak rows.
 */
class MaintenancePlanApplyParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Let ApplyPlanToSite run on the sync test queue; only suppress the
        // outbound favicon fetch dispatched by Site::created.
        Queue::fake([FetchSiteFavicon::class]);
    }

    private function fullPlan(): MaintenancePlan
    {
        // NOT default — so a plain site creation does not pick it up implicitly.
        $plan = MaintenancePlan::create([
            'name' => 'Parity Plan',
            'is_default' => false,
            'include_modules' => true,
            'include_security' => true,
            'include_tweaks' => true,
            'security_settings' => [
                'hardening' => ['disable_theme_editor' => ['value' => ['enabled' => true], 'enabled' => true]],
            ],
            'tweak_settings' => [
                'performance' => ['disable_emojis' => ['value' => true, 'enabled' => true]],
            ],
        ]);

        MaintenancePlanModule::create([
            'maintenance_plan_id' => $plan->id,
            'module_key' => 'uptime',
            'is_enabled' => true,
            'interval_minutes' => 5,
        ]);

        return $plan;
    }

    /** @return array<int, string> category/key of every security_settings row for a site. */
    private function settingKeys(Site $site): array
    {
        return SecuritySetting::where('site_id', $site->id)
            ->get()
            ->map(function (SecuritySetting $s) {
                $category = is_string($s->category) ? $s->category : $s->category->value;

                return $category.'/'.$s->setting_key;
            })
            ->sort()
            ->values()
            ->all();
    }

    public function test_created_hook_and_bulk_apply_produce_identical_rows(): void
    {
        $plan = $this->fullPlan();

        // Path A: Site::created hook (dispatches ApplyPlanToSite, runs sync).
        $viaHook = Site::factory()->create([
            'is_connected' => false,
            'maintenance_plan_id' => $plan->id,
        ]);

        // Path B: a plain site (no plan) then the bulk applyToSites path, which
        // dispatches one ApplyPlanToSite per site (runs sync here).
        $viaBulk = Site::factory()->create(['is_connected' => false]);
        app(MaintenancePlanService::class)->applyToSites(
            $plan,
            collect([$viaBulk]),
            ['modules', 'security', 'tweaks'],
        );

        // Modules materialized on BOTH paths.
        foreach ([$viaHook, $viaBulk] as $site) {
            $this->assertDatabaseHas('uptime_monitors', ['site_id' => $site->id]);
            $this->assertDatabaseHas('dns_monitors', ['site_id' => $site->id, 'is_active' => true]);
            $this->assertDatabaseHas('security_settings', [
                'site_id' => $site->id,
                'category' => 'hardening',
                'setting_key' => 'disable_theme_editor',
            ]);
            $this->assertDatabaseHas('security_settings', [
                'site_id' => $site->id,
                'category' => 'performance',
                'setting_key' => 'disable_emojis',
            ]);
            $this->assertSame($plan->id, $site->fresh()->maintenance_plan_id);
        }

        // Identical resulting security/tweak row set across both entry points.
        $this->assertSame($this->settingKeys($viaHook), $this->settingKeys($viaBulk));
    }
}
