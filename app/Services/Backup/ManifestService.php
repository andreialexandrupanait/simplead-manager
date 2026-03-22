<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\WordPressApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManifestService
{
    /**
     * Generate a file manifest from the WP site and store it in the backup's storage destination.
     * If a session token is provided, tries to retrieve the pre-collected manifest first
     * (collected during prepare-init, avoids re-scanning the filesystem).
     */
    public function generateAndStore(WordPressApiService $api, Backup $backup, $destination, ?string $sessionToken = null): void
    {
        $manifest = null;

        // Try pre-collected manifest from the backup session first
        if ($sessionToken) {
            try {
                $response = $api->request('POST', '/backup/session-manifest', [
                    'token' => $sessionToken,
                ], [], 30);

                if ($response->successful()) {
                    $data = $response->json();
                    if (! empty($data['success']) && ! empty($data['manifest'])) {
                        $manifest = $data['manifest'];
                        Log::info("Using pre-collected manifest for backup {$backup->id}: ".count($manifest).' files');
                    }
                }
            } catch (\Throwable $e) {
                Log::info("Pre-collected manifest not available for backup {$backup->id}: {$e->getMessage()}, falling back to full scan");
            }
        }

        // Fall back to full filesystem scan
        if ($manifest === null) {
            $response = $api->request('POST', '/backup/manifest', [], [], 300);

            if (! $response->successful()) {
                throw new \RuntimeException('Manifest generation failed: HTTP '.$response->status());
            }

            $data = $response->json();
            if (empty($data['success'])) {
                throw new \RuntimeException('Manifest generation failed: '.($data['error']['message'] ?? 'Unknown error'));
            }

            $manifest = $data['manifest'] ?? [];
        }
        $totalFiles = count($manifest);

        // Compress manifest as gzipped JSON
        $jsonManifest = json_encode($manifest, JSON_UNESCAPED_SLASHES);
        $gzipped = gzencode($jsonManifest, 6);

        if ($gzipped === false) {
            throw new \RuntimeException('Failed to gzip manifest');
        }

        // Upload to storage
        $site = $backup->site;
        $manifestPath = $site->domain.'/manifests/manifest-'.$backup->id.'.json.gz';

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = tempnam($tempDir, 'manifest_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file for manifest upload');
        }
        file_put_contents($tempFile, $gzipped);

        try {
            $driver = StorageFactory::make($destination);
            $driver->upload($tempFile, $manifestPath);
        } finally {
            @unlink($tempFile);
        }

        // Update backup record
        $backup->update([
            'manifest_path' => $manifestPath,
            'files_total_count' => $totalFiles,
        ]);

        Log::info("Manifest generated for backup {$backup->id}: {$totalFiles} files, stored at {$manifestPath}");
    }

    /**
     * Retrieve and decompress a manifest from storage.
     *
     * @return array Array of manifest entries [{p, s, m}, ...]
     */
    public function retrieve(Backup $backup): array
    {
        if (! $backup->manifest_path) {
            throw new \RuntimeException("Backup {$backup->id} has no manifest path");
        }

        $destination = $backup->storageDestination;
        if (! $destination) {
            throw new \RuntimeException("Backup {$backup->id} has no storage destination");
        }

        $driver = StorageFactory::make($destination);
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = tempnam($tempDir, 'manifest_dl_');
        if ($tempFile === false) {
            throw new \RuntimeException("Failed to create temporary file for manifest download (backup {$backup->id})");
        }

        try {
            $driver->download($backup->manifest_path, $tempFile);
            $gzipped = file_get_contents($tempFile);
        } finally {
            @unlink($tempFile);
        }

        $json = gzdecode($gzipped);
        if ($json === false) {
            throw new \RuntimeException("Failed to decompress manifest for backup {$backup->id}");
        }

        $manifest = json_decode($json, true);
        if (! is_array($manifest)) {
            throw new \RuntimeException("Invalid manifest JSON for backup {$backup->id}");
        }

        return $manifest;
    }

    /**
     * Find the latest completed backup with a manifest for a given site.
     */
    public function findLatestManifestBackup(int $siteId): ?Backup
    {
        return Backup::where('site_id', $siteId)
            ->where('status', 'completed')
            ->whereNotNull('manifest_path')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Walk parent_backup_id chain to find the root full backup.
     * Uses a single recursive CTE query instead of N+1 queries.
     */
    public function findChainRoot(Backup $backup): Backup
    {
        $chain = $this->loadAncestorChain($backup->id);

        return $chain->first() ?? $backup;
    }

    /**
     * Get the full chain of backups for restore: [full, inc1, inc2, ...target]
     * Uses a single recursive CTE query instead of N+1 queries.
     *
     * @return Backup[] Ordered from full backup to the target incremental
     */
    public function getChain(Backup $backup): array
    {
        if (! $backup->isIncremental()) {
            return [$backup];
        }

        return $this->loadAncestorChain($backup->id)->values()->all();
    }

    /**
     * Load the full ancestor chain for a backup using a recursive CTE.
     * Returns backups ordered from root (full) to the given backup.
     * One query regardless of chain depth.
     */
    private function loadAncestorChain(int $backupId)
    {
        $rows = DB::select('
            WITH RECURSIVE ancestor_chain AS (
                SELECT id, parent_backup_id, 0 AS depth
                FROM backups
                WHERE id = ?
                UNION ALL
                SELECT b.id, b.parent_backup_id, ac.depth + 1
                FROM backups b
                INNER JOIN ancestor_chain ac ON b.id = ac.parent_backup_id
                WHERE ac.depth < 100
            )
            SELECT id FROM ancestor_chain ORDER BY depth DESC
        ', [$backupId]);

        $orderedIds = collect($rows)->pluck('id')->toArray();

        if (empty($orderedIds)) {
            return collect();
        }

        $backups = Backup::whereIn('id', $orderedIds)->get()->keyBy('id');

        return collect($orderedIds)->map(fn ($id) => $backups->get($id))->filter();
    }

    /**
     * Clean up manifest file from storage when deleting a backup.
     */
    public function deleteManifest(Backup $backup): void
    {
        if (! $backup->manifest_path || ! $backup->storageDestination) {
            return;
        }

        try {
            $driver = StorageFactory::make($backup->storageDestination);
            $driver->delete($backup->manifest_path);
        } catch (\Throwable $e) {
            Log::warning("Failed to delete manifest for backup {$backup->id}: {$e->getMessage()}");
        }
    }
}
