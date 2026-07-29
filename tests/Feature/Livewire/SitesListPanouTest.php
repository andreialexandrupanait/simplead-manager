<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\SitesList;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SPEC §4.4/§4.5 — the main screen's three-band Panou and the primary tabs.
 */
class SitesListPanouTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Andrei is an admin → visibleTo returns the whole fleet (single-user app).
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_panou_band_1_lists_sites_needing_attention_grouped_by_client(): void
    {
        $client = Client::factory()->create(['name' => 'Acme']);
        $down = Site::factory()->create(['name' => 'down-site', 'client_id' => $client->id, 'is_up' => false, 'is_connected' => true]);
        Site::factory()->create(['name' => 'healthy-site', 'is_up' => true, 'is_connected' => true, 'health_score' => 95]);

        Livewire::test(SitesList::class)
            ->assertSee('need attention')
            ->assertSee('Acme')
            ->assertSee('down-site')
            // the healthy one is not in band 1; it is counted in band 3 ("operating normally")
            ->assertSee('operating normally');

        $this->assertNotNull($down);
    }

    public function test_panou_resting_counts_only_healthy_sites(): void
    {
        Site::factory()->count(3)->create(['is_up' => true, 'is_connected' => true, 'health_score' => 90]);
        Site::factory()->create(['is_up' => false, 'is_connected' => true, 'health_score' => 90]);

        $panou = Livewire::test(SitesList::class)->instance()->panou();

        $this->assertSame(3, $panou['resting']);
        $this->assertCount(1, collect($panou['attention'])->flatMap->sites ?? collect());
    }

    public function test_classic_dashboard_is_kept_as_a_backup_route_and_linked(): void
    {
        // The pre-§4 widget dashboard stays reachable as a backup view...
        \Livewire\Livewire::test(\App\Livewire\Dashboard\GlobalDashboard::class)->assertOk();

        // ...and the new main screen links to it.
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
}
