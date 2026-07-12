<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\BackupDispatcher;
use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Models\Backup;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P2-31: the Pending stuck-backup threshold must scale with the dispatch
 * stagger. With a fixed 45-min threshold, anything past ~15 staggered sites was
 * flagged "stale" and spuriously auto-retried before it had even started.
 */
class BackupDispatcherStaleThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        config(['backups.stagger_interval_seconds' => 180]);
        config(['backups.pending_stale_minutes' => 45]);
    }

    private function recover(): void
    {
        $dispatcher = new BackupDispatcher;
        $method = new \ReflectionMethod($dispatcher, 'recoverStuckBackups');
        $method->setAccessible(true);
        $method->invoke($dispatcher);
    }

    private function makePending(int $ageMinutes): Backup
    {
        $site = Site::factory()->create();

        return Backup::factory()->create([
            'site_id' => $site->id,
            'status' => BackupStatus::Pending,
            'auto_retry_count' => 0,
            'started_at' => now()->subMinutes($ageMinutes),
        ]);
    }

    public function test_late_staggered_pending_backup_is_not_flagged_stale(): void
    {
        // 20 sites queued together. The 20th is not expected to start until
        // 19 × 3 = 57 min after dispatch — well past the old fixed 45-min gate.
        // With the stagger allowance (20 × 3 = 60 min) the threshold is 105 min,
        // so a 57-min-old pending backup is correctly left alone.
        $backups = collect(range(1, 20))->map(fn () => $this->makePending(57));

        $this->recover();

        foreach ($backups as $backup) {
            $this->assertDatabaseHas('backups', [
                'id' => $backup->id,
                'status' => BackupStatus::Pending->value,
                'auto_retry_count' => 0,
            ]);
        }
        // Site::factory() itself queues FetchSiteFavicon, so assert specifically
        // that no backup was auto-retried rather than that nothing was queued.
        Queue::assertNotPushed(CreateBackup::class);
    }

    public function test_genuinely_stuck_pending_backup_is_recovered(): void
    {
        // A single pending backup (allowance = 3 min → threshold 48 min) that has
        // sat for 200 min really is stuck and should be auto-retried.
        $backup = $this->makePending(200);

        $this->recover();

        $this->assertDatabaseHas('backups', [
            'id' => $backup->id,
            'auto_retry_count' => 1,
        ]);
    }
}
