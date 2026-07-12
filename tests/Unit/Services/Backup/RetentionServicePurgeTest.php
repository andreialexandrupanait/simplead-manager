<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupSidecarMetadata;
use App\Services\Backup\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * P1-28: manual deletion used to remove only the primary `file_path` on the
 * primary destination, leaking every secondary replica and the sidecar
 * metadata files. RetentionService::purge() routes manual deletes through the
 * complete, replica-aware cleanup so nothing is orphaned in remote storage.
 */
class RetentionServicePurgeTest extends TestCase
{
    use RefreshDatabase;

    private string $primaryDir;

    private string $replicaDir;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();
        $this->primaryDir = sys_get_temp_dir().'/purge-primary-'.uniqid();
        $this->replicaDir = sys_get_temp_dir().'/purge-replica-'.uniqid();
    }

    protected function tearDown(): void
    {
        foreach ([$this->primaryDir, $this->replicaDir] as $dir) {
            $this->rrmdir($dir);
        }
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function writeArtifact(string $baseDir, string $remotePath): void
    {
        $full = $baseDir.'/'.$remotePath;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, 'zip-bytes');
        file_put_contents($full.BackupSidecarMetadata::SUFFIX, '{"meta":true}');
    }

    public function test_purge_removes_primary_and_replica_artifacts_and_the_row(): void
    {
        $primary = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->primaryDir],
            'used_bytes' => 5000,
        ]);
        $replica = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->replicaDir],
            'used_bytes' => 5000,
        ]);
        $site = Site::factory()->create();

        $remotePath = $site->domain.'/backup.zip';
        $this->writeArtifact($this->primaryDir, $remotePath);
        $this->writeArtifact($this->replicaDir, $remotePath);

        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $primary->id,
            'file_path' => $remotePath,
            'file_size' => 500,
            'format' => 'v3-zip',
            'status' => BackupStatus::Completed,
            'replicas' => [
                ['destination_id' => $primary->id, 'remote_path' => $remotePath, 'status' => 'completed'],
                ['destination_id' => $replica->id, 'remote_path' => $remotePath, 'status' => 'completed'],
            ],
        ]);

        $purged = app(RetentionService::class)->purge($backup);

        $this->assertTrue($purged);
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);

        // Every artifact across BOTH destinations is gone, sidecars included.
        $this->assertFileDoesNotExist($this->primaryDir.'/'.$remotePath);
        $this->assertFileDoesNotExist($this->primaryDir.'/'.$remotePath.BackupSidecarMetadata::SUFFIX);
        $this->assertFileDoesNotExist($this->replicaDir.'/'.$remotePath);
        $this->assertFileDoesNotExist($this->replicaDir.'/'.$remotePath.BackupSidecarMetadata::SUFFIX);

        // Both destinations were decremented (not just the primary).
        $this->assertSame(4500, $replica->fresh()->used_bytes);
        $this->assertSame(4500, $primary->fresh()->used_bytes);
    }
}
