<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
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
     * Delete a single backup record and its associated storage files.
     */
    private function deleteBackup(Backup $backup): void
    {
        try {
            DB::transaction(function () use ($backup) {
                /** @var StorageDestination|null $destination */
                $destination = $backup->storageDestination;

                if ($destination && $backup->file_path) {
                    $driver = StorageFactory::make($destination);
                    $driver->delete($backup->file_path);
                    $destination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
                }

                // Clean up manifest file
                if ($backup->manifest_path && $destination) {
                    try {
                        StorageFactory::make($destination)->delete($backup->manifest_path);
                    } catch (\Throwable $e) {
                        Log::warning("Failed to delete manifest for backup {$backup->id}: {$e->getMessage()}");
                    }
                }

                $backup->delete();
            });
        } catch (\Exception $e) {
            Log::warning("Failed to delete old backup {$backup->id}", [
                'exception' => get_class($e),
                'code' => $e->getCode(),
            ]);
        }
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
