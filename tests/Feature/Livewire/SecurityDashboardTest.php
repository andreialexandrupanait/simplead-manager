<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Security\SecurityDashboard;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityDashboardTest extends TestCase
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

    private function failedSetting(Site $site, string $key = 'hide_wp_version'): SecuritySetting
    {
        return SecuritySetting::create([
            'site_id' => $site->id,
            'category' => 'hardening',
            'setting_key' => $key,
            'setting_value' => ['enabled' => true],
            'is_enabled' => true,
            'failed_at' => now(),
            'failure_reason' => 'test',
        ]);
    }

    /** The regression this PR fixes: the tile ignored unconfigured sites, the filter didn't. */
    public function test_at_risk_tile_count_matches_the_at_risk_filtered_list(): void
    {
        $admin = User::factory()->admin()->create();
        Site::factory()->create(['security_hardening_score' => 40]);
        Site::factory()->create(['security_hardening_score' => null]);
        Site::factory()->create(['security_hardening_score' => 90]);

        $component = Livewire::actingAs($admin)
            ->test(SecurityDashboard::class)
            ->set('scoreFilter', 'at_risk');

        $this->assertSame(2, $component->instance()->atRiskSites());
        $this->assertSame(2, $component->instance()->sites()->total());
    }

    public function test_failed_filter_shows_only_sites_with_failed_settings(): void
    {
        $admin = User::factory()->admin()->create();
        $failing = Site::factory()->create(['name' => 'Failing Site']);
        Site::factory()->create(['name' => 'Healthy Site']);
        $this->failedSetting($failing);

        Livewire::actingAs($admin)
            ->test(SecurityDashboard::class)
            ->set('scoreFilter', 'failed')
            ->assertSee('Failing Site')
            ->assertDontSee('Healthy Site');
    }

    /** Tile counts failed settings (rows); tab label counts affected sites. */
    public function test_failed_settings_count_vs_failed_sites_count(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();
        $this->failedSetting($site, 'hide_wp_version');
        $this->failedSetting($site, 'restrict_xmlrpc');

        $component = Livewire::actingAs($admin)->test(SecurityDashboard::class);

        $this->assertSame(2, $component->instance()->failedSettingsCount());
        $this->assertSame(1, $component->instance()->failedSitesCount());
    }

    public function test_changing_the_filter_resets_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        Site::factory()->count(51)->create(['security_hardening_score' => 90]);

        Livewire::actingAs($admin)
            ->test(SecurityDashboard::class)
            ->call('nextPage')
            ->set('scoreFilter', 'excellent')
            ->assertSet('paginators.page', 1);
    }

    public function test_presets_cta_is_admin_only(): void
    {
        Site::factory()->create(['user_id' => User::factory()->admin()->create()->id]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(SecurityDashboard::class)
            ->assertSee('Manage Presets');

        Livewire::actingAs(User::factory()->manager()->create())
            ->test(SecurityDashboard::class)
            ->assertDontSee('Manage Presets');
    }

    public function test_manager_sees_only_their_own_sites_in_tiles_and_list(): void
    {
        $manager = User::factory()->manager()->create();
        $other = User::factory()->manager()->create();

        $mine = Site::factory()->create(['user_id' => $manager->id, 'name' => 'Mine', 'security_hardening_score' => 30]);
        Site::factory()->create(['user_id' => $other->id, 'name' => 'Theirs', 'security_hardening_score' => 20]);
        $this->failedSetting($mine);

        $component = Livewire::actingAs($manager)->test(SecurityDashboard::class);

        $this->assertSame(1, $component->instance()->atRiskSites());
        $this->assertSame(1, $component->instance()->failedSitesCount());
        $component->assertSee('Mine')->assertDontSee('Theirs');
    }
}
