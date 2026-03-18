<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Jobs\SyncWordPressSite;
use App\Models\Backup;
use App\Services\Backup\ManifestService;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\WordPressApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class RestoreBackup implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    protected ?string $tempDir = null;

    public function __construct(
        public Backup $backup,
        public bool $restoreDatabase = true,
        public bool $restoreFiles = true,
        public array $selectedFiles = [],
    ) {
        $this->onQueue('backups');
    }

    public function uniqueId(): string
    {
        return 'restore-' . $this->backup->id;
    }

    public function handle(): void
    {
        ini_set('memory_limit', '1G');

        $this->tempDir = storage_path('app/temp/restore-' . uniqid());
        mkdir($this->tempDir, 0755, true);

        try {
            $this->backup->update([
                'restore_status' => BackupStatus::InProgress,
                'restore_stage' => 'downloading',
                'restore_progress_percent' => 10,
                'restore_progress_message' => 'Downloading backup from storage...',
                'restore_error_message' => null,
            ]);

            // Check if this is an incremental backup needing chain restore
            if ($this->backup->isIncremental()) {
                $this->restoreFromChain();
            } else {
                $this->restoreSingleBackup();
            }

        } catch (\Exception $e) {
            Log::error("Restore failed for backup {$this->backup->id}", [
                'exception' => get_class($e),
                'code' => $e->getCode(),
            ]);

            $this->backup->update([
                'restore_status' => BackupStatus::Failed,
                'restore_stage' => 'failed',
                'restore_progress_message' => 'Restore failed: ' . Str::limit($e->getMessage(), 200),
                'restore_error_message' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Restore a single (non-incremental) backup — original flow.
     */
    protected function restoreSingleBackup(): void
    {
        $site = $this->backup->site;
        $destination = $this->backup->storageDestination;

        if (!$destination || !$this->backup->file_path) {
            throw new \RuntimeException('Backup storage destination or file path is missing.');
        }

        // Download from storage
        $localPath = $this->tempDir . '/' . $this->backup->file_name;
        $driver = StorageFactory::make($destination);
        $driver->download($this->backup->file_path, $localPath);

        $this->reportRestoreProgress('downloading', 25, 'Backup downloaded');

        // Verify checksum
        if ($this->backup->checksum) {
            $this->reportRestoreProgress('verifying', 30, 'Verifying backup integrity...');
            $hash = hash_file('sha256', $localPath);
            if ($hash !== $this->backup->checksum) {
                throw new \RuntimeException('Backup checksum verification failed. The file may be corrupted.');
            }
            $this->reportRestoreProgress('verifying', 35, 'Backup integrity verified');
        }

        // Extract zip
        $this->reportRestoreProgress('extracting', 40, 'Extracting backup archive...');
        $zip = new ZipArchive();
        if ($zip->open($localPath) !== true) {
            throw new \RuntimeException('Failed to open backup archive.');
        }
        $zip->extractTo($this->tempDir);
        $zip->close();
        @unlink($localPath);

        $this->reportRestoreProgress('extracting', 45, 'Backup extracted');

        $api = new WordPressApiService($site);
        $this->ensurePluginUpToDate($api);
        $this->doRestore($api, $this->tempDir);
    }

    /**
     * Restore from an incremental backup chain.
     * Downloads full + all incrementals, merges them, then restores.
     */
    protected function restoreFromChain(): void
    {
        $manifestService = new ManifestService();
        $chain = $manifestService->getChain($this->backup);
        $chainLength = count($chain);

        $this->reportRestoreProgress('downloading', 10, "Restoring from chain of {$chainLength} backups...");

        $mergedDir = $this->tempDir . '/merged';
        mkdir($mergedDir, 0755, true);

        $latestDbPath = null;
        $allDeletedPaths = [];

        // Process each backup in the chain
        foreach ($chain as $i => $chainBackup) {
            $stepNum = $i + 1;
            $pct = 10 + (int) (($stepNum / $chainLength) * 50); // 10-60%

            $destination = $chainBackup->storageDestination;
            if (!$destination || !$chainBackup->file_path) {
                throw new \RuntimeException("Backup #{$chainBackup->id} in chain has no file path.");
            }

            $this->reportRestoreProgress('downloading', $pct,
                "Downloading backup {$stepNum}/{$chainLength}...");

            // Download
            $localPath = $this->tempDir . '/chain_' . $i . '.zip';
            $driver = StorageFactory::make($destination);
            $driver->download($chainBackup->file_path, $localPath);

            // Verify checksum
            if ($chainBackup->checksum) {
                $hash = hash_file('sha256', $localPath);
                if ($hash !== $chainBackup->checksum) {
                    throw new \RuntimeException("Checksum mismatch for backup #{$chainBackup->id} in chain (expected {$chainBackup->checksum}, got {$hash}).");
                }
            }

            // Extract to temp dir
            $extractDir = $this->tempDir . '/extract_' . $i;
            mkdir($extractDir, 0755, true);

            $zip = new ZipArchive();
            if ($zip->open($localPath) !== true) {
                throw new \RuntimeException("Failed to open backup #{$chainBackup->id} archive.");
            }
            $zip->extractTo($extractDir);
            $zip->close();
            @unlink($localPath);

            if ($i === 0) {
                // Full backup: check for v2 format (chunk zips) or v1 (single files.zip)
                if ($this->restoreFiles) {
                    $this->mergeChunkZipsForRestore($extractDir);
                    $filesZip = $extractDir . '/files.zip';
                    if (file_exists($filesZip)) {
                        $fz = new ZipArchive();
                        if ($fz->open($filesZip) === true) {
                            $fz->extractTo($mergedDir);
                            $fz->close();
                        }
                    }
                }
            } else {
                // Incremental: overlay changed files, apply deletions
                $filesZip = $extractDir . '/files.zip';
                if (file_exists($filesZip) && $this->restoreFiles) {
                    $fz = new ZipArchive();
                    if ($fz->open($filesZip) === true) {
                        $fz->extractTo($mergedDir); // Overwrites existing files
                        $fz->close();
                    }
                }

                // Apply deletions
                $deletedFile = $extractDir . '/deleted-files.json';
                if (file_exists($deletedFile)) {
                    $deletedPaths = json_decode(file_get_contents($deletedFile), true) ?? [];
                    foreach ($deletedPaths as $path) {
                        $fullPath = $mergedDir . '/' . $path;
                        if (file_exists($fullPath)) {
                            @unlink($fullPath);
                        }
                    }
                    $allDeletedPaths = array_merge($allDeletedPaths, $deletedPaths);
                }
            }

            // Always use the latest database dump
            $dbFile = $extractDir . '/database.sql.gz';
            if (file_exists($dbFile)) {
                $latestDbPath = $dbFile;
            }
        }

        $this->reportRestoreProgress('merging', 65, 'Preparing merged files for restore...');

        // Create merged files.zip from the merged directory
        $mergedFilesZip = $this->tempDir . '/files.zip';
        if ($this->restoreFiles && is_dir($mergedDir)) {
            $zip = new ZipArchive();
            if ($zip->open($mergedFilesZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($mergedDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relative = substr($file->getRealPath(), strlen($mergedDir) + 1);
                        $zip->addFile($file->getRealPath(), $relative);
                    }
                }
                $zip->close();
            }
        }

        // Copy latest DB to expected location
        $finalDbPath = $this->tempDir . '/database.sql.gz';
        if ($latestDbPath && file_exists($latestDbPath)) {
            copy($latestDbPath, $finalDbPath);
        }

        $this->reportRestoreProgress('restoring', 70, 'Sending restored data to WordPress...');

        $site = $this->backup->site;
        $api = new WordPressApiService($site);
        $this->ensurePluginUpToDate($api);
        $this->doRestore($api, $this->tempDir);
    }

    /**
     * If the backup uses v2 format (multiple chunk zips), merge them into a single files.zip for restore.
     */
    protected function mergeChunkZipsForRestore(string $baseDir): void
    {
        $metaFile = $baseDir . '/backup-meta.json';
        if (!file_exists($metaFile)) {
            return;
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        if (empty($meta['format_version']) || $meta['format_version'] < 2 || empty($meta['chunk_files'])) {
            return;
        }

        // v2 format: merge chunk zips into a single files.zip for restore
        $filesZip = $baseDir . '/files.zip';
        $extractDir = $baseDir . '/files_extract_' . uniqid();
        mkdir($extractDir, 0755, true);

        try {
            foreach ($meta['chunk_files'] as $chunkName) {
                $chunkPath = $baseDir . '/' . $chunkName;
                if (!file_exists($chunkPath)) {
                    continue;
                }

                $chunkZip = new ZipArchive();
                if ($chunkZip->open($chunkPath) === true) {
                    $chunkZip->extractTo($extractDir);
                    $chunkZip->close();
                }
                @unlink($chunkPath);
            }

            $zip = new ZipArchive();
            if ($zip->open($filesZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relative = substr($file->getRealPath(), strlen($extractDir) + 1);
                        $zip->addFile($file->getRealPath(), $relative);
                    }
                }
                $zip->close();
            }
        } finally {
            // Clean up extract directory
            if (is_dir($extractDir)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($files as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($extractDir);
            }
        }
    }

    /**
     * Common restore logic: send files and/or DB to WordPress.
     */
    protected function doRestore(WordPressApiService $api, string $baseDir): void
    {
        // Handle v2 format: merge chunk zips into files.zip
        $this->mergeChunkZipsForRestore($baseDir);

        // Restore files FIRST (before database)
        $filesPath = $baseDir . '/files.zip';
        if ($this->restoreFiles && file_exists($filesPath)) {
            if (!empty($this->selectedFiles)) {
                $this->reportRestoreProgress('restoring_files', 50, 'Preparing selective file restore (' . count($this->selectedFiles) . ' files)...');
                $filesPath = $this->createSelectiveArchive($filesPath);
            }

            $this->reportRestoreProgress('restoring_files', 55, 'Restoring files...');
            $this->sendRestoreData($api, 'files', $filesPath);
            $this->reportRestoreProgress('restoring_files', 65, 'Files restored');

            $this->reportRestoreProgress('restoring_files', 67, 'Updating connector plugin...');
            $this->ensurePluginUpToDate($api);
        }

        // Restore database AFTER files
        $dbPath = $baseDir . '/database.sql.gz';
        if ($this->restoreDatabase && file_exists($dbPath)) {
            $this->reportRestoreProgress('restoring_database', 70, 'Restoring database...');
            $this->sendRestoreData($api, 'database', $dbPath);
            $this->reportRestoreProgress('restoring_database', 85, 'Database restored');
        }

        // Sync site data
        $this->reportRestoreProgress('syncing', 95, 'Syncing site data...');

        $message = 'Restore completed successfully';
        if (!empty($this->selectedFiles)) {
            $message = 'Selective restore completed (' . count($this->selectedFiles) . ' files restored)';
        }
        if ($this->backup->isIncremental()) {
            $manifestService = new ManifestService();
            $chainLength = count($manifestService->getChain($this->backup));
            $message = "Chain restore completed ({$chainLength} backups merged)";
        }

        $this->backup->update([
            'last_restored_at' => now(),
            'restore_status' => BackupStatus::Completed,
            'restore_stage' => 'completed',
            'restore_progress_percent' => 100,
            'restore_progress_message' => $message,
        ]);

        SyncWordPressSite::dispatch($this->backup->site);
    }

    /**
     * Create a selective archive containing only the specified files from the inner archive.
     */
    protected function createSelectiveArchive(string $innerArchivePath): string
    {
        $fh = fopen($innerArchivePath, 'rb');
        $magic = fread($fh, 2);
        fclose($fh);

        $isZip = ($magic === "PK");

        $selectivePath = $this->tempDir . '/selective-files.zip';
        $selectedLookup = array_flip($this->selectedFiles);

        if ($isZip) {
            $source = new ZipArchive();
            if ($source->open($innerArchivePath) !== true) {
                throw new \RuntimeException('Failed to open inner files archive for selective restore.');
            }

            $dest = new ZipArchive();
            if ($dest->open($selectivePath, ZipArchive::CREATE) !== true) {
                $source->close();
                throw new \RuntimeException('Failed to create selective archive.');
            }

            for ($i = 0; $i < $source->numFiles; $i++) {
                $name = $source->getNameIndex($i);
                if (isset($selectedLookup[$name])) {
                    $dest->addFromString($name, $source->getFromIndex($i));
                }
            }

            $dest->close();
            $source->close();
        } else {
            $extractDir = $this->tempDir . '/selective-extract';
            mkdir($extractDir, 0755, true);

            $cmd = ['tar', 'xzf', $innerArchivePath, '-C', $extractDir];
            foreach ($this->selectedFiles as $file) {
                $cmd[] = './' . ltrim($file, './');
            }

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($cmd, $descriptors, $pipes);
            if (is_resource($process)) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }

            $dest = new ZipArchive();
            if ($dest->open($selectivePath, ZipArchive::CREATE) !== true) {
                throw new \RuntimeException('Failed to create selective archive from tar.gz.');
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extractDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $relative = substr($file->getRealPath(), strlen($extractDir) + 1);
                    $dest->addFile($file->getRealPath(), $relative);
                }
            }

            $dest->close();
        }

        return $selectivePath;
    }

    /**
     * Send restore data to the WP site via temporary download URL.
     */
    protected function sendRestoreData(WordPressApiService $api, string $type, string $filePath): void
    {
        $token = bin2hex(random_bytes(32));
        $storagePath = storage_path("app/temp/restore-{$token}");

        try {
            copy($filePath, $storagePath);
            $downloadUrl = rtrim(config('app.url'), '/') . '/restore-download/' . $token;

            $result = $api->request('POST', '/backup/restore', [
                'type' => $type,
                'download_url' => $downloadUrl,
            ], [], 1200);
            $result->throw();
        } finally {
            @unlink($storagePath);
        }
    }

    /**
     * Ensure the WP connector plugin has the restore endpoint.
     */
    protected function ensurePluginUpToDate(WordPressApiService $api): void
    {
        $check = $api->request('POST', '/backup/restore', ['type' => 'database']);
        if ($check->status() !== 404) {
            return;
        }

        Log::info("Restore endpoint missing, attempting plugin update for backup {$this->backup->id}");

        $zipUrl = rtrim(config('app.url'), '/') . '/download/connector-plugin';
        $update = $api->request('POST', '/self-update', [
            'download_url' => $zipUrl,
        ], [], 120);

        if ($update->successful()) {
            Log::info("Plugin updated successfully for backup {$this->backup->id}");
            sleep(2);
            return;
        }

        Log::warning("Could not auto-update plugin for backup {$this->backup->id}: {$update->status()} {$update->body()}");
        throw new \RuntimeException(
            'The WordPress connector plugin on the remote site does not support the restore endpoint. ' .
            'Please update the plugin manually via WP Admin > Plugins > Upload.'
        );
    }

    protected function reportRestoreProgress(string $stage, int $percent, string $message): void
    {
        $this->backup->update([
            'restore_stage' => $stage,
            'restore_progress_percent' => $percent,
            'restore_progress_message' => $message,
        ]);
    }

    protected function cleanup(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tempDir);
        }
    }
}
