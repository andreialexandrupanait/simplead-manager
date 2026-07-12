<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Livewire\Components\CopySettingsModal;
use App\Livewire\Components\NotificationDropdown;
use App\Livewire\ErrorLogs\ErrorLogsOverview;
use App\Livewire\MaintenancePlans;
use App\Livewire\Reports\ReportsOverview;
use App\Livewire\Security\PresetManager;
use App\Livewire\Seo\SeoQuickAudit;
use App\Livewire\Sites\CreateSiteWizard;
use App\Livewire\Sites\Detail\ReportRecommendationsManager;
use App\Livewire\Sites\Detail\Security\SecurityIpManagement;
use App\Livewire\Sites\Detail\SiteOverview;
use App\Livewire\Sites\Detail\SiteReports;
use App\Livewire\Updates\UpdatesOverview;
use App\Livewire\Uptime\UptimeOverview;
use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\User;
use App\Services\BulkSettingsCopyService;
use App\Services\PluginManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Representative coverage for the Viewer-write authorization sweep (E-09 +
 * P0-authorization-sweep): a read-only Viewer must be blocked from mutating
 * actions across the site-detail components and the global plan manager, and
 * non-admins must not be able to mutate/leak across tenants. The guard itself
 * is the same authorizeSiteModification()/isViewer() pattern applied everywhere.
 */
class ViewerWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Site creation dispatches FetchSiteFavicon (outbound HTTP) — keep tests
        // hermetic.
        Queue::fake();
        Http::fake();
    }

    public function test_viewer_blocked_on_site_detail_recommendation_mutation(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        Livewire::actingAs($viewer)
            ->test(ReportRecommendationsManager::class, ['site' => $site])
            ->call('regenerateSuggestions')
            ->assertForbidden();
    }

    public function test_manager_allowed_to_mount_recommendation_manager(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id]);

        Livewire::actingAs($manager)
            ->test(ReportRecommendationsManager::class, ['site' => $site])
            ->assertOk();
    }

    public function test_viewer_blocked_on_maintenance_plan_delete(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)
            ->test(MaintenancePlans::class)
            ->call('delete')
            ->assertForbidden();
    }

    // ---- P0-13: WP-admin auto-login minting / impersonation switch ----

    public function test_viewer_blocked_on_open_wp_admin(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        Livewire::actingAs($viewer)
            ->test(SiteOverview::class, ['site' => $site])
            ->call('openWpAdmin')
            ->assertForbidden();
    }

    public function test_viewer_blocked_on_set_wp_admin_user(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        Livewire::actingAs($viewer)
            ->test(SiteOverview::class, ['site' => $site])
            ->call('setWpAdminUser', null)
            ->assertForbidden();
    }

    // ---- P0-14: connector secret exposure via Connect modal ----

    public function test_viewer_blocked_on_open_connect_modal(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        Livewire::actingAs($viewer)
            ->test(SiteOverview::class, ['site' => $site])
            ->call('openConnectModal')
            ->assertForbidden();
    }

    public function test_open_connect_modal_never_round_trips_the_secret(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create([
            'user_id' => $manager->id,
            'api_key' => 'plaintext-api-key-1234567890',
            'api_secret' => 'plaintext-api-secret-0987654321',
        ]);

        Livewire::actingAs($manager)
            ->test(SiteOverview::class, ['site' => $site])
            ->call('openConnectModal')
            ->assertSet('apiSecret', '');
    }

    // ---- P0-09: bulk plugin update per-site access ----

    public function test_viewer_blocked_on_bulk_plugin_update(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)
            ->test(UpdatesOverview::class)
            ->call('updatePluginAcrossSites', 'akismet')
            ->assertForbidden();
    }

    public function test_bulk_plugin_update_skips_inaccessible_sites(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $other = User::factory()->create(['role' => UserRole::Manager]);

        $mine = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);
        $theirs = Site::factory()->create(['user_id' => $other->id, 'is_connected' => true]);

        $minePlugin = SitePlugin::factory()->create([
            'site_id' => $mine->id, 'slug' => 'akismet', 'file' => 'akismet/akismet.php',
            'has_update' => true, 'update_version' => '5.3',
        ]);
        $theirsPlugin = SitePlugin::factory()->create([
            'site_id' => $theirs->id, 'slug' => 'akismet', 'file' => 'akismet/akismet.php',
            'has_update' => true, 'update_version' => '5.3',
        ]);

        // performUpdate must only ever run for the accessible site.
        $this->mock(PluginManagerService::class, function ($mock) {
            $mock->shouldReceive('performUpdate')->andReturn(['success' => true, 'version' => '5.3']);
        });

        Livewire::actingAs($manager)
            ->test(UpdatesOverview::class)
            ->call('updatePluginAcrossSites', 'akismet');

        // Accessible site got updated, inaccessible one was skipped untouched.
        $this->assertFalse($minePlugin->fresh()->has_update);
        $this->assertTrue($theirsPlugin->fresh()->has_update);
    }

    // ---- P0-10: CopySettingsModal cross-tenant write IDOR ----

    public function test_viewer_blocked_on_copy_settings_apply(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $source = Site::factory()->create(['user_id' => $viewer->id]);
        $target = Site::factory()->create(['user_id' => $viewer->id]);

        Livewire::actingAs($viewer)
            ->test(CopySettingsModal::class, ['sourceSite' => $source])
            ->set('showSecurityOption', true)
            ->set('copySecuritySettings', true)
            ->set('selectedSiteIds', [(string) $target->id])
            ->call('apply')
            ->assertForbidden();
    }

    public function test_copy_settings_mount_blocks_inaccessible_source(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $other = User::factory()->create(['role' => UserRole::Manager]);
        $source = Site::factory()->create(['user_id' => $other->id]);

        Livewire::actingAs($manager)
            ->test(CopySettingsModal::class, ['sourceSite' => $source])
            ->assertForbidden();
    }

    public function test_copy_settings_rescopes_targets_to_accessible_sites(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $other = User::factory()->create(['role' => UserRole::Manager]);

        $source = Site::factory()->create(['user_id' => $manager->id]);
        $foreignTarget = Site::factory()->create(['user_id' => $other->id]);

        // The service must never be handed the cross-tenant site — the crafted
        // selectedSiteIds is re-scoped server-side to an empty set.
        $this->mock(BulkSettingsCopyService::class, function ($mock) {
            $mock->shouldNotReceive('copySecuritySettings');
            $mock->shouldNotReceive('copyTweakSettings');
            $mock->shouldNotReceive('copyModuleConfig');
        });

        Livewire::actingAs($manager)
            ->test(CopySettingsModal::class, ['sourceSite' => $source])
            ->set('showSecurityOption', true)
            ->set('copySecuritySettings', true)
            ->set('selectedSiteIds', [(string) $foreignTarget->id])
            ->call('apply')
            ->assertHasNoErrors();
    }

    // ---- P0-24: Reports "Generate All" ----

    public function test_viewer_blocked_on_generate_all_reports(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)
            ->test(ReportsOverview::class)
            ->call('generateAllReports')
            ->assertForbidden();
    }

    // ---- P0-25: report trait viewer-guard gaps ----

    public function test_viewer_blocked_on_report_distribution_and_generation(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        foreach ([
            ['sendReport', []],
            ['bulkSend', [[1], 'x@example.com']],
            ['bulkDelete', [[1]]],
            ['confirmGenerate', []],
            ['proceedToRecommendations', []],
            ['toggleRecommendation', [1]],
            ['removeRecommendation', [1]],
            ['addCustomRecommendation', []],
        ] as [$method, $args]) {
            Livewire::actingAs($viewer)
                ->test(SiteReports::class, ['site' => $site])
                ->call($method, ...$args)
                ->assertForbidden();
        }
    }

    // ---- P1-04: residual viewer/authz sweep ----

    public function test_viewer_blocked_on_security_ip_verify_settings(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        Livewire::actingAs($viewer)
            ->test(SecurityIpManagement::class, ['site' => $site])
            ->call('verifySettings')
            ->assertForbidden();
    }

    public function test_viewer_blocked_on_error_log_resolve(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        $log = PhpErrorLog::create([
            'site_id' => $site->id,
            'level' => 'fatal',
            'message' => 'Uncaught Error',
            'message_hash' => str_repeat('a', 32),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Livewire::actingAs($viewer)
            ->test(ErrorLogsOverview::class)
            ->call('resolve', $log->id)
            ->assertForbidden();

        $this->assertFalse($log->fresh()->is_resolved);
    }

    public function test_viewer_blocked_on_seo_quick_audit_actions(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)
            ->test(SeoQuickAudit::class)
            ->set('url', 'https://example.com')
            ->call('runQuickAudit')
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(SeoQuickAudit::class)
            ->call('deleteProspect', 1)
            ->assertForbidden();
    }

    public function test_viewer_blocked_on_create_site_and_client(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)
            ->test(CreateSiteWizard::class)
            ->call('createSite')
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(CreateSiteWizard::class)
            ->call('createClient')
            ->assertForbidden();
    }

    public function test_viewer_blocked_on_notification_backup_retries(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        Livewire::actingAs($viewer)
            ->test(NotificationDropdown::class)
            ->call('retrySiteBackup', $site->id)
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(NotificationDropdown::class)
            ->call('retryFailedBackups')
            ->assertForbidden();
    }

    public function test_manager_blocked_from_retrying_backup_on_foreign_site(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $other = User::factory()->create(['role' => UserRole::Manager]);
        $foreign = Site::factory()->create(['user_id' => $other->id]);

        Livewire::actingAs($manager)
            ->test(NotificationDropdown::class)
            ->call('retrySiteBackup', $foreign->id)
            ->assertForbidden();
    }

    public function test_add_monitors_for_all_sites_is_scoped_to_visible_sites(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $other = User::factory()->create(['role' => UserRole::Manager]);

        $mine = Site::factory()->create(['user_id' => $manager->id]);
        $foreign = Site::factory()->create(['user_id' => $other->id]);

        Livewire::actingAs($manager)
            ->test(UptimeOverview::class)
            ->call('addMonitorsForAllSites');

        $this->assertDatabaseHas('uptime_monitors', ['site_id' => $mine->id]);
        $this->assertDatabaseMissing('uptime_monitors', ['site_id' => $foreign->id]);
    }

    // ---- P1-59: SecurityPresetService::createFromSite cross-tenant snapshot ----

    public function test_manager_cannot_snapshot_preset_from_foreign_site(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $other = User::factory()->create(['role' => UserRole::Manager]);
        $foreign = Site::factory()->create(['user_id' => $other->id]);

        Livewire::actingAs($manager)
            ->test(PresetManager::class)
            ->set('snapshotSiteId', $foreign->id)
            ->set('snapshotName', 'Stolen config')
            ->call('createFromSite')
            ->assertForbidden();

        $this->assertDatabaseMissing('security_presets', ['name' => 'Stolen config']);
    }
}
