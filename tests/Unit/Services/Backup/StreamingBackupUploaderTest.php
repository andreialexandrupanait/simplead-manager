<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Models\StorageDestination;
use App\Services\Backup\StreamingBackupUploader;
use PHPUnit\Framework\TestCase;

class StreamingBackupUploaderTest extends TestCase
{
    private string $storageDir;

    private string $localDir;

    private StreamingBackupUploader $uploader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDir = sys_get_temp_dir().'/test-streaming-storage-'.uniqid();
        $this->localDir = sys_get_temp_dir().'/test-streaming-local-'.uniqid();
        mkdir($this->storageDir, 0755, true);
        mkdir($this->localDir, 0755, true);

        $destination = new StorageDestination;
        $destination->type = 'local';
        $destination->config = ['path' => $this->storageDir];

        $this->uploader = new StreamingBackupUploader($destination, 'backups/site-1/2026-05-14');
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->storageDir);
        $this->cleanDir($this->localDir);
        parent::tearDown();
    }

    private function cleanDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    private function createLocalFile(string $name, string $content = 'test data'): string
    {
        $path = $this->localDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_add_file_uploads_and_records_entry(): void
    {
        $localPath = $this->createLocalFile('chunk_0.gz', 'chunk-data');

        $entry = $this->uploader->addFile($localPath, 'chunk_0.gz');

        $this->assertSame('chunk_0.gz', $entry['name']);
        $this->assertSame(strlen('chunk-data'), $entry['size']);
        $this->assertSame(hash('sha256', 'chunk-data'), $entry['sha256']);
        $this->assertCount(1, $this->uploader->entries());
    }

    public function test_add_file_deletes_local_by_default(): void
    {
        $localPath = $this->createLocalFile('chunk_0.gz');

        $this->uploader->addFile($localPath, 'chunk_0.gz');

        $this->assertFileDoesNotExist($localPath);
    }

    public function test_add_file_keeps_local_when_flag_false(): void
    {
        $localPath = $this->createLocalFile('chunk_0.gz');

        $this->uploader->addFile($localPath, 'chunk_0.gz', false);

        $this->assertFileExists($localPath);
    }

    public function test_add_file_throws_for_missing_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/local file not found/');

        $this->uploader->addFile('/tmp/nonexistent-'.uniqid(), 'chunk.gz');
    }

    public function test_total_bytes_accumulates(): void
    {
        $this->createLocalFile('a.gz', 'aaa');
        $this->createLocalFile('b.gz', 'bbbbb');

        $this->uploader->addFile($this->localDir.'/a.gz', 'a.gz');
        $this->uploader->addFile($this->localDir.'/b.gz', 'b.gz');

        $this->assertSame(8, $this->uploader->totalBytes());
        $this->assertCount(2, $this->uploader->entries());
    }

    public function test_rollback_deletes_uploaded_files(): void
    {
        $this->createLocalFile('a.gz', 'data-a');
        $this->createLocalFile('b.gz', 'data-b');

        $this->uploader->addFile($this->localDir.'/a.gz', 'a.gz');
        $this->uploader->addFile($this->localDir.'/b.gz', 'b.gz');

        $remotePath = $this->storageDir.'/backups/site-1/2026-05-14/a.gz';
        $this->assertFileExists($remotePath);

        $this->uploader->rollback();

        $this->assertFileDoesNotExist($remotePath);
        $this->assertEmpty($this->uploader->entries());
    }

    public function test_remote_prefix_and_destination_getters(): void
    {
        $this->assertSame('backups/site-1/2026-05-14', $this->uploader->remotePrefix());
        $this->assertSame('local', $this->uploader->destination()->type);
    }

    public function test_upload_manifest_writes_json_file(): void
    {
        $this->uploader->uploadManifest([
            'version' => 3,
            'files' => [['name' => 'chunk_0.gz', 'size' => 100, 'sha256' => 'abc']],
        ]);

        $manifestPath = $this->storageDir.'/backups/site-1/2026-05-14/manifest.json';
        $this->assertFileExists($manifestPath);

        $content = json_decode(file_get_contents($manifestPath), true);
        $this->assertSame(3, $content['version']);
    }
}
