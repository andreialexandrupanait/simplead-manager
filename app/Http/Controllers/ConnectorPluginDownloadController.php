<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class ConnectorPluginDownloadController extends Controller
{
    public function __invoke()
    {
        $sourceDir = base_path('wordpress-plugin/simplead-manager-connector');

        if (! is_dir($sourceDir)) {
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
                $relativePath = 'simplead-manager-connector/'.substr($file->getRealPath(), strlen($sourceDir) + 1);
                $zip->addFile($file->getRealPath(), $relativePath);
            }
        }

        $zip->close();

        // Read the version out of the plugin file being served, not from config:
        // config('connector.current_version') does not exist (config/connector.php
        // holds only a changelog), so the ETag was permanently "connector-0.0.0"
        // next to a one-hour Cache-Control. Any cache in between could hand back
        // a stale zip across a version bump — surfacing as a baffling
        // HASH_MISMATCH on the push path, or as a silently wrong install on the
        // CLI path, which sends no hash at all.
        $version = $this->pluginVersion($sourceDir);

        return response()->download($tempFile, 'simplead-manager-connector.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => '"connector-'.$version.'"',
        ])->deleteFileAfterSend(true);
    }

    /**
     * The Version: header of the plugin's main file — the same string the
     * connector reports back after a self-update, so the two can be compared.
     */
    private function pluginVersion(string $sourceDir): string
    {
        $main = $sourceDir.'/simplead-manager-connector.php';

        if (! is_file($main)) {
            return '0.0.0';
        }

        // Only the header block is needed; the file is otherwise large.
        $head = (string) file_get_contents($main, false, null, 0, 2048);

        return preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $head, $m) === 1
            ? trim($m[1])
            : '0.0.0';
    }
}
