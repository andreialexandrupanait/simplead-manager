<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageDriver;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class BackupBrowserService
{
    protected const MAX_FILES = 15000;

    protected const CACHE_TTL = 2592000; // 30 days — backup contents never change

    /** Prefix under which the current v3-zip format stores WP files inside the outer zip. */
    protected const V3_FILES_PREFIX = 'files/';

    /**
     * List the contents of a backup archive.
     *
     * Dispatches on the stored format so the file browser understands every
     * write path: v3-zip (the current path — files under a `files/` subtree),
     * multipart-v3 (BackupManifestV3 — a prefix of `chunks/N.zip`), and the
     * legacy single-zip-with-inner-files.zip layout.
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

        $result = match ($backup->format) {
            'v3-zip' => $this->listV3ZipContents($backup),
            BackupManifestV3::FORMAT => $this->listMultipartV3Contents($backup),
            default => $this->listLegacyContents($backup),
        };

        Cache::put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Assemble the standard listing envelope, applying the MAX_FILES cap.
     *
     * @param  array<int, array{path: string, size: int}>  $files
     * @return array{has_database: bool, has_files: bool, file_count: int, files: array, truncated: bool}
     */
    protected function envelope(array $files, bool $hasDatabase): array
    {
        $truncated = false;
        if (count($files) > self::MAX_FILES) {
            $files = array_slice($files, 0, self::MAX_FILES);
            $truncated = true;
        }

        return [
            'has_database' => $hasDatabase,
            'has_files' => count($files) > 0,
            'file_count' => count($files),
            'files' => $files,
            'truncated' => $truncated,
        ];
    }

    protected function makePrimaryDriver(Backup $backup): StorageDriver
    {
        /** @var StorageDestination|null $destination */
        $destination = $backup->storageDestination;
        if (! $destination || ! $backup->file_path) {
            throw new \RuntimeException('Backup storage destination or file path is missing.');
        }

        return StorageFactory::make($destination);
    }

    /**
     * v3-zip: single outer zip whose WP files live under the `files/` prefix.
     * The picker/selective-restore path operates on WP-root-relative paths, so
     * the `files/` prefix is stripped here to match RestoreBackup's repack.
     *
     * @return array{has_database: bool, has_files: bool, file_count: int, files: array, truncated: bool}
     */
    protected function listV3ZipContents(Backup $backup): array
    {
        $tempDir = storage_path('app/temp/browse-'.uniqid());
        mkdir($tempDir, 0755, true);

        try {
            $driver = $this->makePrimaryDriver($backup);
            $localPath = $tempDir.'/'.($backup->file_name ?: 'backup.zip');
            $driver->download($backup->file_path, $localPath);

            $zip = new ZipArchive;
            if ($zip->open($localPath) !== true) {
                throw new \RuntimeException('Failed to open backup archive.');
            }

            $hasDatabase = ($zip->locateName('database.sql.gz') !== false);
            $prefixLen = strlen(self::V3_FILES_PREFIX);
            $files = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = $stat['name'];

                if (! str_starts_with($name, self::V3_FILES_PREFIX) || str_ends_with($name, '/')) {
                    continue;
                }

                $relative = substr($name, $prefixLen);
                if ($relative === '') {
                    continue;
                }

                $files[] = [
                    'path' => $relative,
                    'size' => (int) $stat['size'],
                ];
            }

            $zip->close();

            return $this->envelope($files, $hasDatabase);
        } finally {
            $this->cleanup($tempDir);
        }
    }

    /**
     * multipart-v3 (BackupManifestV3): a remote prefix containing manifest.json,
     * database.sql.gz and chunks/N.zip. Files are read by downloading each chunk
     * and listing its central directory (chunk entries are already WP-root
     * relative, matching the selective-restore path).
     *
     * @return array{has_database: bool, has_files: bool, file_count: int, files: array, truncated: bool}
     */
    protected function listMultipartV3Contents(Backup $backup): array
    {
        $tempDir = storage_path('app/temp/browse-'.uniqid());
        mkdir($tempDir, 0755, true);

        try {
            $driver = $this->makePrimaryDriver($backup);

            $manifestLocal = $tempDir.'/'.BackupManifestV3::MANIFEST_FILENAME;
            $driver->download($backup->file_path.'/'.BackupManifestV3::MANIFEST_FILENAME, $manifestLocal);
            $manifest = BackupManifestV3::decode((string) file_get_contents($manifestLocal));

            $hasDatabase = (bool) ($manifest['includes_database'] ?? true);
            $files = [];

            foreach ($manifest['files'] as $entry) {
                $remoteName = $entry['name'] ?? '';
                if (! preg_match('#^chunks/\d+\.zip$#', $remoteName)) {
                    continue;
                }

                $chunkLocal = $tempDir.'/'.str_replace('/', '_', $remoteName);
                $driver->download($backup->file_path.'/'.$remoteName, $chunkLocal);

                foreach (self::listZipContents($chunkLocal) as $file) {
                    $files[] = $file;
                }
                @unlink($chunkLocal);

                if (count($files) > self::MAX_FILES) {
                    break;
                }
            }

            return $this->envelope($files, $hasDatabase);
        } finally {
            $this->cleanup($tempDir);
        }
    }

    /**
     * Legacy single-zip layout: outer zip contains database.sql.gz and an inner
     * `files.zip` / `files.tar.gz` archive holding the WP files.
     *
     * @return array{has_database: bool, has_files: bool, file_count: int, files: array, truncated: bool}
     */
    protected function listLegacyContents(Backup $backup): array
    {
        $tempDir = storage_path('app/temp/browse-'.uniqid());
        mkdir($tempDir, 0755, true);

        try {
            $driver = $this->makePrimaryDriver($backup);
            $localPath = $tempDir.'/'.($backup->file_name ?: 'backup.zip');
            $driver->download($backup->file_path, $localPath);

            $zip = new ZipArchive;
            if ($zip->open($localPath) !== true) {
                throw new \RuntimeException('Failed to open backup archive.');
            }

            $hasDatabase = ($zip->locateName('database.sql.gz') !== false);
            $hasFiles = ($zip->locateName('files.zip') !== false);
            $files = [];

            if ($hasFiles) {
                $innerPath = $tempDir.'/files.zip';
                $zip->extractTo($tempDir, ['files.zip']);
                $zip->close();
                $files = self::listArchiveContents($innerPath);
            } else {
                $zip->close();
            }

            return $this->envelope($files, $hasDatabase);
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
