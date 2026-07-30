<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

/**
 * In-memory MultipartProgressStore for pure-logic tests and dry runs.
 *
 * Models exactly the shape persisted by BackupSessionProgressStore so a test that
 * exercises resume against MinIO with this store proves the same resume path the
 * DB-backed store drives in production.
 */
final class InMemoryProgressStore implements MultipartProgressStore
{
    /**
     * @var array<string, array{upload_id: ?string, parts: array<int, array{part_number: int, etag: string, sha256: string, size: int}>}>
     */
    private array $state = [];

    public function getUploadId(string $key): ?string
    {
        return $this->state[$key]['upload_id'] ?? null;
    }

    public function putUploadId(string $key, string $uploadId): void
    {
        $this->state[$key]['upload_id'] = $uploadId;
        $this->state[$key]['parts'] ??= [];
    }

    public function confirmedParts(string $key): array
    {
        $parts = array_values($this->state[$key]['parts'] ?? []);

        usort($parts, static fn (array $a, array $b): int => $a['part_number'] <=> $b['part_number']);

        return $parts;
    }

    public function confirmPart(string $key, array $part): void
    {
        // Keyed by part_number → idempotent replace, never duplicate.
        $this->state[$key]['parts'][$part['part_number']] = $part;
        $this->state[$key]['upload_id'] ??= null;
    }

    public function clear(string $key): void
    {
        unset($this->state[$key]);
    }
}
