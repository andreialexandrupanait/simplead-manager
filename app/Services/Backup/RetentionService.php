<?php

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
        if (!$config) {
            return;
        }

        // Chain-aware retention: group backups into chains (full + incrementals)
        $allBackups = Backup::where('site_id', $site->id)
            ->where('status', BackupStatus::Completed)
            ->where('is_locked', false)
            ->orderByDesc('created_at')
            ->get();

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
            if (!in_array($parentId, $parentIds)) {
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
            $cutoff = now()->subDays($config->retention_value);
            foreach ($chains as $chain) {
                if ($chain->first()->created_at < $cutoff) {
                    $chainsToDelete[] = $chain;
                }
            }
        }

        foreach ($chainsToDelete as $chain) {
            foreach ($chain as $oldBackup) {
                try {
                    DB::transaction(function () use ($oldBackup) {
                        $oldDestination = $oldBackup->storageDestination;

                        if ($oldDestination && $oldBackup->file_path) {
                            $driver = StorageFactory::make($oldDestination);
                            $driver->delete($oldBackup->file_path);
                            $oldDestination->decrement('used_bytes', max(0, $oldBackup->file_size ?? 0));
                        }

                        // Clean up manifest file
                        if ($oldBackup->manifest_path && $oldDestination) {
                            try {
                                StorageFactory::make($oldDestination)->delete($oldBackup->manifest_path);
                            } catch (\Throwable) {}
                        }

                        $oldBackup->delete();
                    });
                } catch (\Exception $e) {
                    Log::warning("Failed to delete old backup {$oldBackup->id}", [
                        'exception' => get_class($e),
                        'code' => $e->getCode(),
                    ]);
                }
            }
        }
    }
}
