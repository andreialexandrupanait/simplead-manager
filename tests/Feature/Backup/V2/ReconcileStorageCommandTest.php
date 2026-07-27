<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupManifestV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReconcileStorageCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir().'/reconcile_'.uniqid();
        mkdir($this->storageDir, 0700, true);
        // Force strict read-only for the whole suite.
        Config::set('backup_v2.reconciliation_writes_enabled', false);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->storageDir);
        parent::tearDown();
    }

    private function localDestination(int $usedBytes = 0): StorageDestination
    {
        return StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->storageDir],
            'is_active' => true,
            'used_bytes' => $usedBytes,
        ]);
    }

    public function test_runs_read_only_on_empty_storage_without_throwing(): void
    {
        $this->localDestination();

        $this->artisan('backup:reconcile-storage')
            ->assertSuccessful();
    }

    public function test_reports_missing_manifest_and_does_not_write(): void
    {
        $destination = $this->localDestination(usedBytes: 5_000);
        $site = Site::factory()->create();

        // DB says this multipart-v3 backup exists, but no manifest is on disk.
        Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::Completed,
            'format' => BackupManifestV3::FORMAT,
            'file_path' => 'clients/1/sites/1/backups/999',
        ]);

        $this->artisan('backup:reconcile-storage', ['--json' => true])
            ->assertSuccessful();

        // Read-only: used_bytes must be untouched despite the drift.
        $destination->refresh();
        $this->assertSame(5_000, $destination->used_bytes);
    }

    public function test_detects_no_drift_when_manifest_present(): void
    {
        $destination = $this->localDestination();
        $site = Site::factory()->create();

        $prefix = 'clients/1/sites/1/backups/present';
        $manifestDir = $this->storageDir.'/'.$prefix;
        mkdir($manifestDir, 0700, true);
        file_put_contents($manifestDir.'/'.BackupManifestV3::MANIFEST_FILENAME, '{"format_version":3,"type":"full","files":[]}');

        Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::Completed,
            'format' => BackupManifestV3::FORMAT,
            'file_path' => $prefix,
        ]);

        $this->artisan('backup:reconcile-storage', ['--site' => $site->id])
            ->assertSuccessful();
    }

    public function test_site_filter_scopes_the_report(): void
    {
        $destination = $this->localDestination();
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        Backup::factory()->create([
            'site_id' => $siteB->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::Completed,
            'format' => BackupManifestV3::FORMAT,
            'file_path' => 'clients/1/sites/2/backups/xyz',
        ]);

        // Filtering to site A (which has no backups) must still succeed cleanly.
        $this->artisan('backup:reconcile-storage', ['--site' => $siteA->id])
            ->assertSuccessful();
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
