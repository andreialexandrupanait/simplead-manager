<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Legacy;

use App\Backup\V2\Legacy\LegacyImportService;
use App\Backup\V2\Models\LegacyBackupIndexEntry;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupManifestV3;
use App\Services\Backup\BackupSidecarMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P6 read-only legacy IMPORT: with a v2-zip (sidecar), a multipart-v3 (manifest),
 * an orphaned object and a phantom row on local storage, the importer classifies
 * each per LEGACY-BACKUPS-DECISION.md (A–F) and writes read-only index rows —
 * WITHOUT touching a single legacy object.
 */
class LegacyImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir().'/legacyimp_'.uniqid();
        mkdir($this->storageDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->storageDir);
        parent::tearDown();
    }

    public function test_classifies_and_indexes_legacy_backups_read_only(): void
    {
        $destination = $this->localDestination();
        $site = Site::factory()->create();

        // (A) multipart-v3, completed + verified → valid.
        $this->writeManifest('clients/1/sites/10/backups/501', siteId: $site->id);
        $valid = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::Completed,
            'format' => BackupManifestV3::FORMAT,
            'file_path' => 'clients/1/sites/10/backups/501',
            'verification_status' => 'passed',
        ]);

        // (B) v2-zip, completed + unverified → verification_required.
        $this->writeZipWithSidecar('clients/1/sites/11/backups/502/backup.zip', siteId: $site->id, domain: 'legacy.example');
        $unverified = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::Completed,
            'format' => 'v2-zip',
            'file_path' => 'clients/1/sites/11/backups/502/backup.zip',
            'verification_status' => 'pending',
        ]);

        // (F) phantom: a completed row whose manifest is NOT on disk.
        $phantom = Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::Completed,
            'format' => BackupManifestV3::FORMAT,
            'file_path' => 'clients/1/sites/12/backups/503',
        ]);

        // (E) orphan: a manifest on disk with NO backups row (references a gone site).
        $this->writeManifest('clients/1/sites/99/backups/orphan', siteId: 99);

        // Snapshot storage before → prove read-only (no object added/removed/changed).
        $before = $this->snapshotStorage();

        $result = (new LegacyImportService)->importDestination($destination, persist: true);

        // Classification is correct.
        $byBackup = fn (Backup $b): ?LegacyBackupIndexEntry => LegacyBackupIndexEntry::where('backup_id', $b->id)->first();
        $this->assertSame(LegacyBackupIndexEntry::CLASS_VALID, $byBackup($valid)?->classification);
        $this->assertSame(LegacyBackupIndexEntry::CLASS_VERIFICATION_REQUIRED, $byBackup($unverified)?->classification);
        $this->assertSame(LegacyBackupIndexEntry::CLASS_PHANTOM, $byBackup($phantom)?->classification);

        $orphan = LegacyBackupIndexEntry::where('classification', LegacyBackupIndexEntry::CLASS_ORPHANED)->first();
        $this->assertNotNull($orphan, 'the manifest with no row must be indexed as orphaned');
        $this->assertNull($orphan->backup_id);
        $this->assertSame(99, $orphan->site_id, 'orphan descriptor site_id is preserved even though site 99 is gone');

        // Enrichment: valid entry carried the manifest composite + file stats.
        $validEntry = $byBackup($valid);
        $this->assertTrue($validEntry->object_present);
        $this->assertNotNull($validEntry->composite_checksum);
        $this->assertGreaterThan(0, $validEntry->file_count);

        // Counts reported.
        $this->assertSame(1, $result['counts'][LegacyBackupIndexEntry::CLASS_VALID] ?? 0);
        $this->assertSame(1, $result['counts'][LegacyBackupIndexEntry::CLASS_VERIFICATION_REQUIRED] ?? 0);
        $this->assertSame(1, $result['counts'][LegacyBackupIndexEntry::CLASS_PHANTOM] ?? 0);
        $this->assertSame(1, $result['counts'][LegacyBackupIndexEntry::CLASS_ORPHANED] ?? 0);

        // READ-ONLY: storage is byte-for-byte unchanged.
        $this->assertSame($before, $this->snapshotStorage(), 'legacy objects must be untouched');
    }

    public function test_dry_run_persists_nothing(): void
    {
        $destination = $this->localDestination();
        $site = Site::factory()->create();
        $this->writeManifest('clients/1/sites/10/backups/501', siteId: $site->id);
        Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::Completed,
            'format' => BackupManifestV3::FORMAT,
            'file_path' => 'clients/1/sites/10/backups/501',
            'verification_status' => 'passed',
        ]);

        $result = (new LegacyImportService)->importDestination($destination, persist: false);

        $this->assertSame(0, LegacyBackupIndexEntry::count(), 'dry-run writes no index rows');
        $this->assertSame(1, $result['counts'][LegacyBackupIndexEntry::CLASS_VALID] ?? 0, 'but still classifies');
    }

    public function test_reimport_is_idempotent(): void
    {
        $destination = $this->localDestination();
        $site = Site::factory()->create();
        $this->writeManifest('clients/1/sites/10/backups/501', siteId: $site->id);
        Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::Completed,
            'format' => BackupManifestV3::FORMAT,
            'file_path' => 'clients/1/sites/10/backups/501',
            'verification_status' => 'passed',
        ]);

        $service = new LegacyImportService;
        $service->importDestination($destination, persist: true);
        $service->importDestination($destination, persist: true);

        $this->assertSame(1, LegacyBackupIndexEntry::count(), 're-import updates in place (unique dest+path)');
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function localDestination(): StorageDestination
    {
        return StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->storageDir],
            'is_active' => true,
        ]);
    }

    private function writeManifest(string $prefix, int $siteId): void
    {
        $files = [
            ['path' => 'wp-content/a.txt', 'size' => 10, 'sha256' => hash('sha256', 'a')],
            ['path' => 'wp-content/b.txt', 'size' => 20, 'sha256' => hash('sha256', 'b')],
        ];
        $manifest = [
            'format_version' => BackupManifestV3::FORMAT_VERSION,
            'site_id' => $siteId,
            'site_url' => 'https://legacy.example',
            'site_domain' => 'legacy.example',
            'type' => 'full',
            'created_at' => now()->toIso8601String(),
            'composite_checksum' => hash('sha256', implode('', array_column($files, 'sha256'))),
            'total_size' => 30,
            'files' => $files,
        ];
        $dir = $this->storageDir.'/'.$prefix;
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/'.BackupManifestV3::MANIFEST_FILENAME, BackupManifestV3::encode($manifest));
    }

    private function writeZipWithSidecar(string $zipRel, int $siteId, string $domain): void
    {
        $abs = $this->storageDir.'/'.$zipRel;
        mkdir(dirname($abs), 0700, true);
        file_put_contents($abs, 'PK-fake-zip-bytes');

        $sidecar = [
            'schema_version' => BackupSidecarMetadata::SCHEMA_VERSION,
            'format' => 'v2-zip',
            'site_id' => $siteId,
            'site_domain' => $domain,
            'type' => 'full',
            'created_at' => now()->toIso8601String(),
            'file_name' => basename($zipRel),
            'file_size' => 17,
            'checksum' => hash('sha256', 'PK-fake-zip-bytes'),
            'includes_files' => true,
            'includes_database' => true,
        ];
        file_put_contents($abs.BackupSidecarMetadata::SUFFIX, json_encode($sidecar, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string,string> rel-path => sha256 of every stored object
     */
    private function snapshotStorage(): array
    {
        $out = [];
        $base = rtrim($this->storageDir, '/');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = ltrim(substr($f->getPathname(), strlen($base)), '/');
                $out[$rel] = (string) hash_file('sha256', $f->getPathname());
            }
        }
        ksort($out);

        return $out;
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
