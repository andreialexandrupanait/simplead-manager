<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\ManifestService;
use App\Services\Backup\SandboxRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

/**
 * Covers the REAL (unmocked) proven-restore core — SandboxRestoreService::prove()
 * driving restoreInto() → materialiseV3() → stageAndSend() → runHealthChecks() —
 * which the existing RunProvenRestoreTest bypasses by mocking prove() (DoD gap on
 * criterion 19). Runs fully in-process: a local StorageDestination serves a real
 * v3-zip fixture, the WordPress transport is a FakeWordPressApiService, and the
 * sandbox HTTP probes are Http::fake — no lab MinIO / mysqli / spike-wp required.
 */
#[Group('proven-restore')]
class SandboxRestoreProveTest extends TestCase
{
    use RefreshDatabase;

    private string $storeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storeDir = sys_get_temp_dir().'/sandbox-prove-'.uniqid('', true);
        mkdir($this->storeDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->storeDir)) {
            $this->rrmdir($this->storeDir);
        }
        parent::tearDown();
    }

    public function test_prove_restores_a_v3_backup_into_the_sandbox_and_records_passing_checks(): void
    {
        $sandbox = Site::factory()->create(['url' => 'https://sandbox.example.test', 'is_sandbox' => true]);
        $backup = $this->v3Backup($sandbox, rows: 42);

        // Sandbox WordPress transport + health probes, all healthy.
        $fake = $this->fakeApi()
            ->script('request', ['success' => true])                     // /backup/restore staged send
            ->script('runDiagnostic', [
                'loopback' => ['status' => 200],
                'database' => ['total_rows' => 42],                      // matches the manifest baseline
            ]);
        $this->fakeManifestRows(42);
        Http::fake([
            'sandbox.example.test/*' => Http::response('ok', 200),        // homepage + wp-login.php
        ]);

        $result = app(SandboxRestoreService::class)->prove($sandbox, $backup);

        $this->assertTrue($result['passed'], 'Proof should pass: '.json_encode($result));
        $this->assertNull($result['error']);
        $this->assertSame(
            ['homepage_200', 'login_reachable', 'loopback_ok', 'db_coherent'],
            array_keys($result['checks']),
        );
        foreach ($result['checks'] as $name => $ok) {
            $this->assertTrue($ok, "health check {$name} should pass");
        }

        // restoreInto() really ran: both the files.zip and the database.sql.gz were
        // staged and sent to the sandbox connector (not mocked away).
        $sentTypes = collect($fake->callsTo('request'))
            ->filter(fn ($c) => ($c['args'][1] ?? null) === '/backup/restore')
            ->map(fn ($c) => $c['args'][2]['type'] ?? null)
            ->all();
        $this->assertEqualsCanonicalizing(['files', 'database'], $sentTypes);
    }

    public function test_prove_fails_closed_when_the_archive_is_corrupt(): void
    {
        $sandbox = Site::factory()->create(['url' => 'https://sandbox.example.test', 'is_sandbox' => true]);
        $backup = $this->v3Backup($sandbox, rows: 10);
        $backup->update(['checksum' => str_repeat('0', 64)]); // wrong sha256 → corrupt

        $fake = $this->fakeApi()->script('request', ['success' => true]);
        Http::fake(['sandbox.example.test/*' => Http::response('ok', 200)]);

        $result = app(SandboxRestoreService::class)->prove($sandbox, $backup);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('restore failed', (string) $result['error']);
        // A corrupt archive must never reach the sandbox transport.
        $fake->assertNotCalled('request');
    }

    /** Build a real v3-zip (files/ subtree + database.sql.gz) on a local destination. */
    private function v3Backup(Site $site, int $rows): Backup
    {
        $archive = $this->storeDir.'/backup.zip';
        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database.sql.gz', gzencode("-- dump with {$rows} rows\n"));
        $zip->addFromString('files/wp-content/uploads/a.txt', 'hello');
        $zip->addFromString('files/wp-content/themes/x/style.css', 'body{}');
        $zip->close();

        $destination = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->storeDir],
        ]);

        return Backup::factory()->completed()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'format' => 'v3-zip',
            'type' => 'full',
            'file_path' => 'backup.zip',
            'file_name' => 'backup.zip',
            'checksum' => hash_file('sha256', $archive),
        ]);
    }

    private function fakeManifestRows(int $rows): void
    {
        $mock = Mockery::mock(ManifestService::class);
        $mock->shouldReceive('retrieve')->andReturn(['database' => ['total_rows' => $rows]]);
        $this->app->instance(ManifestService::class, $mock);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
