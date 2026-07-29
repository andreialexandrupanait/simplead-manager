<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * SPEC §3.1 — the fleet sidebar surfaces Panou, Alerte [count] and Site-uri [count].
 */
class SidebarFleetNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_fleet_sidebar_shows_panou_alerts_and_sites_with_counts(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Site::factory()->count(3)->create(['is_up' => true, 'is_connected' => true, 'health_score' => 95]);
        Site::factory()->count(2)->create(['is_up' => false, 'is_connected' => true, 'health_score' => 95]);

        $html = Blade::render('<x-sidebar.global-sidebar />');

        $this->assertStringContainsString('Panou', $html);
        $this->assertStringContainsString('Alerts', $html);
        $this->assertStringContainsString('Sites', $html);
        // Links point at the §4 screen (list + the Alerts tab).
        $this->assertStringContainsString('tab=alerts', $html);
        $this->assertStringContainsString(route('sites.index'), $html);
        // Badges: 5 visible sites, 2 alerting.
        $this->assertStringContainsString('>5<', str_replace([' ', "\n"], '', $html));
        $this->assertStringContainsString('>2<', str_replace([' ', "\n"], '', $html));

        // The slim §3.1 set keeps Updates/Reports/Activity/Settings...
        $this->assertStringContainsString(route('updates.index'), $html);
        $this->assertStringContainsString(route('settings.general'), $html);
        // ...and drops the per-site modules from the fleet nav (routes still exist).
        $this->assertStringNotContainsString(route('uptime.index'), $html);
        $this->assertStringNotContainsString(route('security.index'), $html);
    }
}
