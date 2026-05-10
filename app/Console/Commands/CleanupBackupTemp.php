<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupBackupTemp extends Command
{
    protected $signature = 'backup:cleanup-temp '
        .'{--hours=24 : Delete backup-* directories older than this many hours} '
        .'{--php-file-minutes=60 : Delete orphaned PHP temp files (php*) older than this many minutes}';

    protected $description = 'Clean up orphaned backup temp directories and PHP temp files (from killed workers or crashes)';

    public function handle(): int
    {
        $basePath = storage_path('app/temp');

        if (! is_dir($basePath)) {
            $this->info('No temp directory found, nothing to clean.');

            return self::SUCCESS;
        }

        $dirsCleaned = $this->cleanupBackupDirectories($basePath, (int) $this->option('hours'));
        $filesCleaned = $this->cleanupOrphanedPhpFiles($basePath, (int) $this->option('php-file-minutes'));

        $this->info("Cleaned up {$dirsCleaned} backup ".($dirsCleaned === 1 ? 'directory' : 'directories')
            ." and {$filesCleaned} orphaned PHP temp ".($filesCleaned === 1 ? 'file' : 'files').'.');

        return self::SUCCESS;
    }

    private function cleanupBackupDirectories(string $basePath, int $hours): int
    {
        $cutoff = now()->subHours($hours)->timestamp;
        $cleaned = 0;

        foreach (scandir($basePath) as $entry) {
            if (! str_starts_with($entry, 'backup-')) {
                continue;
            }

            $dirPath = $basePath.'/'.$entry;
            if (! is_dir($dirPath) || filemtime($dirPath) > $cutoff) {
                continue;
            }

            $this->removeDirectory($dirPath);
            $cleaned++;
            Log::info("CleanupBackupTemp: removed orphaned directory {$entry}");
        }

        return $cleaned;
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
