<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Enums\BackupEngine;
use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupSidecarMetadata;
use App\Services\Backup\BackupVerifier;
use App\Services\Backup\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * The V1 paths that reach for a `backups` row must honour `engine` — before any
 * row is ever written with engine=v2.
 *
 * The new engine is going to materialise a row in `backups` for every session,
 * so the interface, the observer, the alerts, the health score and chain
 * retention all work over it unchanged. That row is not a display artifact. It
 * is a handle several V1 code paths grab, and on a V2 row `file_path` names a
 * prefix holding a tree of objects rather than an archive.
 *
 * The dangerous one is deletion. Retention's single-file branch would call
 * DeleteObject on a prefix; S3 answers 204 for a key that does not exist, so it
 * reads as success — the row is dropped and every object under that prefix is
 * orphaned forever, with nothing logged. Both halves are tested here: V2 rows
 * take the recursive branch, and V1 rows still take exactly the path they took
 * before, because a mistake in that direction destroys real client backups.
 */
class EngineAwarePathsTest extends TestCase
{
    use RefreshDatabase;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();
        $this->storageDir = sys_get_temp_dir().'/engine-aware-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->storageDir);
        }
        parent::tearDown();
    }

    private function destination(): StorageDestination
    {
        return StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->storageDir],
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function write(string $remotePath, string $contents = 'bytes'): void
    {
        $full = $this->storageDir.'/'.$remotePath;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, $contents);
    }

    // ── deletion ─────────────────────────────────────────────────────────

    public function test_deleting_a_v2_backup_removes_every_object_under_its_prefix(): void
    {
        $destination = $this->destination();
        $site = Site::factory()->create();
        $prefix = 'clients/7/sites/'.$site->id.'/backups/42';

        // A real V2 layout: chunks live in subdirectories, sidecars at the root.
        $this->write($prefix.'/files/chunk_0.zip');
        $this->write($prefix.'/files/chunk_1.zip');
        $this->write($prefix.'/database/chunk_0.sql.gz');
        $this->write($prefix.'/manifest.json');
        $this->write($prefix.'/checksums.json');
        $this->write($prefix.'/metadata.json');
        $this->write($prefix.'/_COMPLETE');

        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'engine' => BackupEngine::V2,
            'file_path' => $prefix,
            'status' => BackupStatus::Completed,
            'file_size' => 1024,
        ]);

        app(RetentionService::class)->purge($backup);

        // The chunks are the point. A non-recursive list would delete the four
        // sidecars, report success, and leave every chunk behind — paid for,
        // forever, with nothing pointing at it.
        $this->assertFileDoesNotExist($this->storageDir.'/'.$prefix.'/files/chunk_0.zip');
        $this->assertFileDoesNotExist($this->storageDir.'/'.$prefix.'/files/chunk_1.zip');
        $this->assertFileDoesNotExist($this->storageDir.'/'.$prefix.'/database/chunk_0.sql.gz');
        $this->assertFileDoesNotExist($this->storageDir.'/'.$prefix.'/manifest.json');
        $this->assertFileDoesNotExist($this->storageDir.'/'.$prefix.'/_COMPLETE');
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    }

    /**
     * The regression that would cost real data: a legacy row must keep taking
     * the single-file-plus-sidecar path it took before the branch existed.
     */
    public function test_deleting_a_v1_backup_still_takes_the_single_archive_path(): void
    {
        $destination = $this->destination();
        $site = Site::factory()->create();
        $path = 'backups/site-'.$site->id.'/backup.zip';

        $this->write($path);
        $this->write($path.BackupSidecarMetadata::SUFFIX, '{"meta":true}');
        // A neighbouring backup in the same directory. If the V1 delete ever fell
        // through to the recursive branch it would take this with it.
        $this->write('backups/site-'.$site->id.'/other-backup.zip');

        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'engine' => BackupEngine::V1,
            'format' => 'v3-zip',
            'file_path' => $path,
            'status' => BackupStatus::Completed,
            'file_size' => 1024,
        ]);

        app(RetentionService::class)->purge($backup);

        $this->assertFileDoesNotExist($this->storageDir.'/'.$path);
        $this->assertFileDoesNotExist($this->storageDir.'/'.$path.BackupSidecarMetadata::SUFFIX);
        $this->assertFileExists(
            $this->storageDir.'/backups/site-'.$site->id.'/other-backup.zip',
            'a neighbouring backup must survive — deleting one must never sweep its directory',
        );
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    }

    // ── stuck recovery ───────────────────────────────────────────────────

    public function test_recovery_ignores_v2_rows(): void
    {
        $site = Site::factory()->create();
        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'engine' => BackupEngine::V2,
            'status' => BackupStatus::InProgress,
            'auto_retry_count' => 0,
            'started_at' => now()->subHours(3),
            'updated_at' => now()->subHours(2),
        ]);

        $dispatcher = new \App\Dispatchers\BackupDispatcher;
        $method = new \ReflectionMethod($dispatcher, 'recoverStuckBackups');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        // The whole point: recovery "recovers" by dispatching the old engine. A
        // V2 session legitimately runs for far longer than 20 minutes without
        // touching this row, so recovering it means two engines writing to the
        // same client site at once.
        Queue::assertNotPushed(CreateBackup::class);
        $this->assertSame(BackupStatus::InProgress, $backup->fresh()->status);
    }

    public function test_recovery_still_retries_v1_rows(): void
    {
        $site = Site::factory()->create();
        Backup::factory()->create([
            'site_id' => $site->id,
            'engine' => BackupEngine::V1,
            'type' => 'full',
            'status' => BackupStatus::InProgress,
            'auto_retry_count' => 0,
            'started_at' => now()->subHours(3),
            'updated_at' => now()->subHours(2),
        ]);

        $dispatcher = new \App\Dispatchers\BackupDispatcher;
        $method = new \ReflectionMethod($dispatcher, 'recoverStuckBackups');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        Queue::assertPushed(CreateBackup::class);
    }

    // ── verification ─────────────────────────────────────────────────────

    /**
     * The weekly sweep runs unattended, so this one bites with nobody clicking
     * anything: it would stamp verification_status='failed' on a backup verified
     * byte for byte, and BackupHealthService docks 30 points for that.
     */
    public function test_the_legacy_verifier_does_not_judge_a_v2_backup(): void
    {
        $site = Site::factory()->create();
        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'engine' => BackupEngine::V2,
            'file_path' => 'clients/7/sites/'.$site->id.'/backups/42',
            'status' => BackupStatus::Completed,
            'verification_status' => 'passed',
        ]);

        $result = app(BackupVerifier::class)->verify($backup);

        $this->assertTrue($result['ok']);
        $this->assertSame('passed', $backup->fresh()->verification_status);
    }

    public function test_the_weekly_sweep_does_not_sample_v2_backups(): void
    {
        $destination = $this->destination();
        $site = Site::factory()->create();

        Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'engine' => BackupEngine::V2,
            'file_path' => 'clients/7/sites/'.$site->id.'/backups/42',
            'status' => BackupStatus::Completed,
            'created_at' => now()->subDay(),
            'verified_at' => null,
        ]);

        $this->artisan('backup:verify-restore')
            ->expectsOutputToContain('No candidate backups to verify.')
            ->assertSuccessful();
    }

    // ── restore ──────────────────────────────────────────────────────────

    public function test_the_legacy_restore_refuses_a_v2_backup_by_name(): void
    {
        $site = Site::factory()->create();
        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'engine' => BackupEngine::V2,
            'file_path' => 'clients/7/sites/'.$site->id.'/backups/42',
            'status' => BackupStatus::Completed,
        ]);

        $job = new \App\Jobs\RestoreBackup($backup);
        $method = new \ReflectionMethod($job, 'restoreSingleBackup');
        $method->setAccessible(true);

        // Without the guard this falls through to the legacy archive branch and
        // dies on `tempDir/null` — a sound backup presented as a broken restore.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be restored by the legacy restore path');
        $method->invoke($job);
    }
}
