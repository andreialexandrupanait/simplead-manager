<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\CheckUptime;
use App\Livewire\Sites\Detail\SiteUptime;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteUptimeTest extends TestCase
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
    public function user_can_view_site_uptime_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteUptime::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_with_an_active_monitor(): void
    {
        UptimeMonitor::factory()->up()->for($this->site)->create();

        Livewire::actingAs($this->admin)
            ->test(SiteUptime::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_with_no_monitor(): void
    {
        $site = Site::factory()->for($this->admin)->create();
        $this->assertNull($site->uptimeMonitor);

        Livewire::actingAs($this->admin)
            ->test(SiteUptime::class, ['site' => $site])
            ->assertOk();
    }

    // ─── pauseMonitor() / resumeMonitor() ─────────────────────────────

    #[Test]
    public function user_can_pause_monitor(): void
    {
        $monitor = UptimeMonitor::factory()->up()->for($this->site)->create();

        Livewire::actingAs($this->admin)
            ->test(SiteUptime::class, ['site' => $this->site])
            ->call('pauseMonitor');

        $this->assertEquals('paused', $monitor->fresh()->status->value);
    }

    #[Test]
    public function user_can_resume_paused_monitor(): void
    {
        $monitor = UptimeMonitor::factory()->paused()->for($this->site)->create();

        Livewire::actingAs($this->admin)
            ->test(SiteUptime::class, ['site' => $this->site])
            ->call('resumeMonitor');

        $this->assertEquals('active', $monitor->fresh()->status->value);
    }

    // ─── testNow() ────────────────────────────────────────────────────

    #[Test]
    public function user_can_trigger_uptime_check(): void
    {
        Queue::fake();

        UptimeMonitor::factory()->up()->for($this->site)->create();

        Livewire::actingAs($this->admin)
            ->test(SiteUptime::class, ['site' => $this->site])
            ->call('testNow');

        Queue::assertPushed(CheckUptime::class);
    }

    #[Test]
    public function test_now_does_nothing_when_no_monitor(): void
    {
        Queue::fake();

        $site = Site::factory()->for($this->admin)->create();

        Livewire::actingAs($this->admin)
            ->test(SiteUptime::class, ['site' => $site])
            ->call('testNow');

        Queue::assertNotPushed(CheckUptime::class);
    }

    // ─── checkCount computed ──────────────────────────────────────────

    #[Test]
    public function check_count_is_zero_with_no_monitor(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        $component = Livewire::actingAs($this->admin)
            ->test(SiteUptime::class, ['site' => $site]);

        $this->assertEquals(0, $component->instance()->checkCount);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_site_uptime(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SiteUptime::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
