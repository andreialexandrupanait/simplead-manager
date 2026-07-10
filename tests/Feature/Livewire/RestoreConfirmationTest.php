<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

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

    public function test_viewer_cannot_open_restore_modal(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)
            ->test(RestoreConfirmation::class, ['site' => $this->site])
            ->call('openModal', $this->backup->id)
            ->assertForbidden();
    }
}
