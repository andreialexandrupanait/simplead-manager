<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Ui;

use App\Backup\V2\Enums\BackupSessionState;
use App\Enums\UserRole;
use App\Livewire\Backup\V2\BackupV2Overview;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupV2OverviewTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_renders_stats_and_sessions_for_admin_when_enabled(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        $site = Site::factory()->create(['name' => 'Acme Corp']);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Uploading, 'stage' => 'uploading file chunks']);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Failed]);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);
        StorageDestination::factory()->create(['name' => 'Primary S3', 'used_bytes' => 5_000_000, 'quota_bytes' => 10_000_000]);

        Livewire::actingAs($this->admin())
            ->test(BackupV2Overview::class)
            ->assertSet('enabled', true)
            ->assertSee('Backup V2')
            ->assertSee('Active sessions')
            ->assertSee('Acme Corp')
            ->assertSee('Primary S3');
    }

    public function test_stats_counts_are_correct(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        $site = Site::factory()->create();
        $this->makeBackupSession($site, ['state' => BackupSessionState::Uploading]);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Failed]);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Corrupt]);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Paused]);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        $component = Livewire::actingAs($this->admin())->test(BackupV2Overview::class);
        $stats = $component->get('stats');

        // Active = non-terminal: uploading + paused = 2.
        $this->assertSame(2, $stats['active']);
        $this->assertSame(2, $stats['failed']); // failed + corrupt
        $this->assertSame(1, $stats['paused']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['incomplete']); // corrupt
    }

    public function test_gated_hidden_when_flag_disabled(): void
    {
        config(['backup_v2.ui_enabled' => false]);

        $site = Site::factory()->create(['name' => 'Hidden Site']);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Uploading]);

        Livewire::actingAs($this->admin())
            ->test(BackupV2Overview::class)
            ->assertSet('enabled', false)
            ->assertSee('not available')
            ->assertDontSee('Active sessions')
            ->assertDontSee('Hidden Site');
    }

    public function test_hidden_for_non_admin_even_when_flag_on(): void
    {
        config(['backup_v2.ui_enabled' => true]);

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['name' => 'Manager Site', 'user_id' => $manager->id]);
        $this->makeBackupSession($site, ['state' => BackupSessionState::Uploading]);

        Livewire::actingAs($manager)
            ->test(BackupV2Overview::class)
            ->assertSet('enabled', false)
            ->assertDontSee('Active sessions');
    }
}
