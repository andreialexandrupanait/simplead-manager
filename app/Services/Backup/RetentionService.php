<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageDriver;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetentionService
{
    /**
     * Apply chain-aware retention policy for a site's backups.
     */
    public function apply(Site $site, StorageDestination $destination): void
    {
        $config = $site->backupConfig;
        if (! $config) {
            return;
        }

        // Chain-aware retention: group backups into chains (full + incrementals)
        $query = Backup::where('site_id', $site->id)
            ->where('status', BackupStatus::Completed)
            ->where('is_locked', false)
            ->orderByDesc('created_at');

        // For days-based retention, only load backups older than the cutoff
        $cutoff = null;
        if ($config->retention_type === 'days') {
            $cutoff = now()->subDays($config->retention_value);
            $query->where('created_at', '<', $cutoff);
        }

        $allBackups = $query->get();

        // Group into chains: full backups (no parent) with their incrementals
        $chains = [];
        $fullBackups = $allBackups->whereNull('parent_backup_id');
        $incrementalByParent = $allBackups->whereNotNull('parent_backup_id')->groupBy('parent_backup_id');

        foreach ($fullBackups as $full) {
            $chain = collect([$full]);
            if (isset($incrementalByParent[$full->id])) {
                $chain = $chain->merge($incrementalByParent[$full->id]);
            }
            $chains[] = $chain;
        }

        // Include orphaned incrementals as their own chains
        $parentIds = $fullBackups->pluck('id')->toArray();
        foreach ($incrementalByParent as $parentId => $incrementals) {
            if (! in_array($parentId, $parentIds)) {
                $chains[] = $incrementals;
            }
        }

        // Sort chains by newest backup (descending)
        usort($chains, fn ($a, $b) => $b->first()->created_at <=> $a->first()->created_at);

        // Determine which chains to delete
        $chainsToDelete = [];
        if ($config->retention_type === 'count') {
            $chainsToDelete = array_slice($chains, $config->retention_value);
        } else {
            // For days-based with pre-filtered query, all loaded chains are candidates
            foreach ($chains as $chain) {
                if ($chain->first()->created_at < $cutoff) {
                    $chainsToDelete[] = $chain;
                }
            }
        }

        foreach ($chainsToDelete as $chain) {
            foreach ($chain as $oldBackup) {
                $this->deleteBackup($oldBackup);
            }
        }

        // Clean up orphaned incrementals whose parent was deleted (FK set to NULL)
        $this->cleanupOrphans($site);
    }

    /**
     * Delete a single backup record and its associated storage files in every replica.
     * If ANY replica delete fails, the DB row is preserved so the next retention pass
     * can retry. The successfully-deleted destinations are removed from `replicas[]`
     * to avoid double-decrementing used_bytes on retry.
     */
    private function deleteBackup(Backup $backup): void
    {
        $targets = $this->collectReplicaTargets($backup);
        $allSucceeded = true;
        $remainingReplicas = $backup->replicas ?? [];
        $primaryDeleted = false;

        foreach ($targets as $target) {
            $destination = StorageDestination::find($target['destination_id']);
            if (! $destination) {
                // Destination was deleted entirely — nothing to clean up there
                $remainingReplicas = $this->dropReplica($remainingReplicas, $target['destination_id']);
                if ($target['is_primary']) {
                    $primaryDeleted = true;
                }

                continue;
            }

            try {
                $driver = StorageFactory::make($destination);

                // multipart-v3: target['remote_path'] is a prefix containing many files.
                // Enumerate + delete each. v2-zip / v3-zip: single file path + sidecar.
                if ($backup->format === BackupManifestV3::FORMAT) {
                    $this->deleteMultipartPrefix($driver, $target['remote_path']);
                } else {
                    $driver->delete($target['remote_path']);
                    // v2-zip & v3-zip have a sidecar metadata.json next to the .zip
                    try {
                        $driver->delete($target['remote_path'].\App\Services\Backup\BackupSidecarMetadata::SUFFIX);
                    } catch (\Throwable $sidecarErr) {
                        // sidecar may not exist for older v2-zip backups — silently ignore
                    }
                }

                $destination->decrement('used_bytes', max(0, $backup->file_size ?? 0));

                if ($backup->manifest_path && $target['is_primary']) {
                    try {
                        $driver->delete($backup->manifest_path);
                    } catch (\Throwable $e) {
                        Log::warning("Failed to delete manifest for backup {$backup->id}: {$e->getMessage()}");
                    }
                }

                $remainingReplicas = $this->dropReplica($remainingReplicas, $target['destination_id']);
                if ($target['is_primary']) {
                    $primaryDeleted = true;
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to delete backup {$backup->id} replica from destination {$destination->id}: {$e->getMessage()}");
                $allSucceeded = false;
            }
        }

        if ($allSucceeded) {
            try {
                $backup->delete();
            } catch (\Exception $e) {
                Log::warning("Failed to delete backup row {$backup->id}", [
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                ]);
            }

            return;
        }

        // Partial success — keep the row but reflect what was successfully purged
        $update = ['replicas' => array_values($remainingReplicas)];
        if ($primaryDeleted) {
            $update['file_path'] = null;
            $update['storage_destination_id'] = null;
        }
        $backup->update($update);
        Log::info("Backup {$backup->id} retention partial: row retained, will retry remaining replicas next cycle");
    }

    /**
     * @param  array<int, array<string, mixed>>  $replicas
     * @return array<int, array<string, mixed>>
     */
    private function dropReplica(array $replicas, int $destinationId): array
    {
        return array_filter($replicas, fn ($r) => (int) ($r['destination_id'] ?? 0) !== $destinationId);
    }

    /**
     * Delete every file under a multipart-v3 prefix. Tries the manifest first
     * (cheap, exact list); falls back to driver->list($prefix) if the manifest
     * is missing or unreadable.
     */
    private function deleteMultipartPrefix(StorageDriver $driver, string $prefix): void
    {
        $entries = [];
        $manifestRemote = $prefix.'/'.BackupManifestV3::MANIFEST_FILENAME;

        // Manifest path: download and read file list
        try {
            $tempManifest = tempnam(sys_get_temp_dir(), 'manifest-cleanup-');
            $driver->download($manifestRemote, $tempManifest);
            $manifest = BackupManifestV3::decode(file_get_contents($tempManifest));
            @unlink($tempManifest);
            foreach ($manifest['files'] as $f) {
                if (! empty($f['name'])) {
                    $entries[] = $prefix.'/'.$f['name'];
                }
            }
            $entries[] = $manifestRemote;
        } catch (\Throwable $e) {
            // Manifest unavailable — fall back to listing the prefix directly
            Log::info("RetentionService: manifest unreadable for {$prefix}, falling back to list(): {$e->getMessage()}");
            try {
                foreach ($driver->list($prefix) as $file) {
                    if (! empty($file['path'])) {
                        $entries[] = $file['path'];
                    }
                }
            } catch (\Throwable $listErr) {
                Log::warning("RetentionService: list() failed for {$prefix}: {$listErr->getMessage()}");
                throw $listErr;
            }
        }

        foreach ($entries as $remotePath) {
            try {
                $driver->delete($remotePath);
            } catch (\Throwable $e) {
                Log::warning("RetentionService: failed to delete {$remotePath}: {$e->getMessage()}");
            }
        }
    }

    /**
     * @return list<array{destination_id: int, remote_path: string, is_primary: bool}>
     */
    private function collectReplicaTargets(Backup $backup): array
    {
        $targets = [];
        $seen = [];

        // Primary (legacy: storage_destination_id + file_path)
        if ($backup->storage_destination_id && $backup->file_path) {
            $targets[] = [
                'destination_id' => (int) $backup->storage_destination_id,
                'remote_path' => $backup->file_path,
                'is_primary' => true,
            ];
            $seen[(int) $backup->storage_destination_id] = true;
        }

        // Additional replicas (jsonb column added in faza 2)
        foreach ($backup->replicas ?? [] as $replica) {
            $destId = (int) ($replica['destination_id'] ?? 0);
            $remotePath = $replica['remote_path'] ?? null;
            if (! $destId || ! $remotePath || isset($seen[$destId])) {
                continue;
            }
            $targets[] = [
                'destination_id' => $destId,
                'remote_path' => $remotePath,
                'is_primary' => false,
            ];
            $seen[$destId] = true;
        }

        return $targets;
    }

    /**
     * Clean up orphaned incremental backups whose parent was deleted.
     * When a parent backup is deleted, nullOnDelete sets parent_backup_id to NULL.
     * Incrementals with type='incremental' and NULL parent are definitively orphaned.
     */
    private function cleanupOrphans(Site $site): void
    {
        $orphans = Backup::where('site_id', $site->id)
            ->where('type', 'incremental')
            ->whereNull('parent_backup_id')
            ->where('status', BackupStatus::Completed)
            ->get();

        foreach ($orphans as $orphan) {
            Log::info("Cleaning up orphaned incremental backup {$orphan->id} for site {$site->id}");
            $this->deleteBackup($orphan);
        }
    }
}
