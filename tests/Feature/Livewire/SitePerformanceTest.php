<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\RunPerformanceTest;
use App\Livewire\Sites\Detail\SitePerformance;
use App\Models\PerformanceMonitor;
use App\Models\PerformancePage;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private PerformanceMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
        $this->monitor = PerformanceMonitor::create([
            'site_id' => $this->site->id,
            'is_active' => true,
            'frequency' => 'daily',
            'test_time' => '04:00',
            'alert_on_score_drop' => true,
            'score_drop_threshold' => 10,
        ]);
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_site_performance_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_without_any_test_data(): void
    {
        $site = Site::factory()->for($this->admin)->create();

        Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $site])
            ->assertOk();
    }

    // ─── runTest() ────────────────────────────────────────────────────

    #[Test]
    public function user_can_trigger_performance_test(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $this->site])
            ->call('runTest');

        Queue::assertPushed(RunPerformanceTest::class, function (RunPerformanceTest $job) {
            return $job->monitor->id === $this->monitor->id;
        });
    }

    #[Test]
    public function running_test_creates_monitor_if_none_exists(): void
    {
        Queue::fake();

        $site = Site::factory()->for($this->admin)->create();

        $this->assertNull($site->performanceMonitor);

        Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $site])
            ->call('runTest');

        $this->assertNotNull($site->fresh()->performanceMonitor);
        Queue::assertPushed(RunPerformanceTest::class);
    }

    #[Test]
    public function running_test_sets_is_running_flag(): void
    {
        Queue::fake();

        $component = Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $this->site])
            ->call('runTest');

        $this->assertTrue($component->get('isRunning'));
    }

    // ─── setHistoryRange() ────────────────────────────────────────────

    #[Test]
    public function user_can_change_history_range(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $this->site])
            ->call('setHistoryRange', '7d');

        $this->assertEquals('7d', $component->get('historyRange'));
    }

    // ─── addPage() / removePage() ─────────────────────────────────────

    #[Test]
    public function user_can_add_a_performance_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $this->site])
            ->set('newPageLabel', 'Homepage')
            ->set('newPageUrl', 'https://example.com')
            ->call('addPage');

        $this->assertDatabaseHas('performance_pages', [
            'performance_monitor_id' => $this->monitor->id,
            'label' => 'Homepage',
            'url' => 'https://example.com',
            'is_primary' => true,
        ]);
    }

    #[Test]
    public function add_page_requires_valid_url(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $this->site])
            ->set('newPageLabel', 'Blog')
            ->set('newPageUrl', 'not-a-url')
            ->call('addPage')
            ->assertHasErrors(['newPageUrl']);
    }

    #[Test]
    public function user_can_remove_a_performance_page(): void
    {
        $page = PerformancePage::create([
            'performance_monitor_id' => $this->monitor->id,
            'label' => 'About',
            'url' => 'https://example.com/about',
            'is_primary' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $this->site])
            ->call('removePage', $page->id);

        $this->assertDatabaseMissing('performance_pages', ['id' => $page->id]);
    }

    // ─── toggleActive() ───────────────────────────────────────────────

    #[Test]
    public function user_can_toggle_monitor_active_state(): void
    {
        $this->assertTrue($this->monitor->is_active);

        Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $this->site])
            ->call('toggleActive');

        $this->assertFalse($this->monitor->fresh()->is_active);
    }

    // ─── saveBudgets() ────────────────────────────────────────────────

    #[Test]
    public function user_can_save_performance_budgets(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SitePerformance::class, ['site' => $this->site])
            ->set('budgetForm', ['performance_score' => '80', 'lcp' => '2.5'])
            ->call('saveBudgets');

        $this->assertNotNull($this->monitor->fresh()->budgets);
        $this->assertEquals(80, $this->monitor->fresh()->budgets['performance_score']);
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_site_performance(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SitePerformance::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
