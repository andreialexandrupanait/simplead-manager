<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PluginPackage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConnectorPluginDownloadController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $package = PluginPackage::connector();

        if (! $package->exists()) {
            abort(404, 'Plugin source not found.');
        }

        // Read the version out of the plugin file being served, not from config:
        // config('connector.current_version') does not exist (config/connector.php
        // holds only a changelog), so the ETag was permanently "connector-0.0.0"
        // next to a one-hour Cache-Control. Any cache in between could hand back
        // a stale zip across a version bump — surfacing as a baffling
        // HASH_MISMATCH on the push path, or as a silently wrong install on the
        // CLI path, which sends no hash at all.
        //
        // The zip itself is built by PluginPackage, which the push job also uses to compute the
        // hash it sends. They used to be two copies of the same directory walk that had to agree
        // byte for byte; now they cannot disagree.
        return response()
            ->download($package->buildZip(), 'simplead-manager-connector.zip', [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'public, max-age=3600',
                'ETag' => '"connector-'.$package->version().'"',
            ])
            ->deleteFileAfterSend(true);
    }
}
