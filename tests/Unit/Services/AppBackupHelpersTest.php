<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AppBackup\AppBackupHelpers;
use PHPUnit\Framework\TestCase;

class AppBackupHelpersTest extends TestCase
{
    private object $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new class
        {
            use AppBackupHelpers;

            public function callExec(string $command): string
            {
                return $this->exec($command);
            }

            public function callCleanupDir(string $dir): void
            {
                $this->cleanupDir($dir);
            }
        };
    }

    public function test_exec_returns_output_on_success(): void
    {
        $result = $this->helper->callExec('echo "hello"');
        $this->assertSame('hello', $result);
    }

    public function test_exec_throws_on_failure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Command failed/');
        $this->helper->callExec('false');
    }

    public function test_cleanup_dir_removes_directory(): void
    {
        $tempDir = sys_get_temp_dir().'/test-cleanup-'.uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir.'/test.txt', 'data');
        mkdir($tempDir.'/subdir', 0755, true);
        file_put_contents($tempDir.'/subdir/nested.txt', 'nested');

        $this->assertDirectoryExists($tempDir);

        $this->helper->callCleanupDir($tempDir);

        $this->assertDirectoryDoesNotExist($tempDir);
    }

    public function test_cleanup_dir_handles_nonexistent_dir(): void
    {
        // Should not throw
        $this->helper->callCleanupDir('/tmp/nonexistent-dir-'.uniqid());
        $this->assertTrue(true);
    }
}
