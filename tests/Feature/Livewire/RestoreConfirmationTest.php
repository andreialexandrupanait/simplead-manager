<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Jobs\RunRestoreSessionJob;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Enums\BackupEngine;
use App\Enums\BackupStatus;
use App\Enums\UserRole;
use App\Livewire\Sites\Detail\Components\RestoreConfirmation;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

class RestoreConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private Backup $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->site = Site::factory()->create(['user_id' => $this->admin->id]);
        StorageDestination::factory()->create(['is_default' => true, 'is_active' => true]);
        $this->backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'status' => BackupStatus::Completed,
            'includes_database' => true,
            'includes_files' => true,
        ]);
    }

    protected function restoreComponent()
    {
        return Livewire::actingAs($this->admin)
            ->test(RestoreConfirmation::class, ['site' => $this->site])
            ->call('openModal', $this->backup->id);
    }

    /**
     * The safety copy moved inside the engine.
     *
     * This screen used to take one itself and then wait for it: the modal called
     * CreateBackup, polled the row, and only dispatched the restore on the second
     * pass. That produced a duplicate full backup in the retired engine's format,
     * and — because it was taken outside the site's operation lock — it could race
     * the very thing it protected against. PreRestoreSafetyBackup now runs inside
     * the restore session, under that lock, in the format the restore reads.
     */
    /**
     * The safety copy moved inside the engine.
     *
     * This screen used to take one itself and then wait for it: it called
     * CreateBackup, polled the row, and dispatched the restore only on a second
     * pass. That produced a duplicate full backup in the retired engine's format
     * and — being taken outside the site's operation lock — could race the very
     * thing it protected against. PreRestoreSafetyBackup now runs inside the
     * restore session, under that lock, in the format the restore reads.
     */
    public function test_the_restore_starts_without_this_screen_taking_its_own_backup(): void
    {
        [$backup, $session] = $this->v2RestorePoint();

        Livewire::actingAs($this->admin)
            ->test(RestoreConfirmation::class, ['site' => $this->site])
            ->call('openModal', $backup->id)
            ->set('confirmed', true)
            ->call('restore');

        Queue::assertPushed(RunRestoreSessionJob::class);
        $this->assertDatabaseHas('restore_sessions', [
            'site_id' => $this->site->id,
            'backup_session_id' => $session->id,
        ]);

        // No second full backup taken by this screen on the way.
        $this->assertDatabaseMissing('backups', ['trigger' => 'pre_restore']);
    }

    /**
     * An archive from the retired engine has no session behind it. Refusing is
     * the honest answer — and the message says what can still be done with the
     * file, because the file itself is perfectly intact.
     */
    public function test_an_archive_from_the_retired_engine_is_refused_by_name(): void
    {
        $this->restoreComponent()
            ->set('confirmed', true)
            ->call('restore')
            ->assertDispatched('close-modal-restore-confirmation');

        $this->assertDatabaseCount('restore_sessions', 0);
        Queue::assertNotPushed(RunRestoreSessionJob::class);
    }

    public function test_a_selective_v2_restore_carries_the_chosen_paths(): void
    {
        [$backup] = $this->v2RestorePoint();

        Livewire::actingAs($this->admin)
            ->test(RestoreConfirmation::class, ['site' => $this->site])
            ->call('openModal', $backup->id)
            ->set('confirmed', true)
            ->set('restoreMode', 'selective')
            ->set('restoreDatabase', false)
            ->set('restoreFiles', true)
            ->set('selectedFiles', ['wp-content/uploads/2026'])
            ->call('restore');

        $scope = RestoreSession::latest('id')->first()?->scope;

        $this->assertSame(false, $scope['database'] ?? null);
        $this->assertSame(['wp-content/uploads/2026'], $scope['paths'] ?? null);
    }

    /**
     * @return array{0: Backup, 1: BackupSession}
     */
    private function v2RestorePoint(): array
    {
        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'status' => BackupStatus::Completed,
            'engine' => BackupEngine::V2,
            'includes_database' => true,
            'includes_files' => true,
        ]);

        $session = BackupSession::create([
            'site_id' => $this->site->id,
            'backup_id' => $backup->id,
            'type' => 'full',
            'scope' => ['database' => true, 'files' => true],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Completed,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'ui-'.uniqid('', true),
            'format_version' => 'simplead-backup/2',
        ]);

        return [$backup, $session];
    }

    public function test_viewer_cannot_open_restore_modal(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)
            ->test(RestoreConfirmation::class, ['site' => $this->site])
            ->call('openModal', $this->backup->id)
            ->assertForbidden();
    }
}
