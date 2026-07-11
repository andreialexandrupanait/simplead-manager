<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationChannel;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DatabaseDumpOffsiteCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dumpDir;

    private string $dumpFile;

    private string $remoteDir;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        NotificationChannel::factory()->default()->create();

        $this->dumpDir = storage_path('app/db-dumps');
        if (! is_dir($this->dumpDir)) {
            mkdir($this->dumpDir, 0755, true);
        }

        // A clearly-newest dump so latestDump() picks ours regardless of leftovers.
        $this->dumpFile = $this->dumpDir.'/db_dump_2999-01-01_000000.sql.gz';
        file_put_contents($this->dumpFile, 'fake-dump-contents');

        $this->remoteDir = sys_get_temp_dir().'/offsite-test-'.uniqid();
        mkdir($this->remoteDir, 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dumpFile);
        if (is_dir($this->remoteDir)) {
            exec('rm -rf '.escapeshellarg($this->remoteDir));
        }
        parent::tearDown();
    }

    public function test_no_destination_reports_degraded_and_fires_critical_notification(): void
    {
        $this->assertSame(0, StorageDestination::count());

        $this->artisan('db:dump-offsite')->assertFailed();

        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->event === 'db_dump_offsite_failed' && $job->severity === 'critical',
        );
    }

    public function test_dump_is_pushed_off_host_to_active_destination(): void
    {
        $destination = StorageDestination::create([
            'name' => 'Test Local',
            'type' => 'local',
            'config' => ['path' => $this->remoteDir],
            'is_default' => true,
            'is_active' => true,
            'used_bytes' => 0,
        ]);

        $this->artisan('db:dump-offsite')->assertSuccessful();

        $this->assertFileExists($this->remoteDir.'/database-dumps/db_dump_2999-01-01_000000.sql.gz');
        $this->assertGreaterThan(0, $destination->fresh()->used_bytes, 'used_bytes should track the off-site copy.');
        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_missing_dump_does_not_fire_offsite_alert(): void
    {
        @unlink($this->dumpFile);

        StorageDestination::create([
            'name' => 'Test Local',
            'type' => 'local',
            'config' => ['path' => $this->remoteDir],
            'is_default' => true,
            'is_active' => true,
            'used_bytes' => 0,
        ]);

        // Recreate the file only if some other leftover dump exists; otherwise
        // the command should fail without raising a false off-site alert.
        if ($this->onlyOurDumpMissing()) {
            $this->artisan('db:dump-offsite')->assertFailed();
            Queue::assertNotPushed(SendNotificationJob::class);
        } else {
            $this->markTestSkipped('Other db_dump_* files present in the dump dir.');
        }
    }

    private function onlyOurDumpMissing(): bool
    {
        $files = array_merge(
            glob($this->dumpDir.'/db_dump_*.sql.gz') ?: [],
            glob($this->dumpDir.'/db_dump_*.sql.gz.enc') ?: [],
        );

        return $files === [];
    }
}
