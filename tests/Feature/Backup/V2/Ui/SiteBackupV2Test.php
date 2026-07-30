<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Ui;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\SessionActions;
use App\Enums\UserRole;
use App\Livewire\Backup\V2\SiteBackupV2;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SiteBackupV2Test extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_renders_history_for_admin_when_enabled(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        $site = Site::factory()->create(['name' => 'Site One']);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Completed, 'type' => 'full']);

        Livewire::actingAs($this->admin())
            ->test(SiteBackupV2::class, ['site' => $site])
            ->assertSet('enabled', true)
            ->assertSee('New backup')
            ->assertSee('History')
            ->assertSee('completed');
    }

    public function test_start_backup_calls_session_actions(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        $site = Site::factory()->create();
        $returned = $this->makeBackupSession($site);

        $mock = Mockery::mock(SessionActions::class);
        $mock->shouldReceive('startBackup')
            ->once()
            ->with(Mockery::on(fn ($s) => $s->id === $site->id), 'full', Mockery::type('array'))
            ->andReturn($returned);
        $this->app->instance(SessionActions::class, $mock);

        Livewire::actingAs($this->admin())
            ->test(SiteBackupV2::class, ['site' => $site])
            ->call('startBackup', 'full')
            ->assertHasNoErrors();
    }

    public function test_pause_calls_session_actions(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['state' => BackupSessionState::Uploading]);

        $mock = Mockery::mock(SessionActions::class);
        $mock->shouldReceive('pause')
            ->once()
            ->with(Mockery::on(fn (BackupSession $s) => $s->id === $session->id));
        $this->app->instance(SessionActions::class, $mock);

        Livewire::actingAs($this->admin())
            ->test(SiteBackupV2::class, ['site' => $site])
            ->call('pauseSession', $session->id);
    }

    public function test_restore_calls_session_actions(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);
        $restore = $this->makeRestoreSession($site);

        $mock = Mockery::mock(SessionActions::class);
        $mock->shouldReceive('startRestore')->once()->andReturn($restore);
        $this->app->instance(SessionActions::class, $mock);

        Livewire::actingAs($this->admin())
            ->test(SiteBackupV2::class, ['site' => $site])
            ->call('restoreSession', $session->id, 'safe_merge');
    }

    public function test_gated_hidden_when_flag_disabled(): void
    {
        config(['backup_v2.ui_enabled' => false]);

        $site = Site::factory()->create();
        $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        Livewire::actingAs($this->admin())
            ->test(SiteBackupV2::class, ['site' => $site])
            ->assertSet('enabled', false)
            ->assertSee('not available')
            ->assertDontSee('New backup');
    }

    public function test_action_aborts_when_flag_disabled(): void
    {
        config(['backup_v2.ui_enabled' => false]);
        $this->withoutVite();

        $site = Site::factory()->create();

        Livewire::actingAs($this->admin())
            ->test(SiteBackupV2::class, ['site' => $site])
            ->call('startBackup', 'full')
            ->assertStatus(404);
    }
}
