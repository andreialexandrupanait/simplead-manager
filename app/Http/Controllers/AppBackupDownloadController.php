<?php

namespace App\Http\Controllers;

use App\Models\AppBackup;
use Illuminate\Http\Request;

class AppBackupDownloadController extends Controller
{
    public function __invoke(Request $request, AppBackup $appBackup)
    {
        $destination = $appBackup->storageDestination;

        if (!$destination) {
            // Local fallback
            $basePath = storage_path('app/backups/application');
            $filePath = $basePath . '/' . ltrim($appBackup->storage_path, '/');

            $realBase = realpath($basePath);
            $realFile = realpath($filePath);

            if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
                abort(404, 'Backup file not found.');
            }

            return response()->download($realFile, $appBackup->file_name);
        }

        if ($destination->type !== 'local') {
            abort(404, 'Backup not available for local download.');
        }

        $config = $destination->config ?? [];
        $basePath = rtrim($config['path'] ?? storage_path('backups'), '/');
        $filePath = $basePath . '/' . ltrim($appBackup->storage_path, '/');

        $realBase = realpath($basePath);
        $realFile = realpath($filePath);

        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase)) {
            abort(404, 'Backup file not found.');
        }

        return response()->download($realFile, $appBackup->file_name);
    }
}
