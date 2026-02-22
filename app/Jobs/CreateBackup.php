<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupBrowserService;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\ActivityLogger;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
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

class CreateBackup implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 2;
    public array $backoff = [120];

    protected ?Backup $backup = null;
    protected ?string $tempDir = null;

    public function __construct(
        public Site $site,
        public string $type = 'full',
        public string $trigger = 'manual',
        public ?int $storageDestinationId = null,
        public ?int $backupId = null,
    ) {
        $this->onQueue('backups');
    }

    public function uniqueId(): string
    {
        return 'backup-' . $this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Creating backup...');

        $this->tempDir = storage_path('app/temp/backup-' . uniqid());
        mkdir($this->tempDir, 0755, true);

        try {
            $destination = $this->resolveStorageDestination();
            if (!$destination) {
                throw new \RuntimeException('No storage destination available. Configure a storage destination first.');
            }

            $this->prepare($destination);
            [$dbPath, $filesPath] = $this->downloadData();
            [$combinedPath, $fileName, $fileSize, $checksum] = $this->createArchive($dbPath, $filesPath);
            $remotePath = $this->upload($destination, $combinedPath, $fileName);
            $this->finalize($destination, $remotePath, $fileName, $fileSize, $checksum);

        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    protected function prepare(StorageDestination $destination): void
    {
        if ($this->backupId) {
            $this->backup = Backup::findOrFail($this->backupId);
            $this->backup->update([
                'status' => BackupStatus::InProgress,
                'stage' => 'initializing',
                'started_at' => $this->backup->started_at ?? now(),
            ]);
        } else {
            $this->backup = Backup::create([
                'site_id' => $this->site->id,
                'storage_destination_id' => $destination->id,
                'type' => $this->type,
                'trigger' => $this->trigger,
                'status' => BackupStatus::InProgress,
                'includes_database' => true,
                'includes_files' => $this->type === 'full',
                'wp_version' => $this->site->wp_version,
                'php_version' => $this->site->php_version,
                'plugins_count' => $this->site->sitePlugins()->count(),
                'themes_count' => $this->site->siteThemes()->count(),
                'db_size_mb' => $this->site->db_size_mb,
                'started_at' => now(),
            ]);
        }

        $this->reportProgress('initializing', 5, 'Initializing backup...');
    }

    protected function downloadData(): array
    {
        $api = new WordPressApiService($this->site);

        $this->reportProgress('downloading_database', 10, 'Downloading database...');
        $dbPath = $this->tempDir . '/database.sql.gz';
        $api->streamDownload('backup/db', $dbPath);
        $this->reportProgress('downloading_database', $this->type === 'full' ? 25 : 40, 'Database downloaded');

        $filesPath = null;
        if ($this->type === 'full') {
            $this->reportProgress('downloading_files', 30, 'Downloading files...');
            $filesPath = $this->tempDir . '/files.zip';
            $api->streamDownload('backup/files', $filesPath);
            $this->reportProgress('downloading_files', 55, 'Files downloaded');
        }

        return [$dbPath, $filesPath];
    }

    protected function createArchive(string $dbPath, ?string $filesPath): array
    {
        $timestamp = now()->format('Y-m-d-His');
        $fileName = "{$this->site->domain}-{$this->type}-{$timestamp}.zip";
        $combinedPath = $this->tempDir . '/' . $fileName;

        $this->reportProgress('creating_archive', $this->type === 'full' ? 60 : 50, 'Creating backup archive...');

        $zip = new ZipArchive();
        if ($zip->open($combinedPath, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Failed to create backup archive.');
        }

        $zip->addFile($dbPath, 'database.sql.gz');
        if ($filesPath && file_exists($filesPath)) {
            $zip->addFile($filesPath, 'files.zip');
        }

        $zip->addFromString('backup-meta.json', json_encode([
            'site_name' => $this->site->name,
            'site_url' => $this->site->url,
            'type' => $this->type,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'created_at' => now()->toIso8601String(),
            'trigger' => $this->trigger,
        ], JSON_PRETTY_PRINT));
        $zip->close();

        $this->reportProgress('creating_archive', 70, 'Archive created');

        $fileSize = filesize($combinedPath);
        $checksum = hash_file('sha256', $combinedPath);

        BackupBrowserService::precache($this->backup->id, $filesPath, true);

        return [$combinedPath, $fileName, $fileSize, $checksum];
    }

    protected function upload(StorageDestination $destination, string $combinedPath, string $fileName): string
    {
        $this->reportProgress('uploading', 75, 'Uploading to storage...');
        $remotePath = $this->site->domain . '/' . $fileName;
        $driver = StorageFactory::make($destination);
        $driver->upload($combinedPath, $remotePath);
        $this->reportProgress('finalizing', 95, 'Finalizing...');

        return $remotePath;
    }

    protected function finalize(StorageDestination $destination, string $remotePath, string $fileName, int $fileSize, string $checksum): void
    {
        $this->backup->update([
            'status' => BackupStatus::Completed,
            'stage' => 'completed',
            'progress_percent' => 100,
            'progress_message' => 'Backup completed successfully',
            'file_path' => $remotePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'checksum' => $checksum,
            'completed_at' => now(),
            'duration_seconds' => (int) $this->backup->started_at->diffInSeconds(now()),
            'is_locked' => $this->trigger === 'pre_update',
            'lock_reason' => $this->trigger === 'pre_update' ? 'pre-update' : null,
        ]);

        ActivityLogger::backupCompleted($this->site, $fileName, $fileSize);

        $this->site->update([
            'backup_ok' => true,
            'last_backup_at' => now(),
        ]);

        $config = $this->site->backupConfig;
        if ($config) {
            $config->update([
                'last_backup_at' => now(),
                'last_backup_status' => 'completed',
            ]);
        }

        $destination->increment('used_bytes', $fileSize);
        $this->applyRetention($destination);

        CircuitBreakerService::recordSuccess($this->site);
        JobTracker::complete($this->uniqueId(), 'Backup complete');
    }

    protected function handleFailure(\Exception $e): void
    {
        Log::error("Backup failed for site {$this->site->id}", [
            'exception' => get_class($e),
            'code' => $e->getCode(),
        ]);

        if ($this->backup) {
            $this->backup->update([
                'status' => BackupStatus::Failed,
                'stage' => 'failed',
                'progress_message' => 'Backup failed: ' . Str::limit($e->getMessage(), 200),
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => (int) $this->backup->started_at->diffInSeconds(now()),
            ]);
        }

        $config = $this->site->backupConfig;
        if ($config) {
            $config->update(['last_backup_status' => 'failed']);
        }

        $this->site->update(['backup_ok' => false]);

        if ($this->backup) {
            NotifyBackupFailed::dispatch($this->site, $this->backup, $e->getMessage());
        }

        ActivityLogger::backupFailed($this->site, $e->getMessage());
    }

    protected function reportProgress(string $stage, int $percent, string $message): void
    {
        if ($this->backup) {
            $this->backup->update([
                'stage' => $stage,
                'progress_percent' => $percent,
                'progress_message' => $message,
            ]);
        }

        JobTracker::progress($this->uniqueId(), $percent, $message);
    }

    protected function resolveStorageDestination(): ?StorageDestination
    {
        // Explicit destination
        if ($this->storageDestinationId) {
            return StorageDestination::find($this->storageDestinationId);
        }

        // Site config destination
        $config = $this->site->backupConfig;
        if ($config?->storage_destination_id) {
            return StorageDestination::find($config->storage_destination_id);
        }

        // Default destination (fall back to any active if no default set)
        return StorageDestination::where('is_default', true)
            ->where('is_active', true)
            ->first()
            ?? StorageDestination::where('is_active', true)->first();
    }

    protected function applyRetention(StorageDestination $destination): void
    {
        $config = $this->site->backupConfig;
        if (!$config) {
            return;
        }

        $query = Backup::where('site_id', $this->site->id)
            ->where('status', BackupStatus::Completed)
            ->where('is_locked', false)
            ->orderByDesc('created_at');

        if ($config->retention_type === 'count') {
            $toDelete = $query->skip($config->retention_value)->get();
        } else {
            // days
            $cutoff = now()->subDays($config->retention_value);
            $toDelete = Backup::where('site_id', $this->site->id)
                ->where('status', BackupStatus::Completed)
                ->where('is_locked', false)
                ->where('created_at', '<', $cutoff)
                ->get();
        }

        foreach ($toDelete as $oldBackup) {
            try {
                \Illuminate\Support\Facades\DB::transaction(function () use ($oldBackup) {
                    $oldDestination = $oldBackup->storageDestination;

                    // Delete from storage first
                    if ($oldDestination && $oldBackup->file_path) {
                        $driver = StorageFactory::make($oldDestination);
                        $driver->delete($oldBackup->file_path);
                        $oldDestination->decrement('used_bytes', max(0, $oldBackup->file_size ?? 0));
                    }

                    // Then delete DB record
                    $oldBackup->delete();
                });
            } catch (\Exception $e) {
                Log::warning("Failed to delete old backup {$oldBackup->id}", [
                    'exception' => get_class($e),
                    'code' => $e->getCode(),
                ]);
            }
        }
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

    public function failed(?\Throwable $exception): void
    {
        $exceptionClass = $exception ? get_class($exception) : 'Unknown';
        CircuitBreakerService::recordFailure($this->site, "Backup failed: {$exceptionClass}");
        JobTracker::fail($this->uniqueId(), "Backup failed: {$exceptionClass}");
    }
}
