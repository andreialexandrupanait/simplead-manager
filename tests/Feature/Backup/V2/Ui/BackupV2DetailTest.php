<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Ui;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Orchestration\SessionActions;
use App\Enums\UserRole;
use App\Livewire\Backup\V2\BackupV2Detail;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class BackupV2DetailTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_renders_session_facts_and_objects(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, [
            'state' => BackupSessionState::Completed,
            'stage' => 'backup complete',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'confirmed_objects' => [
                'files:0' => ['kind' => 'files', 'key' => 'clients/1/sites/1/backups/1/files/chunk_0.zip', 'size' => 2048, 'sha256' => str_repeat('a', 64)],
            ],
        ]);

        Livewire::actingAs($this->admin())
            ->test(BackupV2Detail::class, ['session' => $session])
            ->assertSet('enabled', true)
            ->assertSee('Backup session #'.$session->id)
            // The state is shown as a person would say it, not as the column stores it.
            ->assertSee(BackupSessionState::Completed->label())
            ->assertSee('Objects & checksums')
            ->assertSee('chunk_0.zip')
            ->assertSet('progressPercent', 100);
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
            ->test(BackupV2Detail::class, ['session' => $session])
            ->call('restore', 'safe_merge');
    }

    public function test_gated_hidden_when_flag_disabled(): void
    {
        config(['backup_v2.ui_enabled' => false]);

        $site = Site::factory()->create();
        $session = $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        Livewire::actingAs($this->admin())
            ->test(BackupV2Detail::class, ['session' => $session])
            ->assertSet('enabled', false)
            ->assertSee('not available')
            ->assertDontSee('Objects & checksums');
    }
}
