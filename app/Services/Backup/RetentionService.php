<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageDriver;
use App\Services\Backup\Storage\StorageFactory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RetentionService
{
    /**
     * Apply chain-aware retention policy for a site's backups.
     *
     * A "chain" is a full backup plus every incremental that descends from it
     * (orphaned incrementals — parent already gone — form their own single-
     * member chains). The whole chain is the atomic unit of retention: an
     * incremental cannot be restored without its base full, so we never delete
     * a base while a still-in-window incremental descends from it, and we never
     * delete a chain unless its NEWEST member is itself out of the window.
     *
     * Deletes run in log-only (dry-run) mode by default — see
     * config('backups.retention_dry_run') and P0-03's safe rollout.
     */
    public function apply(Site $site, StorageDestination $destination): void
    {
        $config = $site->backupConfig;
        if (! $config) {
            return;
        }

        $dryRun = (bool) config('backups.retention_dry_run', true);

        // Load ALL completed, unlocked backups. Crucially we do NOT pre-filter
        // by date for days-mode: a fresh incremental must be visible so its
        // chain is recognised as still-valid and its base full is protected
        // (the P0-03 data-loss bug pre-filtered the fresh incremental away,
        // deleted the base full, then cleanupOrphans destroyed the incremental).
        $allBackups = Backup::where('site_id', $site->id)
            ->where('status', BackupStatus::Completed)
            ->where('is_locked', false)
            ->orderByDesc('created_at')
            ->get();

        $chains = $this->buildChains($allBackups);

        $cutoff = $config->retention_type === 'days'
            ? now()->subDays((int) $config->retention_value)
            : null;

        // Sort chains by their NEWEST member (most-recent restore point first).
        usort($chains, fn (Collection $a, Collection $b) => $this->chainNewest($b) <=> $this->chainNewest($a));

        $chainsToDelete = [];
        if ($config->retention_type === 'count') {
            // Keep the N chains with the newest restore points; delete the rest.
            $chainsToDelete = array_slice($chains, (int) $config->retention_value);
        } else {
            // Days: a chain is deletable ONLY when its newest member is older
            // than the cutoff. An old full carrying a fresh incremental survives.
            foreach ($chains as $chain) {
                if ($this->chainNewest($chain) < $cutoff) {
                    $chainsToDelete[] = $chain;
                }
            }
        }

        foreach ($chainsToDelete as $chain) {
            foreach ($chain as $oldBackup) {
                $this->deleteBackup($oldBackup, $dryRun);
            }
        }

        // Age-gated orphan sweep (days-mode only). The chain logic above already
        // removes out-of-window orphan chains atomically; this is a defence-in-
        // depth pass for orphans created mid-cycle (e.g. a partial replica-delete
        // that dropped a base but retained a child). It NEVER deletes a recent
        // restore point: only orphans older than the cutoff are eligible. In
        // count-mode there is no time window, so the chain logic is the sole path.
        if ($cutoff !== null) {
            $this->cleanupOrphans($site, $cutoff, $dryRun);
        }
    }

    /**
     * Group backups into chains: each full (or orphaned incremental) plus the
     * incrementals that descend from it.
     *
     * @param  Collection<int, Backup>  $allBackups
     * @return list<Collection<int, Backup>>
     */
    private function buildChains(Collection $allBackups): array
    {
        $chains = [];

        // whereNull('parent_backup_id') captures both true fulls AND orphaned
        // incrementals whose parent was already removed — each becomes its own
        // chain root.
        $roots = $allBackups->whereNull('parent_backup_id');
        $incrementalByParent = $allBackups->whereNotNull('parent_backup_id')->groupBy('parent_backup_id');

        foreach ($roots as $root) {
            $chain = collect([$root]);
            if (isset($incrementalByParent[$root->id])) {
                $chain = $chain->merge($incrementalByParent[$root->id]);
            }
            $chains[] = $chain;
        }

        // Incrementals whose (non-null) parent is not in the loaded set — e.g.
        // the parent is locked or failed — form their own chains too.
        $rootIds = $roots->pluck('id')->all();
        foreach ($incrementalByParent as $parentId => $incrementals) {
            if (! in_array($parentId, $rootIds, true)) {
                $chains[] = $incrementals->values();
            }
        }

        return $chains;
    }

    /**
     * The timestamp of the newest (most recently created) member of a chain —
     * the age of the freshest restore point the chain provides.
     *
     * @param  Collection<int, Backup>  $chain
     */
    private function chainNewest(Collection $chain): CarbonInterface
    {
        return $chain->max('created_at');
    }

    /**
     * Delete a single backup record and its associated storage files in every replica.
     * If ANY replica delete fails, the DB row is preserved so the next retention pass
     * can retry. The successfully-deleted destinations are removed from `replicas[]`
     * to avoid double-decrementing used_bytes on retry.
     */
    private function deleteBackup(Backup $backup, bool $dryRun = false): void
    {
        if ($dryRun) {
            Log::info("RetentionService[dry-run]: would delete backup {$backup->id}", [
                'site_id' => $backup->site_id,
                'type' => $backup->type,
                'parent_backup_id' => $backup->parent_backup_id,
                'created_at' => (string) $backup->created_at,
                'file_size' => $backup->file_size,
            ]);

            return;
        }

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
     *
     * Age-gated: only orphans OLDER than $deletableBefore are removed, so a
     * recent restore point is never destroyed by this sweep (P0-03).
     */
    private function cleanupOrphans(Site $site, CarbonInterface $deletableBefore, bool $dryRun = false): void
    {
        $orphans = Backup::where('site_id', $site->id)
            ->where('type', 'incremental')
            ->whereNull('parent_backup_id')
            ->where('status', BackupStatus::Completed)
            ->where('is_locked', false)
            ->where('created_at', '<', $deletableBefore)
            ->get();

        foreach ($orphans as $orphan) {
            Log::info("Cleaning up orphaned incremental backup {$orphan->id} for site {$site->id}");
            $this->deleteBackup($orphan, $dryRun);
        }
    }
}
