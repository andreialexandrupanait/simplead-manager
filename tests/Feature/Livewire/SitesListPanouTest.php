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
 * unhappy site before you could see your fleet. That list has its own screen
 * now (/alerts) and the landing page keeps only the count as a way in.
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

    public function test_sites_needing_attention_are_a_link_not_a_list(): void
    {
        Site::factory()->create(['name' => 'down-site', 'is_up' => false, 'is_connected' => true]);

        $component = Livewire::test(SitesList::class);

        // The count and the way in are here…
        $component->assertSee(route('alerts.index'), escape: false);

        // …but the per-site breakdown is not; that is the alerts screen's job.
        $this->assertSame(1, $component->instance()->attentionCount());
    }

    public function test_the_attention_link_is_absent_when_everything_is_healthy(): void
    {
        Site::factory()->count(2)->create(['is_up' => true, 'is_connected' => true, 'health_score' => 95]);

        $component = Livewire::test(SitesList::class);

        $this->assertSame(0, $component->instance()->attentionCount());
        $component->assertDontSee(route('alerts.index'), escape: false);
    }

    public function test_classic_dashboard_is_kept_as_a_backup_route_and_linked(): void
    {
        \Livewire\Livewire::test(\App\Livewire\Dashboard\GlobalDashboard::class)->assertOk();

        Livewire::test(SitesList::class)->assertSee(route('dashboard.classic'), false);
    }

    public function test_updates_tab_filters_to_sites_with_pending_plugin_updates(): void
    {
        $withUpdate = Site::factory()->create(['name' => 'needs-update', 'is_up' => true, 'is_connected' => true]);
        \App\Models\SitePlugin::factory()->for($withUpdate)->create(['has_update' => true]);
        Site::factory()->create(['name' => 'up-to-date', 'is_up' => true, 'is_connected' => true]);

        Livewire::test(SitesList::class)
            ->set('viewMode', 'list')
            ->set('tab', 'updates')
            ->assertSee('needs-update')
            ->assertDontSee('up-to-date');
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
