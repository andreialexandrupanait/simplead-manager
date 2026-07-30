<?php

declare(strict_types=1);

namespace App\Backup\V2\Plugin;

/**
 * A chunk (file zip or DB segment) pulled from the plugin to a local temp file.
 *
 * Pull-and-free: the plugin frees its own copy when `delete=1` was requested, so
 * the manager holds exactly one copy at a time and temp stays bounded by the
 * largest single chunk (never the whole backup).
 */
final class DownloadedChunk
{
    public function __construct(
        public readonly string $path,
        public readonly int $chunkIndex,
        public readonly int $size,
        public readonly string $sha256,
        public readonly ?string $reportedSha256,
        public readonly int $httpStatus,
        public readonly string $contentType,
    ) {}

    /**
     * The plugin streamed a real payload (2xx) whose locally-computed sha256
     * matches the sha256 it reported in the X-SAM-Chunk-Sha256 header.
     */
    public function integrityOk(): bool
    {
        return $this->httpStatus >= 200
            && $this->httpStatus < 300
            && $this->size > 0
            && ($this->reportedSha256 === null || hash_equals($this->reportedSha256, $this->sha256));
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
