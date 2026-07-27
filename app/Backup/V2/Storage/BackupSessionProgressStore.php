<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

use App\Backup\V2\Models\BackupSession;

/**
 * MultipartProgressStore backed by BackupSession.confirmed_parts (jsonb).
 *
 * Persisted shape (per object key):
 *
 *   confirmed_parts = {
 *     "<s3 key>": {
 *       "upload_id": "<UploadId>",
 *       "parts": [ {"part_number":1,"etag":"...","sha256":"...","size":123}, ... ]
 *     },
 *     ...
 *   }
 *
 * Each mutation reloads confirmed_parts from the model, merges, and saves — so a
 * worker that dies mid-upload leaves a durable record the next attempt resumes
 * from. Multiple object keys (database/, files/, manifest…) share one session row.
 */
final class BackupSessionProgressStore implements MultipartProgressStore
{
    public function __construct(private readonly BackupSession $session) {}

    public function getUploadId(string $key): ?string
    {
        $entry = $this->entry($key);

        return isset($entry['upload_id']) && $entry['upload_id'] !== ''
            ? (string) $entry['upload_id']
            : null;
    }

    public function putUploadId(string $key, string $uploadId): void
    {
        $all = $this->all();
        $all[$key]['upload_id'] = $uploadId;
        $all[$key]['parts'] ??= [];
        $this->persist($all);
    }

    public function confirmedParts(string $key): array
    {
        $parts = array_values($this->entry($key)['parts'] ?? []);

        $normalised = array_map(static fn (array $p): array => [
            'part_number' => (int) $p['part_number'],
            'etag' => (string) $p['etag'],
            'sha256' => (string) $p['sha256'],
            'size' => (int) $p['size'],
        ], $parts);

        usort($normalised, static fn (array $a, array $b): int => $a['part_number'] <=> $b['part_number']);

        return $normalised;
    }

    public function confirmPart(string $key, array $part): void
    {
        $all = $this->all();
        $existing = $all[$key]['parts'] ?? [];

        // Idempotent replace on part_number.
        $existing = array_values(array_filter(
            $existing,
            static fn (array $p): bool => (int) $p['part_number'] !== $part['part_number'],
        ));
        $existing[] = $part;

        $all[$key]['parts'] = $existing;
        $all[$key]['upload_id'] ??= null;
        $this->persist($all);
    }

    public function clear(string $key): void
    {
        $all = $this->all();
        unset($all[$key]);
        $this->persist($all);
    }

    /**
     * @return array<string, array{upload_id?: ?string, parts?: array<int, array<string, mixed>>}>
     */
    private function all(): array
    {
        $this->session->refresh();

        return $this->session->confirmed_parts ?? [];
    }

    /**
     * @return array{upload_id?: ?string, parts?: array<int, array<string, mixed>>}
     */
    private function entry(string $key): array
    {
        return $this->all()[$key] ?? [];
    }

    /**
     * @param  array<string, mixed>  $all
     */
    private function persist(array $all): void
    {
        $this->session->confirmed_parts = $all;
        $this->session->save();
    }
}
