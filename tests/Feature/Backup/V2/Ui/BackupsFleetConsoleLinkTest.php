<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Ui;

use App\Enums\UserRole;
use App\Livewire\Backups\BackupsOverview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The way in to the engine's fleet console, from the Backups page.
 *
 * Installing the engine on a site happens in one place, /backup-v2/fleet, and nothing in the
 * application linked to it — the overview that does link to it is itself unreachable, so in practice
 * the console existed only for whoever remembered the URL. That is how a site connected after the
 * fleet migration took its first backup on the old engine.
 *
 * The link has to appear under exactly the condition EnsureBackupV2Ui enforces on the route, or it
 * becomes a link to a 404.
 */
class BackupsFleetConsoleLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_is_offered_the_fleet_console(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->test(BackupsOverview::class)
            ->assertSee('Backup engine')
            ->assertSee(route('backup-v2.fleet'), false);
    }

    public function test_the_ui_flag_off_hides_the_link(): void
    {
        // The route 404s with the flag off, so a visible link would be a dead end.
        config(['backup_v2.ui_enabled' => false]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->test(BackupsOverview::class)
            ->assertDontSee(route('backup-v2.fleet'), false);
    }

    public function test_a_non_admin_is_not_offered_the_console(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Viewer]))
            ->test(BackupsOverview::class)
            ->assertDontSee(route('backup-v2.fleet'), false);
    }
}
