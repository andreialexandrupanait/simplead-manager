<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Ui;

use App\Backup\V2\Enums\BackupSessionState;
use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteBackups;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The V2 console had no link anywhere in the application — the only way in was
 * to know and type /backup-v2. A pilot nobody can reach is not a pilot.
 *
 * The banner has to appear under exactly the conditions that guard the route
 * (EnsureBackupV2Ui: ui flag + admin) AND the engine allowlist, or it becomes a
 * link to a 404 or to a console whose every button is refused.
 */
class SiteBackupsV2LinkTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function pilotSite(): Site
    {
        $site = Site::factory()->create();
        config([
            'backup_v2.ui_enabled' => true,
            'backup_v2.enabled' => true,
            'backup_v2.site_ids' => [(string) $site->id],
        ]);

        return $site;
    }

    public function test_a_pilot_site_shows_the_console_link(): void
    {
        $site = $this->pilotSite();

        Livewire::actingAs($this->admin())
            ->test(SiteBackups::class, ['site' => $site])
            ->assertSee('Open the V2 console')
            ->assertSee(route('backup-v2.site', $site), false);
    }

    public function test_the_banner_reports_the_latest_session(): void
    {
        $site = $this->pilotSite();
        $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        Livewire::actingAs($this->admin())
            ->test(SiteBackups::class, ['site' => $site])
            ->assertSee('completed');
    }

    public function test_a_pilot_site_with_no_session_says_so(): void
    {
        $site = $this->pilotSite();

        Livewire::actingAs($this->admin())
            ->test(SiteBackups::class, ['site' => $site])
            ->assertSee('No V2 backup has run yet.');
    }

    public function test_a_site_outside_the_allowlist_shows_nothing(): void
    {
        $site = Site::factory()->create();
        config([
            'backup_v2.ui_enabled' => true,
            'backup_v2.enabled' => true,
            'backup_v2.site_ids' => ['999999'],
        ]);

        Livewire::actingAs($this->admin())
            ->test(SiteBackups::class, ['site' => $site])
            ->assertDontSee('Open the V2 console');
    }

    /**
     * The engine kill-switch off means the console's buttons would all refuse,
     * so the link must not be offered even though the UI flag is on.
     */
    public function test_the_engine_kill_switch_hides_the_link(): void
    {
        $site = Site::factory()->create();
        config([
            'backup_v2.ui_enabled' => true,
            'backup_v2.enabled' => false,
            'backup_v2.site_ids' => [(string) $site->id],
        ]);

        Livewire::actingAs($this->admin())
            ->test(SiteBackups::class, ['site' => $site])
            ->assertDontSee('Open the V2 console');
    }

    public function test_the_ui_flag_off_hides_the_link(): void
    {
        $site = Site::factory()->create();
        config([
            'backup_v2.ui_enabled' => false,
            'backup_v2.enabled' => true,
            'backup_v2.site_ids' => [(string) $site->id],
        ]);

        Livewire::actingAs($this->admin())
            ->test(SiteBackups::class, ['site' => $site])
            ->assertDontSee('Open the V2 console');
    }

    /**
     * EnsureBackupV2Ui 403s a non-admin, so offering them the link would be
     * offering a door that slams.
     */
    public function test_a_non_admin_is_not_offered_the_console(): void
    {
        $site = $this->pilotSite();
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)
            ->test(SiteBackups::class, ['site' => $site])
            ->assertDontSee('Open the V2 console');
    }
}
