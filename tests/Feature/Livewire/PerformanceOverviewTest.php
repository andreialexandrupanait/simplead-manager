<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Performance\PerformanceOverview;
use App\Models\PerformanceMonitor;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PerformanceOverviewTest extends TestCase
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
    public function admin_can_view_performance_overview(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        PerformanceMonitor::create([
            'site_id' => $site->id,
            'is_active' => true,
            'frequency' => 'daily',
            'test_time' => '04:00',
            'alert_on_score_drop' => true,
            'score_drop_threshold' => 10,
            'alert_on_poor_vitals' => false,
            'latest_mobile_score' => 85,
            'latest_desktop_score' => 90,
        ]);

        Livewire::actingAs($this->admin)
            ->test(PerformanceOverview::class)
            ->assertOk();
    }

    // ─── Search ───────────────────────────────────────────────────────

    #[Test]
    public function user_can_search_by_site_url(): void
    {
        // CQ-1 fix: search uses `url` column, not `domain`
        $targetSite = Site::factory()->for($this->admin)->create([
            'url' => 'https://fast-site.example.com',
            'name' => 'Fast Site',
        ]);
        $otherSite = Site::factory()->for($this->admin)->create([
            'url' => 'https://slow-site.example.com',
            'name' => 'Slow Site',
        ]);

        PerformanceMonitor::create([
            'site_id' => $targetSite->id,
            'is_active' => true,
            'frequency' => 'daily',
            'test_time' => '04:00',
            'alert_on_score_drop' => true,
            'score_drop_threshold' => 10,
            'alert_on_poor_vitals' => false,
        ]);

        PerformanceMonitor::create([
            'site_id' => $otherSite->id,
            'is_active' => true,
            'frequency' => 'daily',
            'test_time' => '04:00',
            'alert_on_score_drop' => true,
            'score_drop_threshold' => 10,
            'alert_on_poor_vitals' => false,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(PerformanceOverview::class)
            ->set('search', 'fast-site.example.com');

        $monitors = $component->viewData('monitors');

        $this->assertCount(1, $monitors);
        $this->assertEquals($targetSite->id, $monitors->first()->site_id);
    }

    #[Test]
    public function user_can_search_by_site_name(): void
    {
        $targetSite = Site::factory()->for($this->admin)->create(['name' => 'My Flagship Store']);
        $otherSite = Site::factory()->for($this->admin)->create(['name' => 'Blog Site']);

        PerformanceMonitor::create([
            'site_id' => $targetSite->id,
            'is_active' => true,
            'frequency' => 'daily',
            'test_time' => '04:00',
            'alert_on_score_drop' => true,
            'score_drop_threshold' => 10,
            'alert_on_poor_vitals' => false,
        ]);

        PerformanceMonitor::create([
            'site_id' => $otherSite->id,
            'is_active' => true,
            'frequency' => 'daily',
            'test_time' => '04:00',
            'alert_on_score_drop' => true,
            'score_drop_threshold' => 10,
            'alert_on_poor_vitals' => false,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(PerformanceOverview::class)
            ->set('search', 'Flagship');

        $monitors = $component->viewData('monitors');

        $this->assertCount(1, $monitors);
        $this->assertEquals($targetSite->id, $monitors->first()->site_id);
    }

    // ─── Inactive monitors are excluded ───────────────────────────────

    #[Test]
    public function inactive_monitors_are_excluded_from_overview(): void
    {
        $activeSite = Site::factory()->for($this->admin)->create();
        $inactiveSite = Site::factory()->for($this->admin)->create();

        PerformanceMonitor::create([
            'site_id' => $activeSite->id,
            'is_active' => true,
            'frequency' => 'daily',
            'test_time' => '04:00',
            'alert_on_score_drop' => true,
            'score_drop_threshold' => 10,
            'alert_on_poor_vitals' => false,
        ]);

        PerformanceMonitor::create([
            'site_id' => $inactiveSite->id,
            'is_active' => false,
            'frequency' => 'daily',
            'test_time' => '04:00',
            'alert_on_score_drop' => true,
            'score_drop_threshold' => 10,
            'alert_on_poor_vitals' => false,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(PerformanceOverview::class);

        $monitors = $component->viewData('monitors');

        $this->assertCount(1, $monitors);
        $this->assertEquals($activeSite->id, $monitors->first()->site_id);
    }
}
