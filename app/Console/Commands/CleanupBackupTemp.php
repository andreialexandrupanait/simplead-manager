<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupBackupTemp extends Command
{
    protected $signature = 'backup:cleanup-temp {--hours=24 : Delete temp directories older than this many hours}';

    protected $description = 'Clean up orphaned backup temp directories (from killed workers or crashes)';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $basePath = storage_path('app/temp');

        if (!is_dir($basePath)) {
            $this->info('No temp directory found, nothing to clean.');
            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours)->timestamp;
        $cleaned = 0;

        foreach (scandir($basePath) as $entry) {
            if (!str_starts_with($entry, 'backup-')) {
                continue;
            }

            $dirPath = $basePath . '/' . $entry;
            if (!is_dir($dirPath)) {
                continue;
            }

            if (filemtime($dirPath) > $cutoff) {
                continue;
            }

            $this->removeDirectory($dirPath);
            $cleaned++;
            Log::info("CleanupBackupTemp: removed orphaned directory {$entry}");
        }

        $this->info("Cleaned up {$cleaned} orphaned backup temp " . ($cleaned === 1 ? 'directory' : 'directories') . '.');

        return self::SUCCESS;
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
