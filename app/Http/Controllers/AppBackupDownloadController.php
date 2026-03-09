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
            $filePath = storage_path('app/backups/application/' . $appBackup->storage_path);
            if (!file_exists($filePath)) {
                abort(404, 'Backup file not found.');
            }
            return response()->download($filePath, $appBackup->file_name);
        }

        if ($destination->type !== 'local') {
            abort(404, 'Backup not available for local download.');
        }

        $config = $destination->config ?? [];
        $basePath = rtrim($config['path'] ?? storage_path('backups'), '/');
        $filePath = $basePath . '/' . ltrim($appBackup->storage_path, '/');

        if (!file_exists($filePath)) {
            abort(404, 'Backup file not found.');
        }

        return response()->download($filePath, $appBackup->file_name);
    }
}
