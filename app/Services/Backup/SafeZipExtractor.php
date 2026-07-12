<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Path-traversal-safe extraction for backup archives.
 *
 * Backup archives are produced on semi-trusted WordPress hosts, so their entry
 * names cannot be trusted. A crafted entry such as `../../etc/passwd`, an
 * absolute path (`/etc/...`), or a Windows drive path (`C:\...`) passed to
 * ZipArchive::extractTo() would write OUTSIDE the intended extraction root
 * (Zip-Slip). This helper validates every entry and only extracts the ones
 * that resolve inside the target directory, logging and skipping the rest
 * (fail closed — a traversal entry never lands on disk).
 */
class SafeZipExtractor
{
    /**
     * Extract every safe entry of $zip into $targetDir. Unsafe entries (path
     * traversal, absolute paths) are skipped with a logged warning. Returns the
     * number of entries that were skipped as unsafe.
     */
    public static function extractTo(ZipArchive $zip, string $targetDir): int
    {
        $safeEntries = [];
        $skipped = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = $stat['name'];
            if (self::isSafeEntryName($name)) {
                $safeEntries[] = $name;

                continue;
            }

            $skipped++;
            Log::warning('SafeZipExtractor: rejected unsafe zip entry (path traversal)', [
                'entry' => $name,
                'target_dir' => $targetDir,
            ]);
        }

        if ($safeEntries !== []) {
            $zip->extractTo($targetDir, $safeEntries);
        }

        return $skipped;
    }

    /**
     * True when a zip entry name stays within the extraction root: not empty,
     * not absolute, no `..` traversal segment, no Windows drive/absolute path.
     */
    public static function isSafeEntryName(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        // Normalise Windows separators so `..\..\` is caught too.
        $normalised = str_replace('\\', '/', $name);

        // Absolute POSIX path.
        if (str_starts_with($normalised, '/')) {
            return false;
        }

        // Windows drive path (C:...) or UNC-ish prefix.
        if (preg_match('#^[a-zA-Z]:#', $normalised) === 1) {
            return false;
        }

        // Any parent-directory traversal segment.
        if (preg_match('#(^|/)\.\.(/|$)#', $normalised) === 1) {
            return false;
        }

        return true;
    }
}
