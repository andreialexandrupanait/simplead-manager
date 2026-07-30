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

    public function test_the_fleet_sidebar_carries_panou_and_alerts_with_counts(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Site::factory()->count(3)->create(['is_up' => true, 'is_connected' => true, 'health_score' => 95]);
        Site::factory()->count(2)->create(['is_up' => false, 'is_connected' => true, 'health_score' => 95]);

        $html = Blade::render('<x-sidebar.global-sidebar />');

        $this->assertStringContainsString('Panou', $html);
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

        // Settings lives once, pinned in the rail footer (layouts/app.blade.php).
        $this->assertStringNotContainsString(route('settings.general'), $html);
    }

    public function test_the_slim_set_keeps_records_and_drops_per_site_modules(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $html = Blade::render('<x-sidebar.global-sidebar />');

        $this->assertStringContainsString(route('updates.index'), $html);
        $this->assertStringContainsString(route('reports.index'), $html);
        $this->assertStringContainsString(route('activity.index'), $html);

        // Per-site modules live in the site context; their routes still exist.
        $this->assertStringNotContainsString(route('uptime.index'), $html);
    }
}
