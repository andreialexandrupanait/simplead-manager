<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PluginPackage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the simplead-backup plugin as a zip, for a site to install from a signed URL.
 *
 * Until now the V2 backup engine reached a site exactly one way: somebody downloaded it and
 * uploaded it by hand through wp-admin. That is why it was live on one site out of twenty-four,
 * and why every fix to it — including the ones that stop a restore filling the client's disk —
 * stopped at the edge of the fleet.
 */
class BackupPluginDownloadController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $package = PluginPackage::backupEngine();

        if (! $package->exists()) {
            abort(404, 'Plugin source not found.');
        }

        // The version goes in the ETag, so a cache between us and the site cannot serve yesterday's
        // zip after a version bump — which surfaces at the far end as an integrity failure, with
        // nothing to suggest a cache was involved.
        return response()
            ->download($package->buildZip(), 'simplead-backup.zip', [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'public, max-age=3600',
                'ETag' => '"simplead-backup-'.$package->version().'"',
            ])
            ->deleteFileAfterSend(true);
    }
}
