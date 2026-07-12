<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupBackupTemp extends Command
{
    protected $signature = 'backup:cleanup-temp '
        .'{--hours=24 : Delete orphaned backup/restore/verify temp entries older than this many hours} '
        .'{--php-file-minutes=60 : Delete orphaned PHP temp files (php*) older than this many minutes}';

    protected $description = 'Clean up orphaned backup temp directories/files and PHP temp files (from killed workers or crashes)';

    /**
     * Prefixes of every temp entry the backup/restore/verify/app-backup
     * pipelines stage under storage/app/temp. P1-39: the sweep previously only
     * matched `backup-*` directories, so `restore-*` (dirs AND the
     * `restore-{token}` archive copies from sendRestoreData), `verify-*`,
     * `app-backup-*`, `app-restore-*`, `replicate-*` and `backup-inc-*` debris
     * from killed workers accumulated forever until DiskSpaceGuard halted
     * fleet backups. Entries may be directories or files — sweep both.
     */
    private const TEMP_PREFIXES = [
        'backup-',
        'backup-inc-',
        'restore-',
        'verify-',
        'replicate-',
        'app-backup-',
        'app-restore-',
    ];

    public function handle(): int
    {
        $basePath = storage_path('app/temp');

        if (! is_dir($basePath)) {
            $this->info('No temp directory found, nothing to clean.');

            return self::SUCCESS;
        }

        $entriesCleaned = $this->cleanupTempEntries($basePath, (int) $this->option('hours'));
        $filesCleaned = $this->cleanupOrphanedPhpFiles($basePath, (int) $this->option('php-file-minutes'));

        $this->info("Cleaned up {$entriesCleaned} orphaned backup/restore temp "
            .($entriesCleaned === 1 ? 'entry' : 'entries')
            ." and {$filesCleaned} orphaned PHP temp ".($filesCleaned === 1 ? 'file' : 'files').'.');

        return self::SUCCESS;
    }

    private function cleanupTempEntries(string $basePath, int $hours): int
    {
        $cutoff = now()->subHours($hours)->timestamp;
        $cleaned = 0;

        foreach (scandir($basePath) as $entry) {
            if ($entry === '.' || $entry === '..' || ! $this->matchesTempPrefix($entry)) {
                continue;
            }

            $path = $basePath.'/'.$entry;
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
            $cleaned++;
            Log::info("CleanupBackupTemp: removed orphaned temp entry {$entry}");
        }

        return $cleaned;
    }

    private function matchesTempPrefix(string $entry): bool
    {
        foreach (self::TEMP_PREFIXES as $prefix) {
            if (str_starts_with($entry, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function cleanupOrphanedPhpFiles(string $basePath, int $minutes): int
    {
        $cutoff = now()->subMinutes($minutes)->timestamp;
        $cleaned = 0;
        $bytesFreed = 0;

        foreach (scandir($basePath) as $entry) {
            if (! str_starts_with($entry, 'php')) {
                continue;
            }

            $filePath = $basePath.'/'.$entry;
            if (! is_file($filePath) || filemtime($filePath) > $cutoff) {
                continue;
            }

            $size = @filesize($filePath) ?: 0;
            if (@unlink($filePath)) {
                $cleaned++;
                $bytesFreed += $size;
            }
        }

        if ($cleaned > 0) {
            $mb = round($bytesFreed / 1024 / 1024, 1);
            Log::info("CleanupBackupTemp: removed {$cleaned} orphaned PHP temp files ({$mb} MB freed)");
        }

        return $cleaned;
    }

    private function removeDirectory(string $path): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($path);
    }
}
