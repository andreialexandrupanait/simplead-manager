<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

use App\Backup\V2\Plugin\DownloadedChunk;
use App\Backup\V2\Plugin\PluginClient;

/**
 * Transparent decorator that records every call made to the wrapped PluginClient.
 * Used by the resume test to PROVE an already-confirmed chunk is never pulled a
 * second time (no double download/upload across a crash+resume).
 */
final class RecordingPluginClient implements PluginClient
{
    /** @var list<array{kind:string, index:int}> */
    public array $downloads = [];

    public function __construct(private readonly PluginClient $inner) {}

    public function capabilities(): array
    {
        return $this->inner->capabilities();
    }

    public function filesInventory(string $sessionId, array $rules, array $options = []): array
    {
        return $this->inner->filesInventory($sessionId, $rules, $options);
    }

    public function dbDump(string $sessionId, array $params = []): array
    {
        return $this->inner->dbDump($sessionId, $params);
    }

    public function dbChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        $this->downloads[] = ['kind' => 'database', 'index' => $chunkIndex];

        return $this->inner->dbChunkDownload($sessionId, $chunkIndex, $deleteAfter);
    }

    public function filesChunkExec(string $sessionId, int $chunkIndex): array
    {
        return $this->inner->filesChunkExec($sessionId, $chunkIndex);
    }

    public function filesChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        $this->downloads[] = ['kind' => 'files', 'index' => $chunkIndex];

        return $this->inner->filesChunkDownload($sessionId, $chunkIndex, $deleteAfter);
    }

    /**
     * How many times a given (kind,index) chunk was pulled.
     */
    public function downloadCount(string $kind, int $index): int
    {
        return count(array_filter(
            $this->downloads,
            static fn (array $d): bool => $d['kind'] === $kind && $d['index'] === $index,
        ));
    }
}
