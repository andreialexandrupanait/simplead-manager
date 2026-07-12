<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Livewire\Activity\ActivityTimeline;
use App\Livewire\Backups\BackupsOverview;
use App\Livewire\Components\GlobalSearch;
use App\Livewire\Performance\PerformanceOverview;
use App\Livewire\Reports\ReportsOverview;
use App\Livewire\Updates\UpdatesOverview;
use App\Livewire\Uptime\UptimeOverview;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\PerformanceMonitor;
use App\Models\Report;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\UptimeMonitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1-02: every global overview/search surface must only show the acting user's
 * visible sites (owned + assigned-client). A Manager/Viewer must never see a
 * different tenant's data; admins are unaffected. One test per swept surface.
 */
class ReadLeakSweepTest extends TestCase
{
    use RefreshDatabase;

    private const MINE = 'VisibleTenantSiteAlpha';

    private const FOREIGN = 'ForeignTenantSiteOmega';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();
    }

    /** @return array{0: User, 1: Site, 2: Site} manager, ownedSite, foreignSite */
    private function tenants(bool $connected = true): array
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $other = User::factory()->create(['role' => UserRole::Manager]);

        $mine = Site::factory()->create([
            'user_id' => $manager->id,
            'name' => self::MINE,
            'is_connected' => $connected,
        ]);
        $foreign = Site::factory()->create([
            'user_id' => $other->id,
            'name' => self::FOREIGN,
            'is_connected' => $connected,
        ]);

        return [$manager, $mine, $foreign];
    }

    public function test_updates_overview_scoped(): void
    {
        [$manager, $mine, $foreign] = $this->tenants();

        SitePlugin::factory()->create([
            'site_id' => $mine->id, 'has_update' => true, 'update_version' => '9.9',
        ]);
        SitePlugin::factory()->create([
            'site_id' => $foreign->id, 'has_update' => true, 'update_version' => '9.9',
        ]);

        Livewire::actingAs($manager)
            ->test(UpdatesOverview::class)
            ->assertSee(self::MINE)
            ->assertDontSee(self::FOREIGN);
    }

    public function test_uptime_overview_scoped(): void
    {
        [$manager, $mine, $foreign] = $this->tenants();

        UptimeMonitor::factory()->create(['site_id' => $mine->id]);
        UptimeMonitor::factory()->create(['site_id' => $foreign->id]);

        Livewire::actingAs($manager)
            ->test(UptimeOverview::class)
            ->assertSee(self::MINE)
            ->assertDontSee(self::FOREIGN);
    }

    public function test_performance_overview_scoped(): void
    {
        [$manager, $mine, $foreign] = $this->tenants();

        PerformanceMonitor::create(['site_id' => $mine->id, 'is_active' => true]);
        PerformanceMonitor::create(['site_id' => $foreign->id, 'is_active' => true]);

        Livewire::actingAs($manager)
            ->test(PerformanceOverview::class)
            ->assertSee(self::MINE)
            ->assertDontSee(self::FOREIGN);
    }

    public function test_backups_overview_scoped(): void
    {
        [$manager, $mine, $foreign] = $this->tenants();

        Backup::factory()->create(['site_id' => $mine->id]);
        Backup::factory()->create(['site_id' => $foreign->id]);

        Livewire::actingAs($manager)
            ->test(BackupsOverview::class)
            ->assertSee(self::MINE)
            ->assertDontSee(self::FOREIGN);
    }

    public function test_reports_overview_scoped(): void
    {
        [$manager, $mine, $foreign] = $this->tenants();

        Report::factory()->create(['site_id' => $mine->id, 'title' => 'Report '.self::MINE]);
        Report::factory()->create(['site_id' => $foreign->id, 'title' => 'Report '.self::FOREIGN]);

        Livewire::actingAs($manager)
            ->test(ReportsOverview::class)
            ->assertSee(self::MINE)
            ->assertDontSee(self::FOREIGN);
    }

    public function test_activity_timeline_scoped(): void
    {
        [$manager, $mine, $foreign] = $this->tenants();

        ActivityLog::factory()->create([
            'site_id' => $mine->id, 'title' => 'Event '.self::MINE, 'created_at' => now(),
        ]);
        ActivityLog::factory()->create([
            'site_id' => $foreign->id, 'title' => 'Event '.self::FOREIGN, 'created_at' => now(),
        ]);

        Livewire::actingAs($manager)
            ->test(ActivityTimeline::class)
            ->assertSee(self::MINE)
            ->assertDontSee(self::FOREIGN);
    }

    public function test_global_search_scoped(): void
    {
        [$manager, $mine, $foreign] = $this->tenants();

        // Both names share the "TenantSite" token so the unscoped query would
        // return both; the scope must drop the foreign one.
        Livewire::actingAs($manager)
            ->test(GlobalSearch::class)
            ->set('query', 'TenantSite')
            ->assertSet('isOpen', true)
            ->assertSee(self::MINE)
            ->assertDontSee(self::FOREIGN);
    }

    public function test_admin_sees_every_tenant_on_a_swept_surface(): void
    {
        [, $mine, $foreign] = $this->tenants();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        UptimeMonitor::factory()->create(['site_id' => $mine->id]);
        UptimeMonitor::factory()->create(['site_id' => $foreign->id]);

        Livewire::actingAs($admin)
            ->test(UptimeOverview::class)
            ->assertSee(self::MINE)
            ->assertSee(self::FOREIGN);
    }
}
