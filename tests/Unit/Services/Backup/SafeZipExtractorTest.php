<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\SafeZipExtractor;
use Tests\TestCase;
use ZipArchive;

class SafeZipExtractorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/safe-zip-'.uniqid();
        mkdir($this->workDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->workDir);
        parent::tearDown();
    }

    public function test_rejects_parent_traversal_entries(): void
    {
        $this->assertFalse(SafeZipExtractor::isSafeEntryName('../evil.txt'));
        $this->assertFalse(SafeZipExtractor::isSafeEntryName('../../etc/passwd'));
        $this->assertFalse(SafeZipExtractor::isSafeEntryName('files/../../evil'));
        $this->assertFalse(SafeZipExtractor::isSafeEntryName('a/b/../../../c'));
        $this->assertFalse(SafeZipExtractor::isSafeEntryName('..'));
    }

    public function test_rejects_absolute_and_drive_paths(): void
    {
        $this->assertFalse(SafeZipExtractor::isSafeEntryName('/etc/passwd'));
        $this->assertFalse(SafeZipExtractor::isSafeEntryName('C:\\Windows\\system32'));
        $this->assertFalse(SafeZipExtractor::isSafeEntryName('..\\..\\evil'));
        $this->assertFalse(SafeZipExtractor::isSafeEntryName(''));
    }

    public function test_accepts_normal_entries(): void
    {
        $this->assertTrue(SafeZipExtractor::isSafeEntryName('wp-content/plugins/foo/index.php'));
        $this->assertTrue(SafeZipExtractor::isSafeEntryName('database.sql.gz'));
        $this->assertTrue(SafeZipExtractor::isSafeEntryName('files/wp-admin/index.php'));
        // A `..` embedded in a filename (not a path segment) is fine.
        $this->assertTrue(SafeZipExtractor::isSafeEntryName('report..txt'));
    }

    public function test_traversal_entry_does_not_escape_target_dir(): void
    {
        $zipPath = $this->workDir.'/malicious.zip';
        $target = $this->workDir.'/extract';
        mkdir($target, 0700, true);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('good.txt', 'safe content');
        $zip->addFromString('../escaped.txt', 'malicious content');
        $zip->close();

        $reader = new ZipArchive;
        $reader->open($zipPath);
        $skipped = SafeZipExtractor::extractTo($reader, $target);
        $reader->close();

        $this->assertSame(1, $skipped, 'The traversal entry should be skipped.');
        $this->assertFileExists($target.'/good.txt');
        // The traversal target — one level above $target — must NOT exist.
        $this->assertFileDoesNotExist($this->workDir.'/escaped.txt');
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
