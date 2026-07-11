<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\Security\SecurityOverview;
use App\Models\SecurityIssue;
use App\Models\SecurityScan;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The per-site Security Overview used to mix Site Tweaks cards into the
 * security grid (while Scanning and Users had no card at all) and built its
 * Needs Attention data in blade @php blocks.
 */
class SecurityOverviewPageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Site, 1: User} */
    private function siteWithManager(): array
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        return [$site, $manager];
    }

    public function test_tweaks_cards_are_gone_but_discoverability_link_remains(): void
    {
        [$site, $manager] = $this->siteWithManager();

        Livewire::actingAs($manager)
            ->test(SecurityOverview::class, ['site' => $site])
            ->assertDontSee(route('sites.tweaks.performance', $site))
            ->assertDontSee(route('sites.tweaks.site-control', $site))
            ->assertSee(route('sites.tweaks', $site));
    }

    public function test_scanning_card_counts_open_critical_and_high_issues_only(): void
    {
        [$site, $manager] = $this->siteWithManager();

        SecurityScan::create(['site_id' => $site->id, 'score' => 70, 'scanned_at' => now()->subHour()]);
        SecurityIssue::factory()->create(['site_id' => $site->id, 'severity' => 'critical']);
        SecurityIssue::factory()->create(['site_id' => $site->id, 'severity' => 'medium']);
        SecurityIssue::factory()->create(['site_id' => $site->id, 'severity' => 'critical', 'is_fixed' => true]);

        $component = Livewire::actingAs($manager)->test(SecurityOverview::class, ['site' => $site]);

        $this->assertSame(1, $component->instance()->scanSummary()['openCriticalHigh']);
        $component->assertSee('1 open issues');
    }

    public function test_users_card_shows_totals_and_admin_count(): void
    {
        [$site, $manager] = $this->siteWithManager();

        SiteUser::create(['site_id' => $site->id, 'wp_user_id' => 1, 'username' => 'a', 'email' => 'a@x.ro', 'role' => 'administrator']);
        SiteUser::create(['site_id' => $site->id, 'wp_user_id' => 2, 'username' => 'b', 'email' => 'b@x.ro', 'role' => 'editor']);
        SiteUser::create(['site_id' => $site->id, 'wp_user_id' => 3, 'username' => 'c', 'email' => 'c@x.ro', 'role' => 'author']);

        $component = Livewire::actingAs($manager)->test(SecurityOverview::class, ['site' => $site]);

        $this->assertSame(['total' => 3, 'admins' => 1], $component->instance()->usersSummary());
        $component->assertSee('3 users')->assertSee('1 admins');
    }

    public function test_attention_items_aggregate_failed_and_pending_per_category(): void
    {
        [$site, $manager] = $this->siteWithManager();

        SecuritySetting::create([
            'site_id' => $site->id, 'category' => 'hardening', 'setting_key' => 'hide_wp_version',
            'setting_value' => [], 'is_enabled' => true, 'failed_at' => now(), 'failure_reason' => 'x',
        ]);
        SecuritySetting::create([
            'site_id' => $site->id, 'category' => 'login', 'setting_key' => 'brute_force_protection',
            'setting_value' => [], 'is_enabled' => true, // no applied_at, no failed_at => pending
        ]);

        $items = Livewire::actingAs($manager)
            ->test(SecurityOverview::class, ['site' => $site])
            ->instance()->attentionItems();

        $hardening = $items->firstWhere('key', 'hardening');
        $login = $items->firstWhere('key', 'login');

        $this->assertSame(1, $hardening['failed']);
        $this->assertSame(1, $login['pending']);
    }

    public function test_cards_render_in_tab_order(): void
    {
        [$site, $manager] = $this->siteWithManager();

        Livewire::actingAs($manager)
            ->test(SecurityOverview::class, ['site' => $site])
            ->assertSeeInOrder([
                'WordPress Hardening',
                '.htaccess Rules',
                'Login Protection',
                'CAPTCHA',
                'IP Management',
                'Scanning',
                'Activity Log',
                'Users',
            ]);
    }
}
