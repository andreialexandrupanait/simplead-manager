<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RestoreBackup;
use App\Models\Backup;
use Tests\TestCase;
use ZipArchive;

/**
 * P1-31: the selective (partial) restore MUST fail loudly when the tar/zip
 * extraction does not fully succeed. Previously the tar exit code and stderr
 * were discarded, so a truncated/failed extraction was packaged and reported as
 * a successful "N files restored".
 */
class RestoreBackupSelectiveArchiveTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir().'/sel-restore-'.uniqid();
        mkdir($this->work, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->work);
        parent::tearDown();
    }

    private function makeJob(array $selectedFiles): RestoreBackup
    {
        $job = new RestoreBackup(new Backup, true, true, $selectedFiles);

        $ref = new \ReflectionProperty($job, 'tempDir');
        $ref->setAccessible(true);
        $ref->setValue($job, $this->work);

        return $job;
    }

    private function invokeSelective(RestoreBackup $job, string $inner): string
    {
        $m = new \ReflectionMethod($job, 'createSelectiveArchive');
        $m->setAccessible(true);

        return $m->invoke($job, $inner);
    }

    private function makeZip(array $entries): string
    {
        $path = $this->work.'/inner-files.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function makeTarGz(array $entries): string
    {
        $build = $this->work.'/tar-build';
        mkdir($build, 0755, true);
        foreach ($entries as $name => $content) {
            $full = $build.'/'.$name;
            @mkdir(dirname($full), 0755, true);
            file_put_contents($full, $content);
        }

        $path = $this->work.'/inner-files.tar.gz';
        exec('tar -czf '.escapeshellarg($path).' -C '.escapeshellarg($build).' .', $out, $code);
        $this->assertSame(0, $code, 'test fixture: tar build failed');

        return $path;
    }

    public function test_zip_selective_restore_succeeds_for_existing_files(): void
    {
        $inner = $this->makeZip([
            'wp-admin/index.php' => '<?php // admin',
            'wp-content/plugins/a.php' => 'A',
            'wp-content/plugins/b.php' => 'B',
        ]);

        $job = $this->makeJob(['wp-admin/index.php', 'wp-content/plugins/b.php']);
        $out = $this->invokeSelective($job, $inner);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($out) === true);
        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('wp-admin/index.php'));
        $this->assertNotFalse($zip->locateName('wp-content/plugins/b.php'));
        $this->assertFalse($zip->locateName('wp-content/plugins/a.php'));
        $zip->close();
    }

    public function test_zip_selective_restore_throws_when_no_selected_file_matches(): void
    {
        $inner = $this->makeZip(['wp-admin/index.php' => 'x']);

        $job = $this->makeJob(['wp-content/does-not-exist.php']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty archive');

        $this->invokeSelective($job, $inner);
    }

    public function test_tar_selective_restore_succeeds_for_existing_files(): void
    {
        $inner = $this->makeTarGz([
            'wp-admin/index.php' => 'admin',
            'wp-content/x.php' => 'X',
        ]);

        $job = $this->makeJob(['wp-admin/index.php']);
        $out = $this->invokeSelective($job, $inner);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($out) === true);
        $this->assertSame(1, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('wp-admin/index.php'));
        $zip->close();
    }

    public function test_tar_selective_restore_throws_on_nonzero_exit_code(): void
    {
        // Requesting a member that is not present makes tar exit non-zero
        // ("Not found in archive"). The old code ignored this and produced an
        // empty archive reported as success; now it must throw.
        $inner = $this->makeTarGz(['wp-admin/index.php' => 'admin']);

        $job = $this->makeJob(['wp-content/missing.php']);

        $this->expectException(\RuntimeException::class);
        // Either the tar exit-code guard or the empty-archive guard fires first;
        // both are P1-31 protections.
        $this->expectExceptionMessageMatches('/tar exit code|empty|Selective restore/i');

        $this->invokeSelective($job, $inner);
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
