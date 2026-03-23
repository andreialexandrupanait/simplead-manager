<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Backup;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class BackupDownloadController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Backup $backup)
    {
        // Verify the backup belongs to a valid site and user has access
        if (! $backup->site || ! $backup->site->exists) {
            abort(403, 'Unauthorized.');
        }

        $this->authorize('view', $backup->site);

        $destination = $backup->storageDestination;

        if (! $destination || $destination->type !== 'local') {
            abort(404, 'Backup not available for local download.');
        }

        $config = $destination->config ?? [];
        $basePath = rtrim($config['path'] ?? storage_path('backups'), '/');
        $filePath = $basePath.'/'.ltrim($backup->file_path, '/');

        // Canonicalize and validate path stays within base directory
        $realBase = realpath($basePath);
        $realFile = realpath($filePath);

        if ($realBase === false || $realFile === false || ! str_starts_with($realFile, $realBase)) {
            abort(404, 'Backup file not found.');
        }

        return response()->download($realFile, $backup->file_name);
    }
}
