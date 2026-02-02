<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\WordPressApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class RestoreBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    protected ?string $tempDir = null;

    public function __construct(
        public Backup $backup,
    ) {}

    public function handle(): void
    {
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
            $this->reportRestoreProgress('extracting', 45, 'Backup extracted');

            $api = new WordPressApiService($site);

            // Restore database
            $dbPath = $this->tempDir . '/database.sql.gz';
            if (file_exists($dbPath)) {
                $this->reportRestoreProgress('restoring_database', 50, 'Restoring database...');
                $dbData = base64_encode(file_get_contents($dbPath));
                $result = $api->request('POST', '/backup/restore', [
                    'type' => 'database',
                    'data' => $dbData,
                ]);
                $result->throw();
                $this->reportRestoreProgress('restoring_database', 65, 'Database restored');
            }

            // Restore files
            $filesPath = $this->tempDir . '/files.zip';
            if (file_exists($filesPath)) {
                $this->reportRestoreProgress('restoring_files', 70, 'Restoring files...');
                $filesData = base64_encode(file_get_contents($filesPath));
                $result = $api->request('POST', '/backup/restore', [
                    'type' => 'files',
                    'data' => $filesData,
                ]);
                $result->throw();
                $this->reportRestoreProgress('restoring_files', 85, 'Files restored');
            }

            // Sync site data
            $this->reportRestoreProgress('syncing', 95, 'Syncing site data...');

            // Update backup record
            $this->backup->update([
                'last_restored_at' => now(),
                'restore_status' => 'completed',
                'restore_stage' => 'completed',
                'restore_progress_percent' => 100,
                'restore_progress_message' => 'Restore completed successfully',
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
