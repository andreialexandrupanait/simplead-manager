<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\Log;

/**
 * Push individual chunks to the storage destination as they become available
 * locally, and delete the local copy as soon as the upload returns. Keeps disk
 * usage bounded to the size of the largest single chunk regardless of overall
 * backup size.
 *
 * Failure of a single addFile() throws — the orchestrating job decides whether
 * to retry the whole backup or salvage a partial. Manifest is uploaded LAST so
 * a half-uploaded backup is detectable: missing manifest.json = incomplete.
 */
class StreamingBackupUploader
{
    /** @var list<array{name: string, size: int, sha256: string}> */
    private array $entries = [];

    public function __construct(
        private readonly StorageDestination $destination,
        private readonly string $remotePrefix,
    ) {}

    /**
     * Upload a local file under the backup's remote prefix and delete the local
     * copy on success. Records the entry in the manifest list.
     *
     * @return array{name: string, size: int, sha256: string}
     */
    public function addFile(string $localPath, string $remoteName, bool $deleteLocal = true): array
    {
        if (! is_file($localPath)) {
            throw new \RuntimeException("addFile: local file not found: {$localPath}");
        }

        $size = (int) filesize($localPath);
        $sha256 = hash_file('sha256', $localPath);
        if ($sha256 === false) {
            throw new \RuntimeException("addFile: hash_file failed for {$localPath}");
        }

        $remotePath = $this->remotePrefix.'/'.ltrim($remoteName, '/');
        $driver = StorageFactory::make($this->destination);
        $driver->upload($localPath, $remotePath);

        if ($deleteLocal) {
            @unlink($localPath);
        }

        $entry = [
            'name' => $remoteName,
            'size' => $size,
            'sha256' => $sha256,
        ];
        $this->entries[] = $entry;

        return $entry;
    }

    /**
     * Encode + upload the manifest as the last step. Use rollback() to remove
     * already-uploaded files if you decide the backup is invalid before this call.
     */
    public function uploadManifest(array $manifestBody): void
    {
        $tempPath = sys_get_temp_dir().'/manifest-'.uniqid().'.json';
        file_put_contents($tempPath, BackupManifestV3::encode($manifestBody));

        try {
            $driver = StorageFactory::make($this->destination);
            $driver->upload($tempPath, $this->remotePrefix.'/'.BackupManifestV3::MANIFEST_FILENAME);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Best-effort cleanup of files uploaded so far. Used when the orchestrating
     * job decides to abort mid-stream. Per-file failures are logged, not raised.
     */
    public function rollback(): void
    {
        $driver = StorageFactory::make($this->destination);
        foreach ($this->entries as $entry) {
            try {
                $driver->delete($this->remotePrefix.'/'.$entry['name']);
            } catch (\Throwable $e) {
                Log::warning("StreamingBackupUploader rollback: failed to delete {$entry['name']}: {$e->getMessage()}");
            }
        }
        $this->entries = [];
    }

    /**
     * @return list<array{name: string, size: int, sha256: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function totalBytes(): int
    {
        return array_sum(array_column($this->entries, 'size'));
    }

    public function remotePrefix(): string
    {
        return $this->remotePrefix;
    }

    public function destination(): StorageDestination
    {
        return $this->destination;
    }
}
