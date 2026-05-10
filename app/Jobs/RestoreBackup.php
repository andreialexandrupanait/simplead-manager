<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Enums\BackupStatus;
use App\Helpers\FormatHelper;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\ManifestService;
use App\Services\Backup\PostRestoreVerifier;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\JobTracker;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class RestoreBackup implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $memory = 1024;

    protected ?string $tempDir = null;

    protected bool $pluginWasUpdated = false;

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
        return 'restore-'.$this->backup->id;
    }

    public function handle(): void
    {
        ini_set('memory_limit', '1G');

        $this->tempDir = storage_path('app/temp/restore-'.uniqid());
        mkdir($this->tempDir, 0755, true);

        try {
            $this->backup->update([
                'restore_status' => BackupStatus::InProgress,
                'restore_stage' => 'downloading',
                'restore_progress_percent' => 10,
                'restore_progress_message' => 'Downloading backup from storage...',
                'restore_error_message' => null,
            ]);
            JobTracker::start($this->uniqueId(), 'Starting restore...');
            $this->logRestoreStep('Downloading backup from storage...');

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

            $this->logRestoreStep("FAILED: {$e->getMessage()}");
            JobTracker::fail($this->uniqueId(), 'Restore failed: '.get_class($e));

            $this->backup->update([
                'restore_status' => BackupStatus::Failed,
                'restore_stage' => 'failed',
                'restore_progress_message' => 'Restore failed: '.Str::limit($e->getMessage(), 200),
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
        /** @var Site $site */
        $site = $this->backup->site;
        /** @var StorageDestination|null $destination */
        $destination = $this->backup->storageDestination;

        if (! $destination || ! $this->backup->file_path) {
            throw new \RuntimeException('Backup storage destination or file path is missing.');
        }

        // Download from storage
        $localPath = $this->tempDir.'/'.$this->backup->file_name;
        $driver = StorageFactory::make($destination);
        $driver->download($this->backup->file_path, $localPath);

        $downloadSize = file_exists($localPath) ? FormatHelper::bytes((int) filesize($localPath)) : '0 B';
        $this->logRestoreStep("Backup downloaded ({$downloadSize})");
        $this->reportRestoreProgress('downloading', 25, 'Backup downloaded');

        // Verify checksum
        if ($this->backup->checksum) {
            $this->reportRestoreProgress('verifying', 30, 'Verifying backup integrity...');
            $this->logRestoreStep('Verifying backup integrity (SHA256)...');
            $hash = hash_file('sha256', $localPath);
            if ($hash !== $this->backup->checksum) {
                throw new \RuntimeException('Backup checksum verification failed. The file may be corrupted.');
            }
            $this->logRestoreStep('Integrity verified');
            $this->reportRestoreProgress('verifying', 35, 'Backup integrity verified');
        }

        // Extract zip
        $this->reportRestoreProgress('extracting', 40, 'Extracting backup archive...');
        $this->logRestoreStep('Extracting backup archive...');
        $zip = new ZipArchive;
        if ($zip->open($localPath) !== true) {
            throw new \RuntimeException('Failed to open backup archive.');
        }
        $zip->extractTo($this->tempDir);
        $zip->close();
        @unlink($localPath);

        $this->logRestoreStep('Backup extracted');
        $this->reportRestoreProgress('extracting', 45, 'Backup extracted');

        $api = app(WordPressApiServiceFactory::class)->make($site);
        $this->ensurePluginUpToDate($api);
        $this->doRestore($api, $this->tempDir);
    }

    /**
     * Restore from an incremental backup chain.
     * Downloads full + all incrementals, merges them, then restores.
     */
    protected function restoreFromChain(): void
    {
        $manifestService = app(ManifestService::class);
        $chain = $manifestService->getChain($this->backup);
        $chainLength = count($chain);

        $this->reportRestoreProgress('downloading', 10, "Restoring from chain of {$chainLength} backups...");
        $this->logRestoreStep("Restoring from chain of {$chainLength} backups...");

        $mergedDir = $this->tempDir.'/merged';
        mkdir($mergedDir, 0755, true);

        $latestDbPath = null;
        $allDeletedPaths = [];

        // Process each backup in the chain
        foreach ($chain as $i => $chainBackup) {
            $stepNum = $i + 1;
            $pct = 10 + (int) (($stepNum / $chainLength) * 50); // 10-60%

            /** @var StorageDestination|null $destination */
            $destination = $chainBackup->storageDestination;
            if (! $destination || ! $chainBackup->file_path) {
                throw new \RuntimeException("Backup #{$chainBackup->id} in chain has no file path.");
            }

            $this->reportRestoreProgress('downloading', $pct,
                "Downloading backup {$stepNum}/{$chainLength}...");
            $this->logRestoreStep("Downloading backup {$stepNum}/{$chainLength}...");

            // Download
            $localPath = $this->tempDir.'/chain_'.$i.'.zip';
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
            $extractDir = $this->tempDir.'/extract_'.$i;
            mkdir($extractDir, 0755, true);

            $zip = new ZipArchive;
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
                    $filesZip = $extractDir.'/files.zip';
                    if (file_exists($filesZip)) {
                        $fz = new ZipArchive;
                        if ($fz->open($filesZip) === true) {
                            $fz->extractTo($mergedDir);
                            $fz->close();
                        }
                    }
                }
            } else {
                // Incremental: overlay changed files, apply deletions
                $filesZip = $extractDir.'/files.zip';
                if (file_exists($filesZip) && $this->restoreFiles) {
                    $fz = new ZipArchive;
                    if ($fz->open($filesZip) === true) {
                        $fz->extractTo($mergedDir); // Overwrites existing files
                        $fz->close();
                    }
                }

                // Apply deletions
                $deletedFile = $extractDir.'/deleted-files.json';
                if (file_exists($deletedFile)) {
                    $deletedPaths = json_decode(file_get_contents($deletedFile), true) ?? [];
                    foreach ($deletedPaths as $path) {
                        $fullPath = $mergedDir.'/'.$path;
                        if (file_exists($fullPath)) {
                            @unlink($fullPath);
                        }
                    }
                    $allDeletedPaths = array_merge($allDeletedPaths, $deletedPaths);
                }
            }

            // Always use the latest database dump
            $dbFile = $extractDir.'/database.sql.gz';
            if (file_exists($dbFile)) {
                $latestDbPath = $dbFile;
            }
        }

        $this->logRestoreStep('Merging incremental chain...');
        $this->reportRestoreProgress('merging', 65, 'Preparing merged files for restore...');

        // Create merged files.zip from the merged directory
        $mergedFilesZip = $this->tempDir.'/files.zip';
        if ($this->restoreFiles && is_dir($mergedDir)) {
            $zip = new ZipArchive;
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
        $finalDbPath = $this->tempDir.'/database.sql.gz';
        if ($latestDbPath && file_exists($latestDbPath)) {
            copy($latestDbPath, $finalDbPath);
        }

        $this->logRestoreStep('Merged files ready');
        $this->reportRestoreProgress('restoring', 70, 'Sending restored data to WordPress...');

        /** @var Site $site */
        $site = $this->backup->site;
        $api = app(WordPressApiServiceFactory::class)->make($site);
        $this->ensurePluginUpToDate($api);
        $this->doRestore($api, $this->tempDir);
    }

    /**
     * If the backup uses v2 format (multiple chunk zips), merge them into a single files.zip for restore.
     */
    protected function mergeChunkZipsForRestore(string $baseDir): void
    {
        $metaFile = $baseDir.'/backup-meta.json';
        if (! file_exists($metaFile)) {
            return;
        }

        $meta = json_decode(file_get_contents($metaFile), true);
        if (empty($meta['format_version']) || $meta['format_version'] < 2 || empty($meta['chunk_files'])) {
            return;
        }

        // v2 format: merge chunk zips into a single files.zip for restore
        $filesZip = $baseDir.'/files.zip';
        $extractDir = $baseDir.'/files_extract_'.uniqid();
        mkdir($extractDir, 0755, true);

        try {
            foreach ($meta['chunk_files'] as $chunkName) {
                $chunkPath = $baseDir.'/'.$chunkName;
                if (! file_exists($chunkPath)) {
                    continue;
                }

                $chunkZip = new ZipArchive;
                if ($chunkZip->open($chunkPath) === true) {
                    $chunkZip->extractTo($extractDir);
                    $chunkZip->close();
                }
                @unlink($chunkPath);
            }

            $zip = new ZipArchive;
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
    protected function doRestore(WordPressApiServiceInterface $api, string $baseDir): void
    {
        // Handle v2 format: merge chunk zips into files.zip
        $this->mergeChunkZipsForRestore($baseDir);

        // Restore files FIRST (before database)
        $filesPath = $baseDir.'/files.zip';
        if ($this->restoreFiles && file_exists($filesPath)) {
            if (! empty($this->selectedFiles)) {
                $this->reportRestoreProgress('restoring_files', 50, 'Preparing selective file restore ('.count($this->selectedFiles).' files)...');
                $filesPath = $this->createSelectiveArchive($filesPath);
            }

            $this->reportRestoreProgress('restoring_files', 55, 'Restoring files...');
            $this->logRestoreStep('Restoring files to WordPress...');
            $this->sendRestoreData($api, 'files', $filesPath);
            $this->logRestoreStep('Files restored');
            $this->reportRestoreProgress('restoring_files', 65, 'Files restored');

            $this->reportRestoreProgress('restoring_files', 67, 'Updating connector plugin...');
            $this->logRestoreStep('Updating connector plugin...');
            $pluginUpdated = $this->ensurePluginUpToDate($api);
            if (! $pluginUpdated) {
                Log::warning("Continuing restore without plugin update for backup {$this->backup->id}");
            }
        }

        // Restore database AFTER files
        $dbPath = $baseDir.'/database.sql.gz';
        if ($this->restoreDatabase && file_exists($dbPath)) {
            $this->reportRestoreProgress('restoring_database', 70, 'Restoring database...');
            $this->logRestoreStep('Restoring database...');
            $this->sendRestoreData($api, 'database', $dbPath);
            $this->logRestoreStep('Database restored');
            $this->reportRestoreProgress('restoring_database', 85, 'Database restored');
        }

        // Post-restore verification
        $verifier = app(PostRestoreVerifier::class);
        $verificationSummary = $verifier->verify(
            $api,
            $this->backup,
            $this->pluginWasUpdated,
            fn (string $stage, int $percent, string $message) => $this->reportRestoreProgress($stage, $percent, $message),
        );

        $message = 'Restore completed successfully';
        if (! empty($this->selectedFiles)) {
            $message = 'Selective restore completed ('.count($this->selectedFiles).' files restored)';
        }
        if ($this->backup->isIncremental()) {
            $manifestService = app(ManifestService::class);
            $chainLength = count($manifestService->getChain($this->backup));
            $message = "Chain restore completed ({$chainLength} backups merged)";
        }

        if ($verificationSummary) {
            $message .= ' — '.$verificationSummary;
        }

        $this->backup->update([
            'last_restored_at' => now(),
            'restore_status' => BackupStatus::Completed,
            'restore_stage' => 'completed',
            'restore_progress_percent' => 100,
            'restore_progress_message' => Str::limit($message, 252),
        ]);

        $this->logRestoreStep('Restore completed');
        JobTracker::complete($this->uniqueId(), 'Restore complete');

        /** @var Site $backupSite */
        $backupSite = $this->backup->site;
        SyncWordPressSite::dispatch($backupSite);
    }

    /**
     * Create a selective archive containing only the specified files from the inner archive.
     */
    protected function createSelectiveArchive(string $innerArchivePath): string
    {
        $fh = fopen($innerArchivePath, 'rb');
        $magic = fread($fh, 2);
        fclose($fh);

        $isZip = ($magic === 'PK');

        $selectivePath = $this->tempDir.'/selective-files.zip';
        $selectedLookup = array_flip($this->selectedFiles);

        if ($isZip) {
            $source = new ZipArchive;
            if ($source->open($innerArchivePath) !== true) {
                throw new \RuntimeException('Failed to open inner files archive for selective restore.');
            }

            $dest = new ZipArchive;
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
            $extractDir = $this->tempDir.'/selective-extract';
            mkdir($extractDir, 0755, true);

            $cmd = ['tar', 'xzf', $innerArchivePath, '-C', $extractDir];
            foreach ($this->selectedFiles as $file) {
                $cmd[] = './'.ltrim($file, './');
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

            $dest = new ZipArchive;
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
    protected function sendRestoreData(WordPressApiServiceInterface $api, string $type, string $filePath): void
    {
        $token = bin2hex(random_bytes(32));
        $storagePath = storage_path("app/temp/restore-{$token}");

        try {
            copy($filePath, $storagePath);
            $downloadUrl = rtrim(config('app.url'), '/').'/restore-download/'.$token;

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
     * Push the latest connector plugin to the WP site.
     *
     * After file restore the backup's old plugin overwrites the current one,
     * so we push the latest version to ensure new endpoints (e.g. fix-elementor)
     * are available for post-restore verification.
     *
     * Retries up to 3 times. Never throws — returns false on failure so the
     * restore can continue even if the plugin update fails.
     */
    protected function ensurePluginUpToDate(WordPressApiServiceInterface $api): bool
    {
        Log::info("Pushing latest connector plugin for backup {$this->backup->id}");

        $zipUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'download.connector-plugin.signed',
            now()->addMinutes(30)
        );

        $lastError = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $update = $api->request('POST', '/self-update', [
                    'download_url' => $zipUrl,
                ], [], 120);

                if ($update->successful()) {
                    Log::info("Plugin updated successfully for backup {$this->backup->id} (attempt {$attempt})");
                    $this->pluginWasUpdated = true;
                    sleep(2);

                    return true;
                }

                $lastError = "{$update->status()} {$update->body()}";
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
            }

            Log::warning("Plugin update attempt {$attempt}/3 failed for backup {$this->backup->id}: {$lastError}");

            if ($attempt < 3) {
                sleep(3);
            }
        }

        Log::error("All plugin update attempts failed for backup {$this->backup->id}: {$lastError}");

        return false;
    }

    protected function logRestoreStep(string $message): void
    {
        JobTracker::appendLog($this->uniqueId(), $message);
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
