<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\ActivityLogger;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use App\Services\MaintenanceService;
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
    public int $tries = 1;

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

        if (MaintenanceService::isSiteInMaintenance($this->site, 'backups')) {
            JobTracker::complete($this->uniqueId(), 'Backup skipped (maintenance mode)');
            return;
        }

        $this->tempDir = storage_path('app/temp/backup-' . uniqid());
        mkdir($this->tempDir, 0755, true);

        try {
            $destination = $this->resolveStorageDestination();
            if (!$destination) {
                throw new \RuntimeException('No storage destination available. Configure a storage destination first.');
            }

            // Use existing backup record or create a new one
            if ($this->backupId) {
                $this->backup = Backup::findOrFail($this->backupId);
                $this->backup->update([
                    'status' => 'in_progress',
                    'stage' => 'initializing',
                    'started_at' => $this->backup->started_at ?? now(),
                ]);
            } else {
                $this->backup = Backup::create([
                    'site_id' => $this->site->id,
                    'storage_destination_id' => $destination->id,
                    'type' => $this->type,
                    'trigger' => $this->trigger,
                    'status' => 'in_progress',
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

            $api = new WordPressApiService($this->site);

            // Download database dump
            $this->reportProgress('downloading_database', 10, 'Downloading database...');
            $dbPath = $this->tempDir . '/database.sql.gz';
            $api->streamDownload('backup/db', $dbPath);
            $this->reportProgress('downloading_database', $this->type === 'full' ? 25 : 40, 'Database downloaded');

            // Download files if full backup
            $filesPath = null;
            if ($this->type === 'full') {
                $this->reportProgress('downloading_files', 30, 'Downloading files...');
                $filesPath = $this->tempDir . '/files.zip';
                $api->streamDownload('backup/files', $filesPath);
                $this->reportProgress('downloading_files', 55, 'Files downloaded');
            }

            // Create combined zip
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

            // Add metadata
            $meta = json_encode([
                'site_name' => $this->site->name,
                'site_url' => $this->site->url,
                'type' => $this->type,
                'wp_version' => $this->site->wp_version,
                'php_version' => $this->site->php_version,
                'created_at' => now()->toIso8601String(),
                'trigger' => $this->trigger,
            ], JSON_PRETTY_PRINT);
            $zip->addFromString('backup-meta.json', $meta);
            $zip->close();
            $this->reportProgress('creating_archive', 70, 'Archive created');

            $fileSize = filesize($combinedPath);
            $checksum = hash_file('sha256', $combinedPath);

            // Upload to storage
            $this->reportProgress('uploading', 75, 'Uploading to storage...');
            $remotePath = $this->site->domain . '/' . $fileName;
            $driver = StorageFactory::make($destination);
            $driver->upload($combinedPath, $remotePath);
            $this->reportProgress('finalizing', 95, 'Finalizing...');

            // Update backup record
            $this->backup->update([
                'status' => 'completed',
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

            // Update site
            $this->site->update([
                'backup_ok' => true,
                'last_backup_at' => now(),
            ]);

            // Update backup config
            $config = $this->site->backupConfig;
            if ($config) {
                $config->update([
                    'last_backup_at' => now(),
                    'last_backup_status' => 'completed',
                ]);
            }

            // Update storage usage
            $destination->increment('used_bytes', $fileSize);

            // Apply retention policy
            $this->applyRetention($destination);

            CircuitBreakerService::recordSuccess($this->site);
            JobTracker::complete($this->uniqueId(), 'Backup complete');

        } catch (\Exception $e) {
            Log::error("Backup failed for site {$this->site->id}: {$e->getMessage()}");

            if ($this->backup) {
                $this->backup->update([
                    'status' => 'failed',
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

            throw $e;
        } finally {
            $this->cleanup();
        }
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
            ->where('status', 'completed')
            ->where('is_locked', false)
            ->orderByDesc('created_at');

        if ($config->retention_type === 'count') {
            $toDelete = $query->skip($config->retention_value)->get();
        } else {
            // days
            $cutoff = now()->subDays($config->retention_value);
            $toDelete = Backup::where('site_id', $this->site->id)
                ->where('status', 'completed')
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
                Log::warning("Failed to delete old backup {$oldBackup->id}: {$e->getMessage()}");
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
        CircuitBreakerService::recordFailure($this->site, $exception?->getMessage() ?? 'Backup failed');
        JobTracker::fail($this->uniqueId(), 'Backup failed: ' . ($exception?->getMessage() ?? 'Unknown error'));
    }
}
