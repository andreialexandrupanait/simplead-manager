<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\SitesList;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The landing screen: fleet numbers first, then the sites.
 *
 * It used to open with the three-band Panou — a wall of red listing every
 * unhappy site before you could see your fleet. That list moved to /alerts, and
 * the count that replaced it moved again, to the sidebar's Alerts entry: the
 * landing page said the same number twice, once cached and once not, so the two
 * could disagree on screen.
 */
class SitesListPanouTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_the_landing_page_leads_with_the_fleet_numbers(): void
    {
        Site::factory()->count(3)->create(['is_up' => true, 'is_connected' => true, 'health_score' => 95]);

        Livewire::test(SitesList::class)
            ->assertSee(__('Sites'))
            ->assertSee(__('Uptime'));
    }

    /**
     * The landing page shows the fleet, not the exceptions. A site that needs a
     * human is still listed — it just does not get a red banner above the fold.
     */
    public function test_the_landing_page_carries_no_attention_banner(): void
    {
        Site::factory()->create(['name' => 'down-site', 'is_up' => false, 'is_connected' => true]);

        Livewire::test(SitesList::class)
            ->assertDontSee(__('View alerts'))
            ->assertSee('down-site');
    }

    public function test_classic_dashboard_is_kept_as_a_backup_route_and_linked(): void
    {
        \Livewire\Livewire::test(\App\Livewire\Dashboard\GlobalDashboard::class)->assertOk();

        Livewire::test(SitesList::class)->assertSee(route('dashboard.classic'), false);
    }

    /**
     * The All/Updates/Alerts/Plans tab row is gone. Updates and Alerts have their
     * own screens in the sidebar, "Plans" never filtered anything (it was a
     * grouping wearing a filter's clothes), and "All" was the default. What is
     * left is one filter bar instead of two stacked ones.
     */
    public function test_the_primary_tab_row_is_gone_and_every_site_is_listed(): void
    {
        $withUpdate = Site::factory()->create(['name' => 'needs-update', 'is_up' => true, 'is_connected' => true]);
        \App\Models\SitePlugin::factory()->for($withUpdate)->create(['has_update' => true]);
        Site::factory()->create(['name' => 'up-to-date', 'is_up' => true, 'is_connected' => true]);

        Livewire::test(SitesList::class)
            ->set('viewMode', 'list')
            ->assertDontSee(__('Plans'))
            ->assertSee('needs-update')
            ->assertSee('up-to-date');
    }

    public function test_the_whole_fleet_fits_on_one_page(): void
    {
        // 16 per page meant three pages for a 40-site fleet — pagination for its
        // own sake. The default is 50 now.
        Site::factory()->count(20)->create(['is_up' => true, 'is_connected' => true]);

        $sites = Livewire::test(SitesList::class)->viewData('sites');

        $this->assertSame(1, $sites->lastPage());
        $this->assertSame(50, $sites->perPage());
    }
}
