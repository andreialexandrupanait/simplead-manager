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
     * The data-loss this format made possible, and the guard against it.
     *
     * Since format/2 a backup's manifest names the object holding every file at that restore point,
     * including files it never uploaded — those still live in an older backup's prefix. Wholesale
     * prefix deletion was correct while every backup only referenced its own objects. Now it would
     * quietly gut restore points that are still listed, still verified, and still expected to work,
     * and nobody would find out until they needed one.
     */
    public function test_deleting_a_backup_keeps_the_objects_a_newer_restore_point_still_references(): void
    {
        $destination = $this->destination();
        $site = Site::factory()->create();

        $oldPrefix = 'clients/7/sites/'.$site->id.'/backups/42';
        $newPrefix = 'clients/7/sites/'.$site->id.'/backups/43';

        // The old full uploaded two chunks and a DB dump.
        $this->write($oldPrefix.'/files/chunk_0.zip');
        $this->write($oldPrefix.'/files/chunk_1.zip');
        $this->write($oldPrefix.'/database/chunk_0.sql.gz');
        $this->write($oldPrefix.'/manifest.json', '{}');
        $this->write($oldPrefix.'/_COMPLETE');

        // The newer incremental uploaded one chunk of its own — and carried chunk_0 forward from the
        // full by reference, which is exactly what makes it restorable on its own.
        $this->write($newPrefix.'/files/chunk_0.zip');
        $this->write($newPrefix.'/database/chunk_0.sql.gz');
        $this->write($newPrefix.'/_COMPLETE');
        $this->write($newPrefix.'/manifest.json', (string) json_encode([
            'format_version' => 'simplead-backup/2',
            'objects' => [
                ['kind' => 'files', 'chunk_index' => 0, 'key' => $newPrefix.'/files/chunk_0.zip'],
                ['kind' => 'database', 'chunk_index' => 0, 'key' => $newPrefix.'/database/chunk_0.sql.gz'],
            ],
            'files' => [
                'included' => [
                    ['p' => 'changed.txt', 'key' => $newPrefix.'/files/chunk_0.zip'],
                    ['p' => 'untouched.txt', 'key' => $oldPrefix.'/files/chunk_0.zip'],
                ],
                'tombstones' => [],
            ],
        ]));

        $old = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'engine' => BackupEngine::V2,
            'file_path' => $oldPrefix,
            'status' => BackupStatus::Completed,
            'file_size' => 1024,
        ]);
        Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'engine' => BackupEngine::V2,
            'file_path' => $newPrefix,
            'status' => BackupStatus::Completed,
            'file_size' => 512,
        ]);

        app(RetentionService::class)->purge($old);

        // Still referenced → must survive, even though its own backup is gone.
        $this->assertFileExists(
            $this->storageDir.'/'.$oldPrefix.'/files/chunk_0.zip',
            'an object a newer restore point still names must not be deleted with its original backup',
        );

        // Referenced by nobody → reclaimed, which is the whole point of retention.
        $this->assertFileDoesNotExist($this->storageDir.'/'.$oldPrefix.'/files/chunk_1.zip');
        $this->assertFileDoesNotExist($this->storageDir.'/'.$oldPrefix.'/database/chunk_0.sql.gz');
        $this->assertFileDoesNotExist($this->storageDir.'/'.$oldPrefix.'/_COMPLETE');
        $this->assertDatabaseMissing('backups', ['id' => $old->id]);
    }

    /**
     * Fails closed: an unreadable manifest means an unknown reference set. Keeping objects that are
     * no longer needed costs storage; deleting objects that are still needed costs the backup.
     */
    public function test_an_unreadable_neighbour_manifest_stops_the_delete_rather_than_guessing(): void
    {
        $destination = $this->destination();
        $site = Site::factory()->create();

        $oldPrefix = 'clients/7/sites/'.$site->id.'/backups/50';
        $newPrefix = 'clients/7/sites/'.$site->id.'/backups/51';

        $this->write($oldPrefix.'/files/chunk_0.zip');
        $this->write($oldPrefix.'/_COMPLETE');
        $this->write($newPrefix.'/manifest.json', 'this is not json');

        $old = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'engine' => BackupEngine::V2,
            'file_path' => $oldPrefix,
            'status' => BackupStatus::Completed,
            'file_size' => 1024,
        ]);
        Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'engine' => BackupEngine::V2,
            'file_path' => $newPrefix,
            'status' => BackupStatus::Completed,
            'file_size' => 512,
        ]);

        app(RetentionService::class)->purge($old);

        $this->assertFileExists(
            $this->storageDir.'/'.$oldPrefix.'/files/chunk_0.zip',
            'nothing may be deleted while it is unknown what still references it',
        );
    }

    /**
     * A copy that is not there is a copy that does not need deleting.
     *
     * Backups carry a record of where they were replicated. When one of those copies had since been
     * removed by hand, the provider answered "no such path", the delete counted as a failure, and
     * the row was kept — permanently, because the next run asked the same question and got the same
     * answer. Every such backup was pinned forever, which is a large part of how a terabyte
     * accumulated: 917 backups that retention reported as deletable and could never actually delete.
     */
    public function test_a_replica_that_is_already_gone_does_not_block_the_delete(): void
    {
        $primary = $this->destination();
        $site = Site::factory()->create();

        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $primary->id,
            'engine' => BackupEngine::V1,
            'format' => 'v3-zip',
            'file_path' => 'backups/site-'.$site->id.'/backup.zip',
            'status' => BackupStatus::Completed,
            'file_size' => 1024,
        ]);

        // A provider that behaves the way Dropbox did: refuses to delete a path it cannot find, and
        // then confirms the path is not there.
        $service = new class extends RetentionService
        {
            protected function driverFor($destination): \App\Services\Backup\Storage\StorageDriver
            {
                return new class implements \App\Services\Backup\Storage\StorageDriver
                {
                    public function delete(string $remotePath): void
                    {
                        throw new \RuntimeException('Dropbox API error [409]: path_lookup/not_found/');
                    }

                    public function exists(string $remotePath): bool
                    {
                        return false;
                    }

                    public function upload(string $localPath, string $remotePath): void {}

                    public function download(string $remotePath, string $localPath): void {}

                    public function size(string $remotePath): int
                    {
                        return 0;
                    }

                    public function list(string $directory = ''): array
                    {
                        return [];
                    }

                    public function listRecursive(string $directory = ''): array
                    {
                        return [];
                    }

                    public function uploadToAbsolutePath(string $localPath, string $absoluteRemotePath): void {}

                    public function listFolders(string $absolutePath = ''): array
                    {
                        return [];
                    }

                    public function test(): bool
                    {
                        return true;
                    }

                    public function temporaryUrl(string $remotePath, int $expiresInMinutes = 60): ?string
                    {
                        return null;
                    }
                };
            }
        };

        $service->purge($backup);

        // A phantom replica must not pin the row — and with it the real copy — alive forever.
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
