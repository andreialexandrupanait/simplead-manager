<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

/**
 * Outcome of a completed hardened multipart upload. Everything the orchestrator
 * needs to write manifest.json / checksums.json entries for this object.
 */
final class MultipartUploadResult
{
    /**
     * @param  list<array{part_number: int, etag: string, sha256: string, size: int}>  $parts
     */
    public function __construct(
        public readonly string $key,
        public readonly int $size,
        public readonly string $sha256,
        public readonly string $etag,
        public readonly array $parts,
        public readonly int $partsUploaded,
        public readonly int $partsResumed,
    ) {}

    public function partCount(): int
    {
        return count($this->parts);
    }
}
