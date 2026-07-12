<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Enums\BackupStatus;
use App\Exceptions\BackupException;
use App\Jobs\CreateBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P2-32: the direct-upload prepare poll must release the job back to the queue
 * between polls (freeing the worker) instead of sleeping in-worker for up to an
 * hour, and it must give up once the wall-clock deadline passes.
 */
class CreateBackupPollTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Backup $backup;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        config(['backups.prepare_poll.max_wait_seconds' => 3600]);
        config(['backups.prepare_poll.release_delay_seconds' => 15]);

        $this->site = Site::factory()->create();
        $destination = StorageDestination::factory()->create(['type' => 'local']);
        $this->backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::InProgress,
        ]);
    }

    private function makeJob(JobContract $fakeJob): CreateBackup
    {
        $job = new CreateBackup($this->site, 'full', 'scheduled');
        $job->backupId = $this->backup->id;
        (new \ReflectionProperty($job, 'backup'))->setValue($job, $this->backup);
        $job->setJob($fakeJob);

        return $job;
    }

    private function invokePoll(CreateBackup $job, WordPressApiServiceInterface $api, string $token): array
    {
        $method = new \ReflectionMethod($job, 'pollPrepareStatus');
        $method->setAccessible(true);

        return $method->invoke($job, $api, $token);
    }

    private function response(array $body): Response
    {
        return new Response(new GuzzleResponse(200, [], json_encode($body)));
    }

    public function test_working_status_releases_job_with_delay_instead_of_sleeping(): void
    {
        $fakeJob = $this->createMock(JobContract::class);
        $fakeJob->method('attempts')->willReturn(2);
        $fakeJob->expects($this->once())->method('release')->with(15);
        $job = $this->makeJob($fakeJob);

        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->expects($this->once())->method('request')->willReturn(
            $this->response(['status' => 'working', 'progress' => 40, 'message' => 'Archiving'])
        );

        $result = $this->invokePoll($job, $api, 'tok-abc');

        $this->assertSame([], $result);
        $deferred = new \ReflectionProperty($job, 'deferredForPolling');
        $deferred->setAccessible(true);
        $this->assertTrue((bool) $deferred->getValue($job));
    }

    public function test_gives_up_after_deadline(): void
    {
        // Pre-seed an already-expired deadline for this token.
        Cache::put("backup-prepare-deadline:{$this->site->id}:tok-late", now()->subMinute()->getTimestamp(), 600);

        $fakeJob = $this->createMock(JobContract::class);
        $fakeJob->method('attempts')->willReturn(3);
        $fakeJob->expects($this->never())->method('release');
        $job = $this->makeJob($fakeJob);

        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->expects($this->never())->method('request');

        $this->expectException(BackupException::class);
        try {
            $this->invokePoll($job, $api, 'tok-late');
        } finally {
            $this->assertNull(Cache::get("backup-prepare-deadline:{$this->site->id}:tok-late"));
        }
    }

    public function test_done_status_returns_payload(): void
    {
        $fakeJob = $this->createMock(JobContract::class);
        $fakeJob->method('attempts')->willReturn(2);
        $fakeJob->expects($this->never())->method('release');
        $job = $this->makeJob($fakeJob);

        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->expects($this->once())->method('request')->willReturn(
            $this->response(['status' => 'done', 'size' => 1024, 'checksum' => 'abc123', 'progress' => 100])
        );

        $result = $this->invokePoll($job, $api, 'tok-ok');

        $this->assertSame('done', $result['status']);
        $this->assertSame(1024, $result['size']);
    }
}
