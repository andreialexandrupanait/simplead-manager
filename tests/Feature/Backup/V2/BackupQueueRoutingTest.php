<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Jobs\RunBackupSessionJob;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\SessionActions;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Backups run on the queue that was tuned for them.
 *
 * The V2 job declared no queue at all, so every backup landed on `default` — served by Horizon's
 * general supervisor: three workers at 512 MB, shared with reports, security scans and performance
 * checks. The supervisor built for this work, with a gigabyte per worker and an hour of timeout
 * because backups are the memory hog, sat empty from the day the engine shipped.
 *
 * Harmless while one site was on V2. With the whole fleet moved and twenty-four sites due at 03:00,
 * a night of backups would have held every general worker for hours with everything else behind it.
 */
#[Group('backup-v2')]
class BackupQueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_backup_job_goes_to_the_backups_queue(): void
    {
        $this->assertSame('backups', (new RunBackupSessionJob(1))->queue);
    }

    public function test_a_scheduled_backup_is_queued_there_too(): void
    {
        Queue::fake();

        $site = Site::factory()->create();
        $destination = StorageDestination::factory()->create([
            'type' => 'hetzner_objectstorage',
            'is_active' => true,
            'is_default' => true,
        ]);
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
        ]);

        app(SessionActions::class)->startBackup($site->fresh(), 'full', ['trigger' => 'scheduled']);

        Queue::assertPushed(
            RunBackupSessionJob::class,
            fn (RunBackupSessionJob $job): bool => $job->queue === 'backups',
        );

        $this->assertSame(
            BackupSessionState::Requested,
            BackupSession::where('site_id', $site->id)->latest('id')->first()?->state,
        );
    }
}
