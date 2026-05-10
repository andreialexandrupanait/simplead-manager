<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Backup;
use App\Models\StorageDestination;
use App\Services\Backup\IntegrityVerifier;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Copies an already-uploaded backup from its primary destination to a secondary
 * destination, satisfying the 3-2-1 rule. Idempotent: re-running for the same
 * (backup, destination) is a no-op if the replica is already recorded.
 *
 * Dispatched after CreateBackup / CreateIncrementalBackup finalize the primary
 * upload. Failure here does NOT fail the backup — primary already exists, so
 * the backup is "completed but partially replicated" and the dispatcher will
 * surface the gap via Backup.replicas inspection on the dashboard.
 */
class ReplicateBackup implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 1800;

    public function __construct(
        public int $backupId,
        public int $destinationId,
    ) {
        $this->onQueue('backups');
    }

    public function uniqueId(): string
    {
        return "replicate-{$this->backupId}-{$this->destinationId}";
    }

    public function handle(): void
    {
        /** @var Backup|null $backup */
        $backup = Backup::with('storageDestination')->find($this->backupId);
        if (! $backup) {
            Log::warning("ReplicateBackup: backup #{$this->backupId} not found, skipping");

            return;
        }

        // Idempotency — skip if replica for this destination already recorded
        $existingReplicas = $backup->replicas ?? [];
        foreach ($existingReplicas as $replica) {
            if (($replica['destination_id'] ?? null) === $this->destinationId) {
                Log::info("ReplicateBackup: backup #{$this->backupId} already replicated to destination {$this->destinationId}");

                return;
            }
        }

        $secondary = StorageDestination::find($this->destinationId);
        if (! $secondary || ! $secondary->is_active) {
            Log::warning("ReplicateBackup: destination #{$this->destinationId} missing or inactive");

            return;
        }

        $primary = $backup->storageDestination;
        if (! $primary || ! $backup->file_path || ! $backup->file_name) {
            Log::warning("ReplicateBackup: backup #{$this->backupId} has no primary storage; nothing to replicate");

            return;
        }

        $tempDir = storage_path('app/temp/replicate-'.uniqid());
        @mkdir($tempDir, 0700, true);
        $localPath = $tempDir.'/'.$backup->file_name;

        try {
            // 1. Pull from primary
            Log::info("ReplicateBackup: downloading backup #{$backup->id} from {$primary->type} ({$primary->name})");
            $primaryDriver = StorageFactory::make($primary);
            $primaryDriver->download($backup->file_path, $localPath);

            if (! is_file($localPath) || filesize($localPath) === 0) {
                throw new \RuntimeException('downloaded file empty or missing');
            }

            // 2. Optional integrity check before pushing — cheap insurance
            if ($backup->checksum) {
                $verifier = app(IntegrityVerifier::class);
                $check = $verifier->verifyArchive($localPath, $backup->checksum);
                if (! $check['ok']) {
                    throw new \RuntimeException('primary copy failed integrity check before replication: '.$check['message']);
                }
            }

            // 3. Push to secondary using same remote_path layout
            $remotePath = $backup->file_path; // same domain/filename layout works on any provider
            Log::info("ReplicateBackup: uploading backup #{$backup->id} to {$secondary->type} ({$secondary->name})");
            $secondaryDriver = StorageFactory::make($secondary);
            $secondaryDriver->upload($localPath, $remotePath);

            // 4. Append replica record (atomic via lock on the row)
            DB::transaction(function () use ($backup, $remotePath) {
                $fresh = Backup::lockForUpdate()->find($backup->id);
                if (! $fresh) {
                    return;
                }
                $replicas = $fresh->replicas ?? [];
                // Re-check idempotency under lock
                foreach ($replicas as $r) {
                    if (($r['destination_id'] ?? null) === $this->destinationId) {
                        return;
                    }
                }
                $replicas[] = [
                    'destination_id' => $this->destinationId,
                    'remote_path' => $remotePath,
                    'uploaded_at' => now()->toIso8601String(),
                    'status' => 'completed',
                ];
                $fresh->update(['replicas' => $replicas]);
            });

            $secondary->increment('used_bytes', $backup->file_size ?? 0);

            Log::info("ReplicateBackup: backup #{$backup->id} replicated to destination #{$secondary->id}");
        } catch (\Throwable $e) {
            Log::error("ReplicateBackup: failed for backup #{$backup->id} → destination #{$this->destinationId}: {$e->getMessage()}", [
                'exception' => $e::class,
            ]);
            throw $e;
        } finally {
            $this->cleanup($tempDir);
        }
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
