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
 * The fleet sidebar: one entry per destination, no entry that duplicates
 * another.
 *
 * It used to carry "Sites" (the same component the landing page renders, so
 * clicking it went nowhere new) and a second "Settings" beside the one already
 * pinned in the rail footer — and that second one was not admin-gated, so a
 * non-admin clicking it got a 403.
 */
class SidebarFleetNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_fleet_sidebar_carries_the_dashboard_and_alerts_with_counts(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Site::factory()->count(3)->create(['is_up' => true, 'is_connected' => true, 'health_score' => 95]);
        Site::factory()->count(2)->create(['is_up' => false, 'is_connected' => true, 'health_score' => 95]);

        $html = Blade::render('<x-sidebar.global-sidebar />');

        // "Panou" was a Romanian string hardcoded as the source text, so it read
        // as Romanian even to an English user. The key is English now.
        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('Alerts', $html);

        // Alerts is its own screen now, not a tab on the landing page.
        $this->assertStringContainsString(route('alerts.index'), $html);
        $this->assertStringNotContainsString('tab=alerts', $html);

        // Two of the five sites are down.
        $this->assertStringContainsString('>2<', str_replace([' ', "\n"], '', $html));
    }

    public function test_it_does_not_repeat_the_landing_page_or_the_settings_link(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $html = Blade::render('<x-sidebar.global-sidebar />');

        // `sites.index` renders the very same component as `dashboard`.
        $this->assertStringNotContainsString(route('sites.index'), $html);

        // Settings is an account control, not navigation — it lives in the
        // header's account menu now, once.
        $this->assertStringNotContainsString(route('settings.general'), $html);
    }

    public function test_the_slim_set_keeps_records_and_the_two_fleet_wide_modules(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $html = Blade::render('<x-sidebar.global-sidebar />');

        $this->assertStringContainsString(route('updates.index'), $html);
        $this->assertStringContainsString(route('reports.index'), $html);
        $this->assertStringContainsString(route('activity.index'), $html);

        // Backups and Uptime are the two modules you check across the whole
        // fleet without a site in mind. Their only way in used to be a stat card
        // on the landing page or typing the URL.
        $this->assertStringContainsString(route('backups.index'), $html);
        $this->assertStringContainsString(route('uptime.index'), $html);
    }

    /**
     * The rest of the per-site modules stay in the site context — the nav is
     * still exception-first, not a directory of every route.
     */
    public function test_the_remaining_per_site_modules_stay_out(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $html = Blade::render('<x-sidebar.global-sidebar />');

        $this->assertStringNotContainsString(route('security.index'), $html);
        $this->assertStringNotContainsString(route('performance.index'), $html);
        $this->assertStringNotContainsString(route('dns.index'), $html);
    }
}
