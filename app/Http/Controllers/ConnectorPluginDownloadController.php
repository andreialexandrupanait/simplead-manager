<?php

namespace App\Http\Controllers;

use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ConnectorPluginDownloadController extends Controller
{
    public function __invoke()
    {
        $sourceDir = base_path('wordpress-plugin/simplead-manager-connector');

        if (!is_dir($sourceDir)) {
            abort(404, 'Plugin source not found.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'connector-plugin-');

        $zip = new ZipArchive;
        $zip->open($tempFile, ZipArchive::OVERWRITE);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $relativePath = 'simplead-manager-connector/' . substr($file->getRealPath(), strlen($sourceDir) + 1);
                $zip->addFile($file->getRealPath(), $relativePath);
            }
        }

        $zip->close();

        return response()->download($tempFile, 'simplead-manager-connector.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
