<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup\Storage;

use App\Services\Backup\Storage\LocalDriver;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class LocalDriverTest extends TestCase
{
    private string $tempDir;

    private LocalDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/local-driver-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->driver = new LocalDriver(['path' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        // Cleanup temp directory
        $this->removeDir($this->tempDir);

        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function createTempFile(string $content = 'test content'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ld_test_');
        file_put_contents($path, $content);

        return $path;
    }

    #[Test]
    public function upload_copies_file_to_destination(): void
    {
        $source = $this->createTempFile('backup data');

        $this->driver->upload($source, 'site1/backup.zip');

        $this->assertFileExists($this->tempDir.'/site1/backup.zip');
        $this->assertEquals('backup data', file_get_contents($this->tempDir.'/site1/backup.zip'));

        unlink($source);
    }

    #[Test]
    public function upload_creates_nested_directories(): void
    {
        $source = $this->createTempFile();

        $this->driver->upload($source, 'deep/nested/path/file.zip');

        $this->assertFileExists($this->tempDir.'/deep/nested/path/file.zip');

        unlink($source);
    }

    #[Test]
    public function download_copies_file_from_destination(): void
    {
        // Create a file in the storage
        mkdir($this->tempDir.'/backups', 0755, true);
        file_put_contents($this->tempDir.'/backups/test.zip', 'zip content');

        $downloadPath = sys_get_temp_dir().'/ld_download_'.uniqid();

        $this->driver->download('backups/test.zip', $downloadPath);

        $this->assertFileExists($downloadPath);
        $this->assertEquals('zip content', file_get_contents($downloadPath));

        unlink($downloadPath);
    }

    #[Test]
    public function download_throws_when_file_not_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $this->driver->download('nonexistent.zip', '/tmp/out.zip');
    }

    #[Test]
    public function delete_removes_file(): void
    {
        file_put_contents($this->tempDir.'/to-delete.zip', 'data');
        $this->assertFileExists($this->tempDir.'/to-delete.zip');

        $this->driver->delete('to-delete.zip');

        $this->assertFileDoesNotExist($this->tempDir.'/to-delete.zip');
    }

    #[Test]
    public function delete_does_not_throw_for_missing_file(): void
    {
        // Should not throw
        $this->driver->delete('nonexistent.zip');

        $this->assertTrue(true);
    }

    #[Test]
    public function exists_returns_true_for_existing_file(): void
    {
        file_put_contents($this->tempDir.'/exists.zip', 'data');

        $this->assertTrue($this->driver->exists('exists.zip'));
    }

    #[Test]
    public function exists_returns_false_for_missing_file(): void
    {
        $this->assertFalse($this->driver->exists('missing.zip'));
    }

    #[Test]
    public function size_returns_file_size(): void
    {
        file_put_contents($this->tempDir.'/sized.zip', str_repeat('x', 1024));

        $this->assertEquals(1024, $this->driver->size('sized.zip'));
    }

    #[Test]
    public function size_throws_for_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $this->driver->size('missing.zip');
    }

    #[Test]
    public function list_returns_files_in_directory(): void
    {
        file_put_contents($this->tempDir.'/file1.zip', 'a');
        file_put_contents($this->tempDir.'/file2.zip', 'bb');
        mkdir($this->tempDir.'/subdir');

        $files = $this->driver->list('');

        $names = array_column($files, 'name');
        $this->assertContains('file1.zip', $names);
        $this->assertContains('file2.zip', $names);
        $this->assertContains('subdir', $names);

        // Check structure
        $subdir = collect($files)->firstWhere('name', 'subdir');
        $this->assertTrue($subdir['is_dir']);
    }

    #[Test]
    public function list_returns_empty_for_nonexistent_directory(): void
    {
        $this->assertEmpty($this->driver->list('nonexistent'));
    }

    #[Test]
    public function test_returns_true_for_writable_directory(): void
    {
        $this->assertTrue($this->driver->test());
    }

    #[Test]
    public function temporary_url_returns_null(): void
    {
        $this->assertNull($this->driver->temporaryUrl('any/path.zip'));
    }

    #[Test]
    public function list_folders_returns_empty_array(): void
    {
        $this->assertEmpty($this->driver->listFolders());
    }

    #[Test]
    public function upload_to_absolute_path_delegates_to_upload(): void
    {
        $source = $this->createTempFile('abs content');

        $this->driver->uploadToAbsolutePath($source, 'absolute/path/file.zip');

        $this->assertFileExists($this->tempDir.'/absolute/path/file.zip');

        unlink($source);
    }
}
