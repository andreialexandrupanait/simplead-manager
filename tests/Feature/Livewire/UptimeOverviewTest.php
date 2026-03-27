<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\CheckUptime;
use App\Livewire\Uptime\UptimeOverview;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UptimeOverviewTest extends TestCase
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
    public function admin_can_view_uptime_overview(): void
    {
        $site = Site::factory()->for($this->admin)->create();
        UptimeMonitor::factory()->for($site)->up()->create();

        Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class)
            ->assertOk();
    }

    // ─── Computed counts ──────────────────────────────────────────────

    #[Test]
    public function displays_monitor_counts(): void
    {
        $siteA = Site::factory()->for($this->admin)->create();
        $siteB = Site::factory()->for($this->admin)->create();
        $siteC = Site::factory()->for($this->admin)->create();
        $siteD = Site::factory()->for($this->admin)->create();

        UptimeMonitor::factory()->for($siteA)->up()->create();
        UptimeMonitor::factory()->for($siteB)->down()->create();
        UptimeMonitor::factory()->for($siteC)->degraded()->create();
        UptimeMonitor::factory()->for($siteD)->paused()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class);

        $counts = $component->instance()->counts;

        $this->assertEquals(4, $counts['total']);
        $this->assertEquals(1, $counts['up']);
        $this->assertEquals(1, $counts['down']);
        $this->assertEquals(1, $counts['degraded']);
        $this->assertEquals(1, $counts['paused']);
    }

    // ─── Search ───────────────────────────────────────────────────────

    #[Test]
    public function user_can_search_monitors_by_site_name(): void
    {
        $targetSite = Site::factory()->for($this->admin)->create(['name' => 'Acme Corp Website']);
        $otherSite = Site::factory()->for($this->admin)->create(['name' => 'Other Site']);

        UptimeMonitor::factory()->for($targetSite)->up()->create();
        UptimeMonitor::factory()->for($otherSite)->up()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class)
            ->set('search', 'Acme Corp');

        $monitors = $component->viewData('monitors');

        $this->assertCount(1, $monitors);
        $this->assertEquals($targetSite->id, $monitors->first()->site_id);
    }

    // ─── Filter by state ──────────────────────────────────────────────

    #[Test]
    public function user_can_filter_monitors_by_up_status(): void
    {
        $siteUp = Site::factory()->for($this->admin)->create();
        $siteDown = Site::factory()->for($this->admin)->create();

        UptimeMonitor::factory()->for($siteUp)->up()->create();
        UptimeMonitor::factory()->for($siteDown)->down()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class)
            ->set('filter', 'up');

        $monitors = $component->viewData('monitors');

        $this->assertCount(1, $monitors);
        $this->assertEquals($siteUp->id, $monitors->first()->site_id);
    }

    #[Test]
    public function user_can_filter_monitors_by_down_status(): void
    {
        $siteUp = Site::factory()->for($this->admin)->create();
        $siteDown = Site::factory()->for($this->admin)->create();

        UptimeMonitor::factory()->for($siteUp)->up()->create();
        UptimeMonitor::factory()->for($siteDown)->down()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class)
            ->set('filter', 'down');

        $monitors = $component->viewData('monitors');

        $this->assertCount(1, $monitors);
        $this->assertEquals($siteDown->id, $monitors->first()->site_id);
    }

    #[Test]
    public function user_can_filter_monitors_by_degraded_status(): void
    {
        $siteDegraded = Site::factory()->for($this->admin)->create();
        $siteUp = Site::factory()->for($this->admin)->create();

        UptimeMonitor::factory()->for($siteDegraded)->degraded()->create();
        UptimeMonitor::factory()->for($siteUp)->up()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class)
            ->set('filter', 'degraded');

        $monitors = $component->viewData('monitors');

        $this->assertCount(1, $monitors);
        $this->assertEquals($siteDegraded->id, $monitors->first()->site_id);
    }

    #[Test]
    public function user_can_filter_monitors_by_paused_status(): void
    {
        $sitePaused = Site::factory()->for($this->admin)->create();
        $siteUp = Site::factory()->for($this->admin)->create();

        UptimeMonitor::factory()->for($sitePaused)->paused()->create();
        UptimeMonitor::factory()->for($siteUp)->up()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class)
            ->set('filter', 'paused');

        $monitors = $component->viewData('monitors');

        $this->assertCount(1, $monitors);
        $this->assertEquals($sitePaused->id, $monitors->first()->site_id);
    }

    // ─── testMonitor() ────────────────────────────────────────────────

    #[Test]
    public function admin_can_dispatch_immediate_check_for_monitor(): void
    {
        Queue::fake();

        $site = Site::factory()->for($this->admin)->create();
        $monitor = UptimeMonitor::factory()->for($site)->up()->create();

        Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class)
            ->call('testMonitor', $monitor->id);

        Queue::assertPushed(CheckUptime::class, function (CheckUptime $job) use ($monitor) {
            return $job->monitor->id === $monitor->id;
        });
    }

    // ─── pauseMonitor() / resumeMonitor() ─────────────────────────────

    #[Test]
    public function admin_can_pause_a_monitor(): void
    {
        $site = Site::factory()->for($this->admin)->create();
        $monitor = UptimeMonitor::factory()->for($site)->up()->create();

        Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class)
            ->call('pauseMonitor', $monitor->id);

        $this->assertDatabaseHas('uptime_monitors', [
            'id' => $monitor->id,
            'status' => 'paused',
        ]);
    }

    #[Test]
    public function admin_can_resume_a_paused_monitor(): void
    {
        $site = Site::factory()->for($this->admin)->create();
        $monitor = UptimeMonitor::factory()->for($site)->paused()->create();

        Livewire::actingAs($this->admin)
            ->test(UptimeOverview::class)
            ->call('resumeMonitor', $monitor->id);

        $this->assertDatabaseHas('uptime_monitors', [
            'id' => $monitor->id,
            'status' => 'active',
        ]);
    }
}
