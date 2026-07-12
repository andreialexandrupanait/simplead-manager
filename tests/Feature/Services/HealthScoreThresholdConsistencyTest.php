<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\HealthLevel;
use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-69: one canonical health-score banding (HealthLevel 75/50) for the whole
 * app. Previously the sites list + dashboard used a divergent 90/70, so the
 * same score was labelled differently in different views. A score of 80 is the
 * discriminating case: Warning under the old 90/70, Healthy under canonical.
 */
class HealthScoreThresholdConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();
    }

    public function test_canonical_helper_buckets_representative_scores(): void
    {
        $this->assertSame(HealthLevel::Healthy, HealthLevel::fromScore(75));
        $this->assertSame(HealthLevel::Healthy, HealthLevel::fromScore(80));
        $this->assertSame(HealthLevel::Warning, HealthLevel::fromScore(74));
        $this->assertSame(HealthLevel::Warning, HealthLevel::fromScore(50));
        $this->assertSame(HealthLevel::Critical, HealthLevel::fromScore(49));
        $this->assertSame(HealthLevel::Critical, HealthLevel::fromScore(0));
    }

    public function test_dashboard_and_sites_list_agree_on_a_score_of_80(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $site = Site::factory()->create(['health_score' => 80, 'is_up' => true]);

        // Canonical verdict: 80 is Healthy under 75/50 (was Warning under 90/70).
        $this->assertSame(HealthLevel::Healthy, HealthLevel::fromScore($site->health_score, $site->is_up));
        $this->assertTrue(Site::query()->healthy()->whereKey($site->id)->exists());
        $this->assertFalse(Site::query()->warning()->whereKey($site->id)->exists());

        // Call site #1 — DashboardService::getSitesOverview (was 90/70).
        $healthyIds = app(DashboardService::class)->getSitesOverview(filter: 'healthy')->pluck('id')->all();
        $warningIds = app(DashboardService::class)->getSitesOverview(filter: 'warning')->pluck('id')->all();
        $this->assertContains($site->id, $healthyIds);
        $this->assertNotContains($site->id, $warningIds);

        // Call site #2 — the exact filter query SitesList applies (was 90/70).
        // (Its full render can't run in tests: site-card references the
        // sites.show route, unloaded in the test env — same caveat as
        // SiteTagsTest.) It now agrees with the dashboard and the enum.
        $listHealthy = Site::query()
            ->where('health_score', '>=', HealthLevel::HEALTHY_THRESHOLD)->where('is_up', true)
            ->pluck('id')->all();
        $listWarning = Site::query()
            ->where('health_score', '>=', HealthLevel::WARNING_THRESHOLD)
            ->where('health_score', '<', HealthLevel::HEALTHY_THRESHOLD)->where('is_up', true)
            ->pluck('id')->all();

        $this->assertContains($site->id, $listHealthy);
        $this->assertNotContains($site->id, $listWarning);
    }
}
