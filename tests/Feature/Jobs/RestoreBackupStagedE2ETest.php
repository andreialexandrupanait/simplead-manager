<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\RestoreBackup;
use App\Models\Backup;
use App\Models\Site;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use ZipArchive;

/**
 * C-13: end-to-end safety net for the restore execution core (the RestoreBackup
 * god-object, slated for decomposition in Faza F). Drives `doRestore()` through
 * the FakeWordPressApiService and locks the load-bearing contracts:
 *   - a FULL restore sends the connector's atomic staged swap (file_mode=staged);
 *   - a SELECTIVE restore must merge in place (file_mode=merge) — a swap would
 *     wipe the files not in the selection;
 *   - post-restore health checks run;
 *   - a transport failure propagates (never a silent "success").
 */
class RestoreBackupStagedE2ETest extends TestCase
{
    use RefreshDatabase;

    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();  // doRestore dispatches SyncWordPressSite on success — don't run it
        Redis::spy();   // JobTracker writes progress to the cache/redis
        $this->baseDir = sys_get_temp_dir().'/restore-e2e-'.uniqid();
        mkdir($this->baseDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->baseDir);
        parent::tearDown();
    }

    private function backup(): Backup
    {
        $site = Site::factory()->create();

        return Backup::factory()->completed()->create([
            'site_id' => $site->id,
            'format' => 'v3-zip',
            'type' => 'full',
        ]);
    }

    /** Script the fake for a clean restore: transport OK + all health checks OK. */
    private function scriptHealthyRestore(): \Tests\Fakes\FakeWordPressApiService
    {
        return $this->fakeApi()
            ->script('request', ['success' => true])
            ->script('clearCache', ['success' => true])
            ->script('fixElementor', ['fixed' => []])
            ->script('deactivatePlugin', ['success' => true])
            ->script('activatePlugin', ['success' => true])
            ->script('runDiagnostic', ['loopback' => ['status' => 200], 'paused_extensions' => []]);
    }

    private function writeFullArchive(): void
    {
        file_put_contents($this->baseDir.'/files.zip', "PK\x03\x04dummy-files-archive");
        file_put_contents($this->baseDir.'/database.sql.gz', gzencode('-- dummy sql dump'));
    }

    private function invokeDoRestore(RestoreBackup $job, $api): void
    {
        $m = new \ReflectionMethod($job, 'doRestore');
        $m->setAccessible(true);
        $m->invoke($job, $api, $this->baseDir);
    }

    /** @return array<int,array<string,mixed>> the /backup/restore POST bodies */
    private function restorePostBodies(\Tests\Fakes\FakeWordPressApiService $fake): array
    {
        return collect($fake->callsTo('request'))
            ->filter(fn ($c) => ($c['args'][1] ?? null) === '/backup/restore')
            ->map(fn ($c) => $c['args'][2] ?? [])
            ->values()
            ->all();
    }

    public function test_full_restore_uses_the_staged_swap_and_runs_health_checks(): void
    {
        $backup = $this->backup();
        $fake = $this->scriptHealthyRestore();
        $this->writeFullArchive();

        $job = new RestoreBackup($backup, restoreDatabase: true, restoreFiles: true);
        $this->invokeDoRestore($job, $fake);

        // Every /backup/restore call is the atomic staged swap.
        $bodies = $this->restorePostBodies($fake);
        $this->assertNotEmpty($bodies, 'expected the connector /backup/restore endpoint to be called');
        foreach ($bodies as $body) {
            $this->assertSame('staged', $body['file_mode'] ?? null);
        }
        // Both files and database were restored (files before database).
        $this->assertEqualsCanonicalizing(['files', 'database'], array_column($bodies, 'type'));

        // Post-restore health checks ran, and the restore is marked complete.
        $fake->assertCalled('clearCache');
        $fake->assertCalled('runDiagnostic');
        $this->assertSame(BackupStatus::Completed, $backup->fresh()->restore_status);
    }

    public function test_selective_restore_merges_in_place_instead_of_swapping(): void
    {
        $backup = $this->backup();
        $fake = $this->scriptHealthyRestore();

        // A real files.zip containing the selected file, plus one to leave behind.
        $zip = new ZipArchive;
        $zip->open($this->baseDir.'/files.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('wp-content/uploads/keep.jpg', 'KEEP');
        $zip->addFromString('wp-content/uploads/other.jpg', 'OTHER');
        $zip->close();

        $job = new RestoreBackup(
            $backup,
            restoreDatabase: false,
            restoreFiles: true,
            selectedFiles: ['wp-content/uploads/keep.jpg'],
        );
        // createSelectiveArchive writes under the job's tempDir.
        $tmp = new \ReflectionProperty($job, 'tempDir');
        $tmp->setAccessible(true);
        $tmp->setValue($job, $this->baseDir);

        $this->invokeDoRestore($job, $fake);

        $bodies = $this->restorePostBodies($fake);
        $this->assertNotEmpty($bodies);
        // Selective restore must merge — never a swap that would delete other.jpg.
        foreach ($bodies as $body) {
            $this->assertSame('merge', $body['file_mode'] ?? null);
        }
        $this->assertSame(['files'], array_column($bodies, 'type'));
        $this->assertSame(BackupStatus::Completed, $backup->fresh()->restore_status);
    }

    public function test_a_transport_failure_propagates_and_does_not_report_success(): void
    {
        $backup = $this->backup();
        $this->writeFullArchive();

        // The connector rejects the restore POST (5xx) — sendRestoreData throws.
        $fake = $this->fakeApi()->script(
            'request',
            new Response(new Psr7Response(500, [], json_encode(['error' => 'connector exploded']))),
        );

        $job = new RestoreBackup($backup, restoreDatabase: true, restoreFiles: true);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        try {
            $this->invokeDoRestore($job, $fake);
        } finally {
            // The restore must NOT be marked completed on a transport failure.
            $this->assertNotSame(BackupStatus::Completed, $backup->fresh()->restore_status);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
