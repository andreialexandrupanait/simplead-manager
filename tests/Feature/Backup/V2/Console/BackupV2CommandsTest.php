<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Console;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Models\LegacyBackupIndexEntry;
use App\Backup\V2\Storage\ObjectLayout;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupManifestV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Support\ManualBackupWriter;
use Tests\TestCase;

/**
 * P6 command surface: every new command is INERT without its flag (production
 * safety), and functional when explicitly enabled.
 */
#[Group('minio')]
class BackupV2CommandsTest extends TestCase
{
    use InteractsWithLabMinio;
    use RefreshDatabase;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMinio();
        $this->storageDir = sys_get_temp_dir().'/cmd_'.uniqid();
        mkdir($this->storageDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupMinio();
        // setUp() can markTestSkipped mid-way (lab MinIO absent) before
        // $storageDir is assigned; guard so tearDown skips cleanly.
        if (isset($this->storageDir)) {
            $this->deleteDir($this->storageDir);
        }
        parent::tearDown();
    }

    public function test_deep_verify_is_inert_without_flag(): void
    {
        Config::set('backup_v2.enabled', false);
        $this->artisan('backup:v2-deep-verify')->assertSuccessful();
        $this->assertSame(0, BackupVerification::where('kind', 'deep')->count());
    }

    public function test_proven_restore_is_inert_without_flag(): void
    {
        Config::set('backup_v2.proven_restore_enabled', false);
        Site::factory()->create(['proven_restore_enabled' => true]);
        $this->artisan('backup:v2-proven-restore')->assertSuccessful();
        $this->assertDatabaseCount('proven_restores', 0);
    }

    public function test_import_legacy_is_read_only_dry_run_by_default(): void
    {
        $destination = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->storageDir],
            'is_active' => true,
        ]);
        $site = Site::factory()->create();
        $dir = $this->storageDir.'/clients/1/sites/1/backups/1';
        mkdir($dir, 0700, true);
        file_put_contents($dir.'/'.BackupManifestV3::MANIFEST_FILENAME, BackupManifestV3::encode([
            'format_version' => BackupManifestV3::FORMAT_VERSION,
            'type' => 'full',
            'files' => [['path' => 'a', 'size' => 1, 'sha256' => hash('sha256', 'a')]],
        ]));
        Backup::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'status' => BackupStatus::Completed,
            'format' => BackupManifestV3::FORMAT,
            'file_path' => 'clients/1/sites/1/backups/1',
            'verification_status' => 'passed',
        ]);

        // Default (no --apply): classifies but writes NO index rows.
        $this->artisan('backup:v2-import-legacy')->assertSuccessful();
        $this->assertSame(0, LegacyBackupIndexEntry::count());

        // --apply without backup_v2.enabled is refused (still read-only).
        Config::set('backup_v2.enabled', false);
        $this->artisan('backup:v2-import-legacy', ['--apply' => true])->assertSuccessful();
        $this->assertSame(0, LegacyBackupIndexEntry::count());

        // --apply WITH the master flag writes index rows.
        Config::set('backup_v2.enabled', true);
        $this->artisan('backup:v2-import-legacy', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, LegacyBackupIndexEntry::where('classification', 'valid')->count());
    }

    public function test_deep_verify_records_verification_when_enabled(): void
    {
        Config::set('backup_v2.enabled', true);

        $site = Site::factory()->create();
        // Deep-verify now enforces the site allowlist (BackupV2Gate::siteAllowed).
        Config::set('backup_v2.site_ids', [(string) $site->id]);
        $session = BackupSession::create([
            'site_id' => $site->id,
            'type' => 'full',
            'scope' => ['database' => true, 'files' => true],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Completed,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'cmd-deep-'.uniqid('', true),
            'format_version' => 'simplead-backup/1',
            'completed_at' => now(),
        ]);

        $template = $this->testPrefix.'/clients/{client_id}/sites/{site_id}/backups/{backup_id}';
        $layout = new ObjectLayout(1, $session->site_id, $session->id, $template);
        ManualBackupWriter::write($this->minioClient(), $this->bucket, $layout, fileChunks: 1, dbSegments: 1);

        $this->artisan('backup:v2-deep-verify', [
            '--session' => $session->id,
            '--prefix-template' => $template,
            '--client' => 1,
            '--sample' => 0,
        ])->assertSuccessful();

        $this->assertDatabaseHas('backup_verifications', [
            'backup_session_id' => $session->id,
            'kind' => 'deep',
            'status' => 'passed',
        ]);
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
