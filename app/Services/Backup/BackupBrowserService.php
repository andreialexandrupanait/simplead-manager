<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class BackupBrowserService
{
    protected const MAX_FILES = 15000;

    protected const CACHE_TTL = 2592000; // 30 days — backup contents never change

    /**
     * List the contents of a backup archive.
     *
     * @return array{has_database: bool, has_files: bool, file_count: int, files: array, truncated: bool}
     */
    public function listContents(Backup $backup): array
    {
        $cacheKey = self::cacheKey($backup->id);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $tempDir = storage_path('app/temp/browse-'.uniqid());
        mkdir($tempDir, 0755, true);

        try {
            $destination = $backup->storageDestination;
            if (! $destination || ! $backup->file_path) {
                throw new \RuntimeException('Backup storage destination or file path is missing.');
            }

            // Download the outer zip
            $localPath = $tempDir.'/'.$backup->file_name;
            $driver = StorageFactory::make($destination);
            $driver->download($backup->file_path, $localPath);

            // Open outer zip
            $zip = new ZipArchive;
            if ($zip->open($localPath) !== true) {
                throw new \RuntimeException('Failed to open backup archive.');
            }

            $hasDatabase = ($zip->locateName('database.sql.gz') !== false);
            $hasFiles = ($zip->locateName('files.zip') !== false);

            $files = [];
            $truncated = false;

            if ($hasFiles) {
                // Extract only the files archive
                $innerPath = $tempDir.'/files.zip';
                $zip->extractTo($tempDir, ['files.zip']);
                $zip->close();

                $files = self::listArchiveContents($innerPath);

                if (count($files) > self::MAX_FILES) {
                    $files = array_slice($files, 0, self::MAX_FILES);
                    $truncated = true;
                }
            } else {
                $zip->close();
            }

            $result = [
                'has_database' => $hasDatabase,
                'has_files' => $hasFiles,
                'file_count' => count($files),
                'files' => $files,
                'truncated' => $truncated,
            ];

            Cache::put($cacheKey, $result, self::CACHE_TTL);

            return $result;
        } finally {
            $this->cleanup($tempDir);
        }
    }

    /**
     * Pre-populate the cache from a local files archive during backup creation.
     * Called from CreateBackup job when the files are still on disk.
     */
    public static function precache(int $backupId, ?string $filesPath, bool $hasDatabase): void
    {
        try {
            $files = [];
            $truncated = false;
            $hasFiles = $filesPath && file_exists($filesPath);

            if ($hasFiles) {
                $files = self::listArchiveContents($filesPath);

                if (count($files) > self::MAX_FILES) {
                    $files = array_slice($files, 0, self::MAX_FILES);
                    $truncated = true;
                }
            }

            $result = [
                'has_database' => $hasDatabase,
                'has_files' => $hasFiles,
                'file_count' => count($files),
                'files' => $files,
                'truncated' => $truncated,
            ];

            Cache::put(self::cacheKey($backupId), $result, self::CACHE_TTL);
        } catch (\Exception $e) {
            // Non-critical — don't fail the backup if precaching fails
            Log::warning("Failed to precache file list for backup {$backupId}: {$e->getMessage()}");
        }
    }

    /**
     * List contents of a local archive file (zip or tar.gz, detected by magic bytes).
     */
    public static function listArchiveContents(string $archivePath): array
    {
        $fh = fopen($archivePath, 'rb');
        $magic = fread($fh, 2);
        fclose($fh);

        if ($magic === 'PK') {
            return self::listZipContents($archivePath);
        }

        if ($magic === "\x1f\x8b") {
            return self::listTarGzContents($archivePath);
        }

        return [];
    }

    /**
     * List contents of a zip archive.
     */
    public static function listZipContents(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Failed to open inner files archive.');
        }

        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];

            // Skip directory entries
            if (str_ends_with($name, '/')) {
                continue;
            }

            $files[] = [
                'path' => $name,
                'size' => $stat['size'],
            ];
        }

        $zip->close();

        return $files;
    }

    /**
     * List contents of a tar.gz archive using tar command.
     */
    public static function listTarGzContents(string $tarPath): array
    {
        $cmd = ['tar', 'tzvf', $tarPath];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Failed to run tar command to list contents.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $files = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            // tar tzvf format: -rw-r--r-- user/group  1234 2024-01-01 12:00 ./path/to/file
            $parts = preg_split('/\s+/', $line, 6);
            if (count($parts) < 6) {
                continue;
            }

            $permissions = $parts[0];
            $size = (int) $parts[2];
            $path = ltrim($parts[5], './');

            // Skip directories (permissions start with 'd')
            if (str_starts_with($permissions, 'd') || $path === '' || str_ends_with($path, '/')) {
                continue;
            }

            $files[] = [
                'path' => $path,
                'size' => $size,
            ];
        }

        return $files;
    }

    /**
     * Cache key for a backup's file list.
     */
    public static function cacheKey(int $backupId): string
    {
        return "backup:{$backupId}:file-list";
    }

    /**
     * Clean up temporary directory.
     */
    protected function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
