<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Backup;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class BackupLocalExportDownloadController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Backup $backup)
    {
        if (! $backup->site || ! $backup->site->exists) {
            abort(403, 'Unauthorized.');
        }

        $this->authorize('view', $backup->site);

        if (! $backup->localExportReady()) {
            abort(404, 'Local export not ready.');
        }

        $destination = $backup->storageDestination;

        if (! $destination || $destination->type !== 'local') {
            abort(404, 'Local export not available for local download.');
        }

        $config = $destination->config ?? [];
        $basePath = rtrim($config['path'] ?? storage_path('backups'), '/');
        $filePath = $basePath.'/'.ltrim((string) $backup->local_export_file_path, '/');

        $realBase = realpath($basePath);
        $realFile = realpath($filePath);

        if ($realBase === false || $realFile === false || ! str_starts_with($realFile, $realBase)) {
            abort(404, 'Local export file not found.');
        }

        return response()->download($realFile, basename($backup->local_export_file_path));
    }
}
