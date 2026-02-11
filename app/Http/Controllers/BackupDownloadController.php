<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use Illuminate\Http\Request;

class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, Backup $backup)
    {
        // Verify the backup belongs to a valid site
        if (!$backup->site || !$backup->site->exists) {
            abort(403, 'Unauthorized.');
        }

        $destination = $backup->storageDestination;

        if (!$destination || $destination->type !== 'local') {
            abort(404, 'Backup not available for local download.');
        }

        $config = $destination->config ?? [];
        $basePath = rtrim($config['path'] ?? storage_path('backups'), '/');
        $filePath = $basePath . '/' . ltrim($backup->file_path, '/');

        if (!file_exists($filePath)) {
            abort(404, 'Backup file not found.');
        }

        return response()->download($filePath, $backup->file_name);
    }
}
