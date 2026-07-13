<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * P2-33: Level-B / on-demand verification of v3-zip backups must route through the
 * SAME full integrity verifier the creation path uses (including the has-files
 * assertion) and fall back to a replica when the primary storage is unreachable.
 */
class BackupVerifierReplicaTest extends TestCase
{
    use RefreshDatabase;

    private string $primaryDir;

    private string $replicaDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryDir = sys_get_temp_dir().'/bv_primary_'.uniqid();
        $this->replicaDir = sys_get_temp_dir().'/bv_replica_'.uniqid();
        mkdir($this->primaryDir, 0700, true);
        mkdir($this->replicaDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->primaryDir, $this->replicaDir] as $dir) {
            if (is_dir($dir)) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($items as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    public function test_v3zip_level_b_uses_full_integrity_and_fails_when_files_subtree_missing(): void
    {
        $site = Site::factory()->create();
        $primary = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->primaryDir],
        ]);

        // A v3-zip with NO files/* subtree. The legacy verifyArchive would pass
        // this (no chunk_files declared = 0 files, OK); verifyV3Zip must FAIL it.
        $relPath = 'backup.zip';
        $sha = $this->buildV3Zip($this->primaryDir.'/'.$relPath, withFiles: false, type: 'full');

        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $primary->id,
            'status' => BackupStatus::Completed,
            'format' => 'v3-zip',
            'file_path' => $relPath,
            'file_name' => $relPath,
            'checksum' => $sha,
            'replicas' => [['destination_id' => $primary->id, 'remote_path' => $relPath, 'status' => 'completed']],
        ]);

        $result = app(BackupVerifier::class)->verify($backup->fresh());

        $this->assertFalse($result['ok'], 'v3-zip without files/ must fail the full integrity check');
        $this->assertStringContainsString('no files/*', $result['message']);
        $this->assertSame('failed', $backup->fresh()->verification_status);
    }

    public function test_primary_unreachable_verifies_ok_via_replica(): void
    {
        $site = Site::factory()->create();
        $primary = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->primaryDir], // left EMPTY → download fails
        ]);
        $replica = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->replicaDir],
        ]);

        // Intact backup exists ONLY in the replica; the primary directory is empty.
        $relPath = 'backup.zip';
        $sha = $this->buildV3Zip($this->replicaDir.'/'.$relPath, withFiles: true, type: 'full');

        $backup = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $primary->id,
            'status' => BackupStatus::Completed,
            'format' => 'v3-zip',
            'file_path' => $relPath,
            'file_name' => $relPath,
            'checksum' => $sha,
            'replicas' => [
                ['destination_id' => $primary->id, 'remote_path' => $relPath, 'status' => 'completed'],
                ['destination_id' => $replica->id, 'remote_path' => $relPath, 'status' => 'completed'],
            ],
        ]);

        $result = app(BackupVerifier::class)->verify($backup->fresh());

        $this->assertTrue($result['ok'], 'healthy replicated backup must verify OK even when primary is unreachable: '.$result['message']);
        $this->assertSame('passed', $backup->fresh()->verification_status);
    }

    public function test_level_b_sample_size_honors_config(): void
    {
        config()->set('backups.level_b_sample_size', 2);

        $site = Site::factory()->create();
        $dest = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->primaryDir],
        ]);

        // Three intact, never-verified candidates. With sample size 2, only 2 run.
        for ($i = 0; $i < 3; $i++) {
            $relPath = "backup-{$i}.zip";
            $sha = $this->buildV3Zip($this->primaryDir.'/'.$relPath, withFiles: true, type: 'full');
            Backup::factory()->create([
                'site_id' => $site->id,
                'storage_destination_id' => $dest->id,
                'status' => BackupStatus::Completed,
                'format' => 'v3-zip',
                'file_path' => $relPath,
                'file_name' => $relPath,
                'checksum' => $sha,
                'verified_at' => null,
                'created_at' => now()->subDays($i + 1),
                'replicas' => [['destination_id' => $dest->id, 'remote_path' => $relPath, 'status' => 'completed']],
            ]);
        }

        $this->artisan('backup:verify-restore')->assertExitCode(0);

        $this->assertSame(2, Backup::whereNotNull('verified_at')->count());
    }

    private function buildV3Zip(string $absPath, bool $withFiles, string $type): string
    {
        $dir = dirname($absPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $dbContent = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nCREATE TABLE foo (id int);\nINSERT INTO foo VALUES (1);\nSET FOREIGN_KEY_CHECKS = 1;\n";
        $dbPath = $dir.'/database-'.uniqid().'.sql.gz';
        $gz = gzopen($dbPath, 'wb');
        gzwrite($gz, $dbContent);
        gzclose($gz);

        $zip = new ZipArchive;
        $zip->open($absPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($dbPath, 'database.sql.gz');
        $zip->setCompressionName('database.sql.gz', ZipArchive::CM_STORE);
        if ($withFiles) {
            $zip->addFromString('files/wp-config.php', "<?php // wp\n");
            $zip->addFromString('files/index.php', "<?php // index\n");
        }
        $zip->addFromString('backup-meta.json', json_encode([
            'format' => 'v3-zip',
            'type' => $type,
            'site_url' => 'https://example.test',
            'created_at' => '2026-07-12T00:00:00+00:00',
            'trigger' => 'manual',
        ]));
        $zip->close();

        @unlink($dbPath);

        return hash_file('sha256', $absPath);
    }
}
