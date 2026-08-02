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
use App\Jobs\CreateBackup;
use App\Jobs\RestoreBackup;
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

    public function test_full_restore_creates_a_full_safety_backup_not_db_only(): void
    {
        $this->restoreComponent()
            ->set('confirmed', true)
            ->call('restore');

        $safety = Backup::where('trigger', 'pre_restore')->latest('id')->first();

        $this->assertNotNull($safety);
        $this->assertSame('full', $safety->type);
        $this->assertTrue((bool) $safety->includes_files);
        Queue::assertPushed(CreateBackup::class, fn ($job) => $job->type === 'full');
        // The restore itself must NOT be dispatched yet.
        Queue::assertNotPushed(RestoreBackup::class);
    }

    public function test_db_only_selective_restore_gets_db_safety_backup(): void
    {
        $this->restoreComponent()
            ->set('confirmed', true)
            ->set('restoreMode', 'selective')
            ->set('restoreDatabase', true)
            ->set('restoreFiles', false)
            ->call('restore');

        $safety = Backup::where('trigger', 'pre_restore')->latest('id')->first();

        $this->assertSame('database', $safety->type);
        $this->assertFalse((bool) $safety->includes_files);
    }

    public function test_safety_backup_cannot_be_skipped_by_unchecking_the_old_toggle(): void
    {
        $this->restoreComponent()
            ->set('confirmed', true)
            ->set('backupBeforeRestore', false)
            ->call('restore');

        // Even with the legacy flag off, a safety backup is created first.
        $this->assertDatabaseHas('backups', ['trigger' => 'pre_restore']);
        Queue::assertNotPushed(RestoreBackup::class);
    }

    public function test_restore_anyway_is_forbidden_unless_safety_backup_failed(): void
    {
        $this->restoreComponent()
            ->set('confirmed', true)
            ->set('confirmDangerText', $this->site->domain)
            ->call('restoreAnyway')
            ->assertForbidden();

        Queue::assertNotPushed(RestoreBackup::class);
    }

    public function test_restore_anyway_requires_typed_domain(): void
    {
        $this->restoreComponent()
            ->set('confirmed', true)
            ->set('preRestoreBackupId', 123)
            ->set('preRestoreStatus', 'failed')
            ->set('confirmDangerText', 'wrong-domain.com')
            ->call('restoreAnyway')
            ->assertHasErrors('confirmDangerText');

        Queue::assertNotPushed(RestoreBackup::class);
    }

    public function test_restore_anyway_with_typed_domain_dispatches_flagged_restore(): void
    {
        $this->restoreComponent()
            ->set('confirmed', true)
            ->set('preRestoreBackupId', 123)
            ->set('preRestoreStatus', 'failed')
            ->set('confirmDangerText', $this->site->domain)
            ->call('restoreAnyway');

        Queue::assertPushed(RestoreBackup::class, fn ($job) => $job->safetyBackupSkipped === true);
        $this->assertDatabaseHas('activity_logs', [
            'site_id' => $this->site->id,
            'severity' => 'critical',
        ]);
    }

    public function test_failed_safety_backup_blocks_the_normal_restore_path(): void
    {
        $this->restoreComponent()
            ->set('confirmed', true)
            ->set('preRestoreBackupId', 123)
            ->set('preRestoreStatus', 'failed')
            ->call('restore');

        Queue::assertNotPushed(RestoreBackup::class);
    }

    /**
     * The backup buttons on this page route by engine; this modal did not, and dispatched the V1
     * job for every restore point on the site. Both engines write rows into `backups`, so the day a
     * site moves to V2 its restore points appear in this very list — and "Restore" would have
     * pointed the old engine at storage written by the new one, on a live site.
     */
    public function test_a_v2_restore_point_never_goes_to_the_v1_job(): void
    {
        [$backup, $session] = $this->v2RestorePoint();

        Livewire::actingAs($this->admin)
            ->test(RestoreConfirmation::class, ['site' => $this->site])
            ->call('openModal', $backup->id)
            ->set('confirmed', true)
            ->call('restore');

        Queue::assertNotPushed(RestoreBackup::class);
        Queue::assertPushed(RunRestoreSessionJob::class);

        $this->assertDatabaseHas('restore_sessions', [
            'site_id' => $this->site->id,
            'backup_session_id' => $session->id,
        ]);
    }

    public function test_a_v2_restore_does_not_take_a_v1_safety_backup_first(): void
    {
        // The V2 runner takes its own safety copy, through the engine it is about to restore with.
        // Running the old one first would leave a V1-format net under a site that no longer uses
        // that engine — and cost the site a duplicate full backup on the way.
        [$backup] = $this->v2RestorePoint();

        Livewire::actingAs($this->admin)
            ->test(RestoreConfirmation::class, ['site' => $this->site])
            ->call('openModal', $backup->id)
            ->set('confirmed', true)
            ->call('restore');

        Queue::assertNotPushed(CreateBackup::class);
    }

    public function test_a_v1_restore_point_still_takes_the_old_path(): void
    {
        // A migrated site keeps its old restore points, and each one has to go back through the
        // engine that produced it.
        $this->restoreComponent()
            ->set('confirmed', true)
            ->call('restore');

        Queue::assertPushed(CreateBackup::class);
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
