<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupBrowserService;
use App\Services\Backup\BackupManifestV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

/**
 * P1-32: the selective-restore file browser must understand the current v3-zip
 * write path (WP files under a `files/` subtree) and the multipart-v3
 * (BackupManifestV3) chunk layout — otherwise every recent backup reports
 * has_files=false and granular restore is impossible.
 */
class BackupBrowserServiceV3Test extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private StorageDestination $destination;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        Cache::flush();

        $this->base = sys_get_temp_dir().'/browser-v3-'.uniqid();
        mkdir($this->base, 0755, true);

        $this->destination = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => $this->base],
            'is_active' => true,
        ]);
        $this->site = Site::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
        parent::tearDown();
    }

    public function test_v3_zip_browser_lists_files_under_files_prefix_with_prefix_stripped(): void
    {
        $zipPath = $this->base.'/v3.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('database.sql.gz', 'gzip-bytes');
        $zip->addFromString('backup-meta.json', '{}');
        $zip->addFromString('files/wp-admin/index.php', '<?php');
        $zip->addFromString('files/wp-content/plugins/a/a.php', 'A');
        $zip->close();

        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $this->destination->id,
            'format' => 'v3-zip',
            'file_path' => 'v3.zip',
            'file_name' => 'v3.zip',
        ]);

        $result = (new BackupBrowserService)->listContents($backup);

        $this->assertTrue($result['has_database']);
        $this->assertTrue($result['has_files']);
        $this->assertSame(2, $result['file_count']);

        $paths = array_column($result['files'], 'path');
        sort($paths);
        $this->assertSame(['wp-admin/index.php', 'wp-content/plugins/a/a.php'], $paths);
    }

    public function test_multipart_v3_browser_lists_files_from_chunks(): void
    {
        $prefix = $this->site->domain.'/full-2026-07-11-000000';
        @mkdir($this->base.'/'.$prefix.'/chunks', 0755, true);

        // chunk zip holds WP-root-relative entries (as the connector produces)
        $chunk = new ZipArchive;
        $chunk->open($this->base.'/'.$prefix.'/chunks/0.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $chunk->addFromString('wp-admin/index.php', '<?php');
        $chunk->addFromString('wp-content/themes/t/style.css', 'css');
        $chunk->close();

        $manifest = [
            'format_version' => BackupManifestV3::FORMAT_VERSION,
            'type' => 'full',
            'includes_database' => true,
            'includes_files' => true,
            'files' => [
                ['name' => 'chunks/0.zip', 'size' => 10, 'sha256' => str_repeat('a', 64)],
                ['name' => 'database.sql.gz', 'size' => 5, 'sha256' => str_repeat('b', 64)],
            ],
        ];
        file_put_contents(
            $this->base.'/'.$prefix.'/'.BackupManifestV3::MANIFEST_FILENAME,
            json_encode($manifest)
        );

        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $this->destination->id,
            'format' => BackupManifestV3::FORMAT,
            'file_path' => $prefix,
            'file_name' => 'manifest.json',
        ]);

        $result = (new BackupBrowserService)->listContents($backup);

        $this->assertTrue($result['has_database']);
        $this->assertTrue($result['has_files']);
        $this->assertSame(2, $result['file_count']);

        $paths = array_column($result['files'], 'path');
        sort($paths);
        $this->assertSame(['wp-admin/index.php', 'wp-content/themes/t/style.css'], $paths);
    }

    public function test_legacy_inner_files_zip_still_lists(): void
    {
        // Legacy single-zip layout with an inner files.zip.
        $inner = $this->base.'/files.zip';
        $iz = new ZipArchive;
        $iz->open($inner, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $iz->addFromString('wp-admin/index.php', '<?php');
        $iz->close();

        $outer = $this->base.'/legacy.zip';
        $oz = new ZipArchive;
        $oz->open($outer, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $oz->addFromString('database.sql.gz', 'gz');
        $oz->addFile($inner, 'files.zip');
        $oz->close();
        @unlink($inner);

        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'storage_destination_id' => $this->destination->id,
            'format' => 'v2-zip',
            'file_path' => 'legacy.zip',
            'file_name' => 'legacy.zip',
        ]);

        $result = (new BackupBrowserService)->listContents($backup);

        $this->assertTrue($result['has_database']);
        $this->assertTrue($result['has_files']);
        $this->assertSame(1, $result['file_count']);
        $this->assertSame('wp-admin/index.php', $result['files'][0]['path']);
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
