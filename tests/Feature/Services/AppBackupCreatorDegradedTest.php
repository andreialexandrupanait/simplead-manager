<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\StorageDestination;
use App\Services\AppBackup\AppBackupCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Covers P0-26: a self-backup with no working remote destination must be
 * reported DEGRADED (local-only) with a critical alert — never a clean
 * "completed" success. Uses the 'config' backup type (env-only) so the test
 * exercises the upload/degraded branch without invoking pg_dump.
 */
class AppBackupCreatorDegradedTest extends TestCase
{
    use RefreshDatabase;

    private string $remoteDir;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        NotificationChannel::factory()->default()->create();

        $this->remoteDir = sys_get_temp_dir().'/appbackup-test-'.uniqid();
        mkdir($this->remoteDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->remoteDir)) {
            exec('rm -rf '.escapeshellarg($this->remoteDir));
        }
        // Remove any local fallback artifacts created by a degraded backup.
        foreach (glob(storage_path('app/backups/application/simplead-backup-config-*')) ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_local_only_backup_is_marked_degraded_and_alerts_critically(): void
    {
        $this->assertSame(0, StorageDestination::count());

        $backup = (new AppBackupCreator)->create('config', 'scheduled');

        $this->assertSame('degraded', $backup->fresh()->status, 'No remote destination → DEGRADED, not completed.');

        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->event === 'app_backup_degraded' && $job->severity === 'critical',
        );
    }

    public function test_backup_to_verified_remote_destination_is_completed(): void
    {
        $destination = StorageDestination::create([
            'name' => 'Test Local',
            'type' => 'local',
            'config' => ['path' => $this->remoteDir],
            'is_default' => true,
            'is_active' => true,
            'used_bytes' => 0,
        ]);

        $backup = (new AppBackupCreator)->create('config', 'scheduled', $destination->id);

        $this->assertSame('completed', $backup->fresh()->status);
        $this->assertDatabaseHas('app_backups', ['id' => $backup->id, 'status' => 'completed']);
        // The archive actually landed off-host (in the destination's base path).
        $this->assertNotEmpty(glob($this->remoteDir.'/application-backups/*.tar.gz'));
    }
}
