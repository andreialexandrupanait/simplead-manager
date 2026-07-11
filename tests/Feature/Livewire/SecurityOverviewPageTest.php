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

    protected function setUp(): void
    {
        parent::setUp();
        // Site::created() dispatches FetchSiteFavicon + plan setup — on the
        // sync queue in CI that means real HTTP calls per created site (the
        // 51-site pagination test timed out the whole suite).
        \Illuminate\Support\Facades\Queue::fake();
    }

    /** @return array{0: Site, 1: User} */
    private function siteWithManager(): array
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);

        return [$site, $manager];
    }

    public function test_hub_renders_the_wordpress_tweaks_band_with_all_leaf_links(): void
    {
        [$site, $manager] = $this->siteWithManager();

        Livewire::actingAs($manager)
            ->test(SecurityOverview::class, ['site' => $site])
            ->assertSee('WordPress Tweaks')
            ->assertSee(route('sites.tweaks.performance', $site))
            ->assertSee(route('sites.tweaks.site-control', $site))
            ->assertSee(route('sites.tweaks.admin-ux', $site))
            ->assertSee(route('sites.tweaks.content-media', $site))
            ->assertDontSee('Looking for Performance & Site Control?');
    }

    public function test_old_tweaks_overview_url_redirects_to_the_hub(): void
    {
        [$site, $manager] = $this->siteWithManager();

        $this->actingAs($manager)
            ->get(route('sites.tweaks', $site))
            ->assertRedirect(route('sites.security', $site));
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
        SecuritySetting::create([
            'site_id' => $site->id, 'category' => 'performance', 'setting_key' => 'disable_emojis',
            'setting_value' => [], 'is_enabled' => true, 'failed_at' => now(), 'failure_reason' => 'x',
        ]);

        $items = Livewire::actingAs($manager)
            ->test(SecurityOverview::class, ['site' => $site])
            ->instance()->attentionItems();

        $hardening = $items->firstWhere('key', 'hardening');
        $login = $items->firstWhere('key', 'login');
        $performance = $items->firstWhere('key', 'performance');

        $this->assertSame(1, $hardening['failed']);
        $this->assertSame(1, $login['pending']);
        $this->assertSame(1, $performance['failed']);
    }

    public function test_next_actions_surface_the_heaviest_unapplied_settings_first(): void
    {
        [$site, $manager] = $this->siteWithManager();

        $component = Livewire::actingAs($manager)->test(SecurityOverview::class, ['site' => $site]);
        $actions = $component->instance()->nextActions();

        $this->assertSame('brute_force_protection', $actions[0]['key']);
        $this->assertSame(15, $actions[0]['weight']);
        $this->assertCount(3, $actions);

        // Apply brute force → the next heaviest (a 10-pointer) takes the lead.
        SecuritySetting::create([
            'site_id' => $site->id, 'category' => 'login', 'setting_key' => 'brute_force_protection',
            'setting_value' => [], 'is_enabled' => true, 'applied_at' => now(),
        ]);

        $fresh = Livewire::actingAs($manager)->test(SecurityOverview::class, ['site' => $site]);
        $this->assertSame(10, $fresh->instance()->nextActions()[0]['weight']);
        $component->assertSee('Boost your score');
    }

    public function test_repush_settings_queues_both_push_jobs(): void
    {
        [$site, $manager] = $this->siteWithManager();

        Livewire::actingAs($manager)
            ->test(SecurityOverview::class, ['site' => $site])
            ->call('repushSettings');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\PushSecuritySettings::class);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\PushSiteTweaksSettings::class);
    }

    public function test_viewer_cannot_repush_settings(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id, 'is_connected' => true]);

        Livewire::actingAs($viewer)
            ->test(SecurityOverview::class, ['site' => $site])
            ->call('repushSettings')
            ->assertForbidden();

        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\PushSecuritySettings::class);
    }

    public function test_attention_items_link_to_their_category_page(): void
    {
        [$site, $manager] = $this->siteWithManager();

        SecuritySetting::create([
            'site_id' => $site->id, 'category' => 'hardening', 'setting_key' => 'hide_wp_version',
            'setting_value' => [], 'is_enabled' => true, 'failed_at' => now(), 'failure_reason' => 'x',
        ]);

        $items = Livewire::actingAs($manager)
            ->test(SecurityOverview::class, ['site' => $site])
            ->instance()->attentionItems();

        $this->assertSame('sites.security.hardening', $items->firstWhere('key', 'hardening')['route']);
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
                'WordPress Tweaks',
                'Performance',
                'Site Control',
                'Admin UX',
                'Content & Media',
            ]);
    }
}
