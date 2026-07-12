<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\NotifyBackupFailed;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P2-29: a transient backup failure must NOT notify the user on every attempt,
 * and a retry must reuse the same Backup row instead of inserting a duplicate.
 */
class CreateBackupRetryTest extends TestCase
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

    private function jobWithAttempts(int $attempts): CreateBackup
    {
        $job = new CreateBackup($this->site, 'full', 'scheduled', $this->destination->id);
        $fakeJob = $this->createMock(JobContract::class);
        $fakeJob->method('attempts')->willReturn($attempts);
        $job->setJob($fakeJob);

        return $job;
    }

    private function setBackup(CreateBackup $job, Backup $backup): void
    {
        (new \ReflectionProperty($job, 'backup'))->setValue($job, $backup);
        $job->backupId = $backup->id;
    }

    public function test_retry_reuses_existing_row_instead_of_creating_duplicate(): void
    {
        // Simulate the row a first (now-failed/retrying) attempt left behind.
        $existing = Backup::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'full',
            'trigger' => 'scheduled',
            'status' => BackupStatus::Pending,
            'started_at' => now()->subMinutes(2),
        ]);

        $job = $this->jobWithAttempts(2); // a retry
        $method = new \ReflectionMethod($job, 'prepare');
        $method->setAccessible(true);
        $method->invoke($job, $this->destination);

        $this->assertSame(1, Backup::where('site_id', $this->site->id)->count(), 'The retry must not create a duplicate Backup row.');
        $this->assertSame($existing->id, $job->backupId);
        $this->assertSame(BackupStatus::InProgress, $existing->fresh()->status);
    }

    public function test_transient_failure_does_not_notify_and_keeps_row_recoverable(): void
    {
        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'full',
            'trigger' => 'scheduled',
            'status' => BackupStatus::InProgress,
            'started_at' => now()->subMinute(),
        ]);

        $job = $this->jobWithAttempts(1);
        $this->setBackup($job, $backup);

        $method = new \ReflectionMethod($job, 'handleFailure');
        $method->setAccessible(true);
        $method->invoke($job, new \RuntimeException('temporary network blip'));

        Queue::assertNotPushed(NotifyBackupFailed::class);
        $fresh = $backup->fresh();
        $this->assertSame(BackupStatus::Pending, $fresh->status, 'The row must stay recoverable so the retry can reuse it.');
        $this->assertSame('temporary network blip', $fresh->error_message);
    }

    public function test_failed_notifies_once_and_marks_row_failed(): void
    {
        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'full',
            'trigger' => 'scheduled',
            'status' => BackupStatus::Pending,
            'stage' => 'retrying',
            'started_at' => now()->subMinutes(3),
        ]);

        // backupId is intentionally left null to exercise resolveFailedBackup().
        $job = new CreateBackup($this->site, 'full', 'scheduled', $this->destination->id);

        $job->failed(new \RuntimeException('permanent failure'));

        // The single notification is emitted once by BackupObserver on the
        // status → Failed transition; the job must not dispatch it again.
        Queue::assertPushed(NotifyBackupFailed::class, 1);
        $this->assertSame(BackupStatus::Failed, $backup->fresh()->status);
        $this->assertFalse((bool) $this->site->fresh()->backup_ok);
    }

    public function test_first_dispatch_with_fresh_inflight_row_is_dropped_as_duplicate(): void
    {
        // Not a retry (attempts = 1): a fresh in-flight row means a real
        // concurrent duplicate dispatch, which must be dropped, not adopted.
        $existing = Backup::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'full',
            'trigger' => 'scheduled',
            'status' => BackupStatus::InProgress,
            'started_at' => now()->subMinute(),
        ]);
        Backup::whereKey($existing->id)->update(['updated_at' => now()->subSeconds(30)]);

        $job = $this->jobWithAttempts(1);
        $method = new \ReflectionMethod($job, 'prepare');
        $method->setAccessible(true);
        $method->invoke($job, $this->destination);

        $aborted = new \ReflectionProperty($job, 'abortedAsDuplicate');
        $aborted->setAccessible(true);
        $this->assertTrue((bool) $aborted->getValue($job));
        $this->assertSame(1, Backup::where('site_id', $this->site->id)->count());
    }
}
