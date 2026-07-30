<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Alerts\AlertsOverview;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The alerts screen. The sidebar had an "Alerts" item for a while, but it
 * pointed back at the landing page with `?tab=alerts` — so the fleet overview
 * doubled as the alert list.
 *
 * The definition of "needs attention" used to be written out in three places
 * (the landing band, its tab counter, the sidebar composer), which meant the
 * numbers could disagree. The scope test below is the one that keeps them
 * honest.
 */
class AlertsOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_down_disconnected_and_unhealthy_sites_all_show_up(): void
    {
        Site::factory()->create(['name' => 'is-down', 'is_up' => false, 'is_connected' => true, 'health_score' => 90]);
        Site::factory()->create(['name' => 'is-offline', 'is_up' => true, 'is_connected' => false, 'health_score' => 90]);
        Site::factory()->create(['name' => 'is-sick', 'is_up' => true, 'is_connected' => true, 'health_score' => 20]);
        Site::factory()->create(['name' => 'is-fine', 'is_up' => true, 'is_connected' => true, 'health_score' => 95]);

        Livewire::test(AlertsOverview::class)
            ->assertSee('is-down')
            ->assertSee('is-offline')
            ->assertSee('is-sick')
            ->assertDontSee('is-fine');
    }

    public function test_sites_are_grouped_by_client_worst_first(): void
    {
        $calm = Client::factory()->create(['name' => 'Calm Client']);
        $burning = Client::factory()->create(['name' => 'Burning Client']);

        // A poor score is a warning; being down is critical.
        Site::factory()->create(['client_id' => $calm->id, 'is_up' => true, 'is_connected' => true, 'health_score' => 20]);
        Site::factory()->create(['client_id' => $burning->id, 'is_up' => false, 'is_connected' => true, 'health_score' => 90]);

        $groups = Livewire::test(AlertsOverview::class)->instance()->alerts()['groups'];

        $this->assertSame('Burning Client', $groups[0]['client']);
        $this->assertSame(0, $groups[0]['severity']);
        $this->assertSame('Calm Client', $groups[1]['client']);
    }

    public function test_a_healthy_fleet_says_so(): void
    {
        Site::factory()->count(3)->create(['is_up' => true, 'is_connected' => true, 'health_score' => 95]);

        Livewire::test(AlertsOverview::class)
            ->assertSee(__('No alerts.'))
            ->assertSee('3');
    }

    public function test_the_filters_narrow_to_one_kind_of_trouble(): void
    {
        Site::factory()->create(['name' => 'is-down', 'is_up' => false, 'is_connected' => true, 'health_score' => 90]);
        Site::factory()->create(['name' => 'is-offline', 'is_up' => true, 'is_connected' => false, 'health_score' => 90]);

        Livewire::test(AlertsOverview::class)
            ->set('filter', 'down')
            ->assertSee('is-down')
            ->assertDontSee('is-offline');
    }

    public function test_every_counter_in_the_app_agrees(): void
    {
        // One site in each state, so a mismatch between the three call sites
        // would show up as a different total.
        Site::factory()->create(['is_up' => false, 'is_connected' => true, 'health_score' => 90]);
        Site::factory()->create(['is_up' => true, 'is_connected' => false, 'health_score' => 90]);
        Site::factory()->create(['is_up' => true, 'is_connected' => true, 'health_score' => 20]);
        Site::factory()->count(2)->create(['is_up' => true, 'is_connected' => true, 'health_score' => 95]);

        $onAlertsPage = Livewire::test(AlertsOverview::class)->instance()->alerts()['total'];
        $onLandingPage = Livewire::test(\App\Livewire\Sites\SitesList::class)->instance()->attentionCount();

        // The sidebar composer reads the same scope, so asserting the scope once
        // covers all three call sites.
        $viaScope = Site::query()->visibleTo(auth()->user())->needsAttention()->count();

        $this->assertSame(3, $onAlertsPage);
        $this->assertSame(3, $onLandingPage);
        $this->assertSame(3, $viaScope);
    }
}
