<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

use App\Backup\V2\Plugin\DownloadedChunk;
use App\Backup\V2\Plugin\PluginClient;
use RuntimeException;
use ZipArchive;

/**
 * In-process PluginClient for the deterministic empty-chunk contract test. It
 * feeds the REAL BackupRunner a files-only plan where one chunk materialises 0
 * files (empty=true, chunk_size=0), and records which chunks were downloaded — so
 * the test can assert the empty chunk is skipped (never pulled, never uploaded,
 * never in the manifest) while the real object still lands on MinIO.
 */
final class FakePluginClient implements PluginClient
{
    /** @var list<int> */
    public array $downloadedIndexes = [];

    /** @var list<string> */
    private array $tempFiles = [];

    /**
     * @param  list<int>  $emptyIndexes  chunk indexes that materialise 0 files
     */
    public function __construct(
        private readonly int $chunkCount,
        private readonly array $emptyIndexes,
    ) {}

    public function capabilities(): array
    {
        return [
            'plugin' => ['version' => '0.5.0'],
            'wordpress' => ['version' => '6.9'],
            'php' => ['version' => PHP_VERSION],
            'extensions' => ['zip' => true],
            'disk' => ['temp_dir' => '/tmp/sam_backup', 'temp_writable' => true, 'free_bytes' => 8 * 1024 ** 3],
            'database' => ['consistent_snapshot_supported' => true, 'server_version' => 'fake', 'engine_family' => 'mysql', 'non_innodb_tables' => []],
        ];
    }

    public function filesInventory(string $sessionId, array $rules, array $options = []): array
    {
        return [
            'ok' => true,
            'session_id' => $sessionId,
            'exclusion_policy_hash' => 'fake-excl-hash',
            'total_files' => ($this->chunkCount - count($this->emptyIndexes)) * 2,
            'total_bytes' => 0,
            'excluded_files' => 0,
            'excluded_bytes' => 0,
            'chunk_count' => $this->chunkCount,
            'compression' => 'store',
        ];
    }

    public function dbDump(string $sessionId, array $params = []): array
    {
        throw new RuntimeException('FakePluginClient is files-only; dbDump must not be called.');
    }

    public function dbChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        throw new RuntimeException('FakePluginClient is files-only; dbChunkDownload must not be called.');
    }

    public function filesChunkExec(string $sessionId, int $chunkIndex): array
    {
        if (in_array($chunkIndex, $this->emptyIndexes, true)) {
            return [
                'ok' => true, 'session_id' => $sessionId, 'chunk_index' => $chunkIndex,
                'empty' => true, 'skipped' => false, 'chunk_size' => 0, 'sha256' => null,
                'file_count' => 0, 'files' => [],
            ];
        }

        return [
            'ok' => true, 'session_id' => $sessionId, 'chunk_index' => $chunkIndex,
            'empty' => false, 'skipped' => false, 'chunk_size' => 1,
            'file_count' => 1,
            'files' => [
                ['p' => "fake/chunk_{$chunkIndex}/file.dat", 's' => 8, 'sha256' => hash('sha256', "chunk{$chunkIndex}"), 'chunk_index' => $chunkIndex],
            ],
        ];
    }

    public function filesChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        if (in_array($chunkIndex, $this->emptyIndexes, true)) {
            throw new RuntimeException("Empty chunk {$chunkIndex} must never be downloaded.");
        }

        $this->downloadedIndexes[] = $chunkIndex;

        $path = (string) tempnam(sys_get_temp_dir(), 'fakechunk_');
        $this->tempFiles[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("fake/chunk_{$chunkIndex}/file.dat", "chunk-{$chunkIndex}");
        $zip->close();

        return new DownloadedChunk(
            path: $path,
            chunkIndex: $chunkIndex,
            size: (int) filesize($path),
            sha256: (string) hash_file('sha256', $path),
            reportedSha256: (string) hash_file('sha256', $path),
            httpStatus: 200,
            contentType: 'application/zip',
        );
    }

    public function cleanup(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
    }
}
