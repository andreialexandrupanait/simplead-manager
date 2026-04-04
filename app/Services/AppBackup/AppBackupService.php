<?php

declare(strict_types=1);

namespace App\Services\AppBackup;

use App\Models\AppBackup;
use App\Models\AppBackupConfig;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\Log;

class AppBackupService
{
    public function __construct(
        private AppBackupCreator $creator,
        private AppBackupRestorer $restorer,
    ) {}

    public function createBackup(
        string $type = 'full',
        string $trigger = 'manual',
        ?int $storageDestinationId = null,
        array $options = [],
        ?string $notes = null,
    ): AppBackup {
        return $this->creator->create($type, $trigger, $storageDestinationId, $options, $notes);
    }

    public function restoreDatabase(AppBackup $backup): array
    {
        return $this->restorer->restoreDatabase($backup);
    }

    public function viewEnv(AppBackup $backup): string
    {
        return $this->restorer->viewEnv($backup);
    }

    public function downloadBackup(AppBackup $backup): string
    {
        /** @var StorageDestination|null $destination */
        $destination = $backup->storageDestination;

        if (! $destination) {
            $localPath = storage_path('app/backups/application/'.$backup->storage_path);
            if (! file_exists($localPath)) {
                throw new \RuntimeException('Backup file not found.');
            }

            return $localPath;
        }

        if ($destination->type === 'local') {
            $config = $destination->config ?? [];
            $basePath = rtrim($config['path'] ?? storage_path('backups'), '/');
            $filePath = $basePath.'/'.ltrim($backup->storage_path, '/');

            if (! file_exists($filePath)) {
                throw new \RuntimeException('Backup file not found.');
            }

            return $filePath;
        }

        $tempDir = storage_path('app/temp/app-backup-download-'.$backup->id);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $localPath = $tempDir.'/'.$backup->file_name;
        $driver = StorageFactory::make($destination);
        $driver->download($backup->storage_path, $localPath);

        return $localPath;
    }

    public function deleteBackup(AppBackup $backup): void
    {
        /** @var StorageDestination|null $destination */
        $destination = $backup->storageDestination;

        if ($destination && $backup->storage_path) {
            try {
                $driver = StorageFactory::make($destination);
                $driver->delete($backup->storage_path);
                $destination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
            } catch (\RuntimeException $e) {
                Log::warning("Failed to delete app backup file {$backup->storage_path}: {$e->getMessage()}");
            }
        } elseif (! $destination && $backup->storage_path) {
            $localPath = storage_path('app/backups/application/'.$backup->storage_path);
            if (file_exists($localPath)) {
                unlink($localPath);
            }
        }

        $backup->delete();
    }

    public function applyRetention(): void
    {
        $config = AppBackupConfig::instance();

        $query = AppBackup::where('status', 'completed')
            ->where('is_locked', false)
            ->orderByDesc('created_at');

        if ($config->retention_type === 'count') {
            $toDelete = $query->skip($config->retention_value)->get();
        } else {
            $cutoff = now()->subDays($config->retention_value);
            $toDelete = AppBackup::where('status', 'completed')
                ->where('is_locked', false)
                ->where('created_at', '<', $cutoff)
                ->get();
        }

        foreach ($toDelete as $oldBackup) {
            try {
                $this->deleteBackup($oldBackup);
            } catch (\RuntimeException $e) {
                Log::warning("Failed to delete old app backup {$oldBackup->id}: {$e->getMessage()}");
            }
        }
    }

    public function cleanupExpired(): void
    {
        AppBackup::expired()
            ->where('is_locked', false)
            ->each(function (AppBackup $backup) {
                try {
                    $this->deleteBackup($backup);
                } catch (\RuntimeException $e) {
                    Log::warning("Failed to clean expired app backup {$backup->id}: {$e->getMessage()}");
                }
            });
    }
}
