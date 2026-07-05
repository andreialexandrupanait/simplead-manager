<?php

declare(strict_types=1);

namespace App\Services\AppBackup;

use App\Models\AppBackup;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;

class AppBackupDownloader
{
    public function download(AppBackup $backup): string
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
}
