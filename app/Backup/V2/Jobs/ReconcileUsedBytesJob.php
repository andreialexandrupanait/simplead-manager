<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Support\BackupLogger;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * `used_bytes` truth job — recomputes a StorageDestination's `used_bytes` from the
 * real summed size of its objects (storage truth), fixing the drift called out in
 * docs/backup/TARGET-ARCHITECTURE.md.
 *
 * SAFETY: inert unless the V2 engine is enabled AND write mode is on. With the
 * default flags (config('backup_v2.enabled') / .reconciliation_writes_enabled both
 * false) it computes and logs the drift but writes NOTHING — a pure read-only
 * probe. It only persists the corrected value when writes are explicitly enabled.
 */
class ReconcileUsedBytesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $storageDestinationId,
    ) {}

    public function handle(): void
    {
        $logger = (new BackupLogger)->forSession('reconcile-used-bytes', null, null);

        // Master kill-switch: do nothing at all unless the V2 engine is enabled.
        if (! (bool) config('backup_v2.enabled', false)) {
            $logger->info('ReconcileUsedBytesJob skipped: backup_v2.enabled is false');

            return;
        }

        $destination = StorageDestination::find($this->storageDestinationId);
        if ($destination === null) {
            $logger->warning('ReconcileUsedBytesJob: destination not found', [
                'destination_id' => $this->storageDestinationId,
            ]);

            return;
        }

        try {
            $driver = StorageFactory::make($destination);
            $files = $driver->listRecursive('');
        } catch (\Throwable $e) {
            $logger->warning('ReconcileUsedBytesJob: could not list storage', [
                'destination_id' => $destination->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $summed = 0;
        foreach ($files as $entry) {
            if ($entry['is_dir']) {
                continue;
            }
            $summed += (int) $entry['size'];
        }

        $recorded = (int) $destination->used_bytes;
        $writesEnabled = (bool) config('backup_v2.reconciliation_writes_enabled', false);

        $logger->info('ReconcileUsedBytesJob computed used_bytes truth', [
            'destination_id' => $destination->id,
            'recorded_used_bytes' => $recorded,
            'summed_used_bytes' => $summed,
            'delta_bytes' => $summed - $recorded,
            'writes_enabled' => $writesEnabled,
        ]);

        if (! $writesEnabled) {
            // Read-only: report the drift, change nothing.
            return;
        }

        if ($summed !== $recorded) {
            $destination->used_bytes = $summed;
            $destination->save();

            $logger->info('ReconcileUsedBytesJob corrected used_bytes', [
                'destination_id' => $destination->id,
                'from' => $recorded,
                'to' => $summed,
            ]);
        }
    }
}
