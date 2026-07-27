<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

/**
 * Persists multipart-upload progress so an interrupted upload can resume from the
 * last confirmed part instead of restarting the whole object.
 *
 * For each object key it holds:
 *   - the S3 UploadId (so the SAME multipart upload is reused on resume), and
 *   - the list of confirmed parts: {part_number, etag, sha256, size}.
 *
 * Implementations: InMemoryProgressStore (pure-logic tests) and
 * BackupSessionProgressStore (persists into BackupSession.confirmed_parts jsonb).
 */
interface MultipartProgressStore
{
    /**
     * The persisted UploadId for this key, or null if none has been started.
     */
    public function getUploadId(string $key): ?string;

    /**
     * Record the UploadId for a freshly initiated multipart upload.
     */
    public function putUploadId(string $key, string $uploadId): void;

    /**
     * Confirmed parts for this key, ordered by part_number ascending.
     *
     * @return list<array{part_number: int, etag: string, sha256: string, size: int}>
     */
    public function confirmedParts(string $key): array;

    /**
     * Durably record one confirmed part. MUST be idempotent on part_number:
     * confirming the same part_number twice replaces, never duplicates.
     *
     * @param  array{part_number: int, etag: string, sha256: string, size: int}  $part
     */
    public function confirmPart(string $key, array $part): void;

    /**
     * Drop all progress for this key (called after a clean complete or a clean
     * abort, so a stale UploadId is never reused).
     */
    public function clear(string $key): void;
}
