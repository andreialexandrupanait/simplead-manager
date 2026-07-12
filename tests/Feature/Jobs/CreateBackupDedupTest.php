<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P1-27: the in-flight dedup heuristic in CreateBackup::prepare() used to treat
 * an existing backup as "stuck" (and supersede it with a fresh one → duplicate
 * backup) once it was >30 min old OR had gone >5 min without a heartbeat. A
 * large-site upload legitimately runs for tens of minutes and can go several
 * minutes between heartbeat writes, so healthy long backups were duplicated.
 *
 * The fix keys the decision purely off the heartbeat (updated_at) against a
 * threshold ABOVE the job timeout: within the window → live duplicate to drop;
 * beyond it → genuinely dead (the worker would have been killed at $timeout).
 */
class CreateBackupDedupTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private StorageDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();
        $this->site = Site::factory()->create();
        $this->destination = StorageDestination::factory()->create(['type' => 'local']);
    }

    private function runPrepare(): CreateBackup
    {
        $job = new CreateBackup($this->site, 'full', 'manual', $this->destination->id);
        $method = new \ReflectionMethod($job, 'prepare');
        $method->setAccessible(true);
        $method->invoke($job, $this->destination);

        return $job;
    }

    private function abortedAsDuplicate(CreateBackup $job): bool
    {
        $prop = new \ReflectionProperty($job, 'abortedAsDuplicate');
        $prop->setAccessible(true);

        return (bool) $prop->getValue($job);
    }

    private function makeExisting(int $startedMinutesAgo, int $heartbeatMinutesAgo): Backup
    {
        $existing = Backup::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'full',
            'trigger' => 'manual',
            'status' => BackupStatus::InProgress,
            'started_at' => now()->subMinutes($startedMinutesAgo),
        ]);

        // Mass update leaves timestamps untouched, so we can back-date the heartbeat.
        Backup::whereKey($existing->id)->update(['updated_at' => now()->subMinutes($heartbeatMinutesAgo)]);

        return $existing->fresh();
    }

    public function test_long_running_backup_with_fresh_heartbeat_is_not_superseded(): void
    {
        // Started 40 min ago (would have failed the old 30-min gate) but the
        // heartbeat is recent — a healthy long upload, not a stuck row.
        $existing = $this->makeExisting(startedMinutesAgo: 40, heartbeatMinutesAgo: 3);

        $job = $this->runPrepare();

        $this->assertTrue($this->abortedAsDuplicate($job), 'The duplicate dispatch should be dropped, not superseded.');
        $this->assertSame(BackupStatus::InProgress, $existing->fresh()->status, 'The live backup must be left running.');
        $this->assertSame(1, Backup::where('site_id', $this->site->id)->count(), 'No duplicate backup row should be created.');
    }

    public function test_dead_backup_past_heartbeat_threshold_is_superseded(): void
    {
        // Heartbeat older than the job timeout → the worker is dead.
        $existing = $this->makeExisting(startedMinutesAgo: 60, heartbeatMinutesAgo: 55);

        $job = $this->runPrepare();

        $this->assertFalse($this->abortedAsDuplicate($job));
        $this->assertSame(BackupStatus::Failed, $existing->fresh()->status, 'The orphaned row should be superseded.');
        $this->assertSame(2, Backup::where('site_id', $this->site->id)->count(), 'A fresh backup row should replace the dead one.');
    }

    public function test_redelivered_completed_row_is_not_rerun(): void
    {
        $completed = Backup::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'full',
            'trigger' => 'manual',
            'status' => BackupStatus::Completed,
            'started_at' => now()->subMinutes(5),
        ]);

        $job = new CreateBackup($this->site, 'full', 'manual', $this->destination->id, $completed->id);
        $method = new \ReflectionMethod($job, 'prepare');
        $method->setAccessible(true);
        $method->invoke($job, $this->destination);

        $this->assertTrue($this->abortedAsDuplicate($job), 'A redelivered completed backup must not re-run.');
        $this->assertSame(BackupStatus::Completed, $completed->fresh()->status);
    }
}
