<?php

namespace App\Jobs;

use App\Jobs\SyncWordPressSite;
use App\Models\Backup;
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
                'restore_status' => 'in_progress',
                'restore_stage' => 'downloading',
                'restore_progress_percent' => 10,
                'restore_progress_message' => 'Downloading backup from storage...',
                'restore_error_message' => null,
            ]);

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

            // Delete the original downloaded zip to free disk/memory
            @unlink($localPath);

            $this->reportRestoreProgress('extracting', 45, 'Backup extracted');

            $api = new WordPressApiService($site);

            // Ensure the WP connector plugin has the restore endpoint
            $this->ensurePluginUpToDate($api);

            // Restore files FIRST (before database), because DB restore changes
            // WP options that can deactivate the plugin and break subsequent API calls
            $filesPath = $this->tempDir . '/files.zip';
            if ($this->restoreFiles && file_exists($filesPath)) {
                // Selective restore: create a smaller archive with only selected files
                if (!empty($this->selectedFiles)) {
                    $this->reportRestoreProgress('restoring_files', 50, 'Preparing selective file restore (' . count($this->selectedFiles) . ' files)...');
                    $filesPath = $this->createSelectiveArchive($filesPath);
                }

                $this->reportRestoreProgress('restoring_files', 55, 'Restoring files...');
                $this->sendRestoreData($api, 'files', $filesPath);
                $this->reportRestoreProgress('restoring_files', 65, 'Files restored');

                // Re-update plugin after file restore (restore overwrites it with old version)
                $this->reportRestoreProgress('restoring_files', 67, 'Updating connector plugin...');
                $this->ensurePluginUpToDate($api);
            }

            // Restore database AFTER files
            $dbPath = $this->tempDir . '/database.sql.gz';
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

            // Update backup record
            $this->backup->update([
                'last_restored_at' => now(),
                'restore_status' => 'completed',
                'restore_stage' => 'completed',
                'restore_progress_percent' => 100,
                'restore_progress_message' => $message,
            ]);

            // Sync site after restore
            SyncWordPressSite::dispatch($site);

        } catch (\Exception $e) {
            Log::error("Restore failed for backup {$this->backup->id}: {$e->getMessage()}");

            $this->backup->update([
                'restore_status' => 'failed',
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
     * Create a selective archive containing only the specified files from the inner archive.
     */
    protected function createSelectiveArchive(string $innerArchivePath): string
    {
        // Detect inner format by magic bytes
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
            // tar.gz: extract selected files to a temp dir, then zip them
            $extractDir = $this->tempDir . '/selective-extract';
            mkdir($extractDir, 0755, true);

            // Extract only specific files from tar.gz
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

            // Create zip from extracted files
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
     * Always uses URL-based transfer to avoid loading file contents into PHP memory
     * (the container has a 512MB memory limit).
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
            ], [], 600);
            $result->throw();
        } finally {
            @unlink($storagePath);
        }
    }

    /**
     * Ensure the WP connector plugin has the restore endpoint.
     * Uses the self-update endpoint if available, otherwise logs a warning.
     */
    protected function ensurePluginUpToDate(WordPressApiService $api): void
    {
        // Check if the restore endpoint exists
        $check = $api->request('POST', '/backup/restore', ['type' => 'database']);
        if ($check->status() !== 404) {
            return; // Endpoint exists (even if it returns 400 for missing data)
        }

        Log::info("Restore endpoint missing, attempting plugin update for backup {$this->backup->id}");

        // Try the self-update endpoint
        $zipUrl = rtrim(config('app.url'), '/') . '/download/connector-plugin';
        $update = $api->request('POST', '/self-update', [
            'download_url' => $zipUrl,
        ], [], 120);

        if ($update->successful()) {
            Log::info("Plugin updated successfully for backup {$this->backup->id}");
            // Wait briefly for WP to reload
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
