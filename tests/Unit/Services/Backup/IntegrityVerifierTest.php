<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\IntegrityVerifier;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class IntegrityVerifierTest extends TestCase
{
    private string $tmpDir;

    private IntegrityVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/integrity_test_'.uniqid();
        mkdir($this->tmpDir, 0700, true);
        $this->verifier = new IntegrityVerifier;
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_valid_full_backup_archive_passes(): void
    {
        $archive = $this->buildArchive(includeFiles: true, formatVersion: 2);
        $sha = hash_file('sha256', $archive);

        $result = $this->verifier->verifyArchive($archive, $sha);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertTrue($result['checks']['outer_zip_consistent']);
        $this->assertTrue($result['checks']['meta_ok']);
        $this->assertTrue($result['checks']['database']['ok']);
        $this->assertSame(2, $result['checks']['files']['entry_count']);
    }

    public function test_db_only_backup_passes_with_no_files(): void
    {
        $archive = $this->buildArchive(includeFiles: false);
        $sha = hash_file('sha256', $archive);

        $result = $this->verifier->verifyArchive($archive, $sha);

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['checks']['files']['entry_count']);
    }

    public function test_sha256_mismatch_fails(): void
    {
        $archive = $this->buildArchive(includeFiles: false);

        $result = $this->verifier->verifyArchive($archive, str_repeat('0', 64));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sha256 mismatch', $result['message']);
    }

    public function test_missing_meta_fails(): void
    {
        $archive = $this->buildArchive(includeFiles: false, withMeta: false);
        $sha = hash_file('sha256', $archive);

        $result = $this->verifier->verifyArchive($archive, $sha);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('backup-meta.json missing', $result['message']);
    }

    public function test_corrupted_archive_fails(): void
    {
        $archive = $this->buildArchive(includeFiles: false);
        // Corrupt the central directory area by appending garbage at end
        file_put_contents($archive, str_repeat('X', 50), FILE_APPEND);

        $sha = hash_file('sha256', $archive);
        $result = $this->verifier->verifyArchive($archive, $sha);

        $this->assertFalse($result['ok']);
    }

    public function test_truncated_db_dump_fails(): void
    {
        $archive = $this->buildArchive(includeFiles: false, brokenDb: true);
        $sha = hash_file('sha256', $archive);

        $result = $this->verifier->verifyArchive($archive, $sha);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('database dump invalid', $result['message']);
    }

    private function buildArchive(bool $includeFiles, int $formatVersion = 2, bool $withMeta = true, bool $brokenDb = false): string
    {
        // Build a realistic db dump
        $dbContent = $brokenDb
            ? "garbage no header\n"
            : "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nCREATE TABLE foo (id int);\nINSERT INTO foo VALUES (1);\nSET FOREIGN_KEY_CHECKS = 1;\n";
        $dbPath = $this->tmpDir.'/database.sql.gz';
        $gz = gzopen($dbPath, 'wb');
        gzwrite($gz, $dbContent);
        gzclose($gz);

        // Build inner chunk zips
        $chunkPaths = [];
        $chunkNames = [];
        if ($includeFiles) {
            for ($i = 0; $i < 2; $i++) {
                $chunkPath = $this->tmpDir."/chunk_{$i}.zip";
                $chunk = new ZipArchive;
                $chunk->open($chunkPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                $chunk->addFromString("file_{$i}.txt", "content {$i}");
                $chunk->close();
                $chunkPaths[] = $chunkPath;
                $chunkNames[] = "files_chunk_{$i}.zip";
            }
        }

        // Build outer archive
        $archivePath = $this->tmpDir.'/backup.zip';
        $outer = new ZipArchive;
        $outer->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $outer->addFile($dbPath, 'database.sql.gz');
        $outer->setCompressionName('database.sql.gz', ZipArchive::CM_STORE);
        foreach ($chunkPaths as $idx => $cp) {
            $outer->addFile($cp, $chunkNames[$idx]);
            $outer->setCompressionName($chunkNames[$idx], ZipArchive::CM_STORE);
        }
        if ($withMeta) {
            $meta = [
                'site_url' => 'https://example.test',
                'type' => $includeFiles ? 'full' : 'database',
                'created_at' => '2026-05-10T12:00:00+00:00',
                'trigger' => 'manual',
            ];
            if ($includeFiles) {
                $meta['format_version'] = $formatVersion;
                $meta['chunk_files'] = $chunkNames;
            }
            $outer->addFromString('backup-meta.json', json_encode($meta));
        }
        $outer->close();

        return $archivePath;
    }
}
