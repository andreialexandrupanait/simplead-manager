<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Jobs\Concerns\BackupJobTrait;
use App\Services\Backup\RetentionService;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\ActivityLogger;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use App\Services\WordPressApiService;
use App\Jobs\PrecacheBackupFileList;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class CreateBackup implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, BackupJobTrait;

    public int $timeout = 1800;
    public int $tries = 2;
    public array $backoff = [120];
    public int $uniqueFor = 1800;

    protected ?Backup $backup = null;
    protected ?string $tempDir = null;
    protected ?string $filesSessionToken = null;

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

    protected function backupTypeLabel(): string
    {
        return 'Backup';
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Creating backup...');

        $this->tempDir = storage_path('app/temp/backup-' . uniqid());
        mkdir($this->tempDir, 0700, true);

        try {
            $destination = $this->resolveStorageDestination();
            if (!$destination) {
                throw new \RuntimeException('No storage destination available. Configure a storage destination first.');
            }

            $this->prepare($destination);

            $api = new WordPressApiService($this->site);
            $this->refreshCapabilities($api);

            Log::info("Backup {$this->backupId}: starting pull flow", [
                'site' => $this->site->domain,
                'type' => $this->type,
                'trigger' => $this->trigger,
                'destinationType' => $destination->type,
            ]);

            [$dbPath, $filesChunkPaths] = $this->downloadData();
            [$combinedPath, $fileName, $fileSize, $checksum] = $this->createArchive($dbPath, $filesChunkPaths);
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
            if ($this->backup->status === BackupStatus::Cancelled) {
                throw new \RuntimeException('Backup cancelled by user');
            }
            $this->backup->update([
                'status' => BackupStatus::InProgress,
                'stage' => 'initializing',
                'started_at' => $this->backup->started_at ?? now(),
            ]);
        } else {
            // Clean up any orphaned backup from a previous failed attempt for this site/type/trigger
            Backup::where('site_id', $this->site->id)
                ->where('type', $this->type)
                ->where('trigger', $this->trigger)
                ->whereIn('status', [BackupStatus::Pending, BackupStatus::InProgress])
                ->update([
                    'status' => BackupStatus::Failed,
                    'stage' => 'failed',
                    'error_message' => 'Superseded by a new backup attempt',
                    'completed_at' => now(),
                ]);

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
            $this->backupId = $this->backup->id;
        }

        $this->reportProgress('initializing', 5, 'Initializing backup...');
    }

    protected function downloadData(): array
    {
        $this->checkCancelled();

        $api = new WordPressApiService($this->site);
        $supportsChunked = $this->supportsChunkedDownload($api);

        $this->reportProgress('downloading_database', 10, 'Downloading database...');
        $dbPath = $this->tempDir . '/database.sql.gz';

        if ($supportsChunked) {
            $api->chunkedDownload('db', $dbPath, function (int $downloaded, int $total) {
                $pct = $this->type === 'full'
                    ? 10 + (int) (($downloaded / max($total, 1)) * 15)   // 10-25%
                    : 10 + (int) (($downloaded / max($total, 1)) * 30);  // 10-40%
                $mb = round($downloaded / 1048576, 1);
                $this->reportProgress('downloading_database', $pct, "Downloading database... {$mb} MB");
            });
        } else {
            $api->streamDownload('backup/db', $dbPath);
        }

        $this->reportProgress('downloading_database', $this->type === 'full' ? 25 : 40, 'Database downloaded');

        $filesChunkPaths = [];
        $this->filesSessionToken = null;
        if ($this->type === 'full') {
            $this->checkCancelled();
            $this->reportProgress('downloading_files', 30, 'Downloading files...');

            if ($supportsChunked) {
                // Download files as individual chunk zips (no merge step)
                $filesDownloadStart = microtime(true);
                [$filesChunkPaths, $this->filesSessionToken] = $api->chunkedDownloadFilesAsChunks(
                    $this->tempDir . '/files.zip',
                    function (int $downloaded, int $total) use ($filesDownloadStart) {
                        $pct = 30 + (int) (($downloaded / max($total, 1)) * 25); // 30-55%
                        $elapsed = microtime(true) - $filesDownloadStart;
                        $eta = '';
                        if ($downloaded > 0 && $downloaded < $total) {
                            $avgPerChunk = $elapsed / $downloaded;
                            $remaining = ($total - $downloaded) * $avgPerChunk;
                            $min = (int) ($remaining / 60);
                            $sec = (int) ($remaining % 60);
                            $eta = $min > 0 ? " (~{$min}m {$sec}s remaining)" : " (~{$sec}s remaining)";
                        }
                        $this->reportProgress('downloading_files', $pct, "Downloading files... chunk {$downloaded}/{$total}{$eta}");
                    }
                );
            } else {
                // Legacy: single files.zip download
                $filesPath = $this->tempDir . '/files.zip';
                $api->streamDownload('backup/files', $filesPath);
                $filesChunkPaths = [$filesPath]; // Treat as single chunk
            }

            $this->reportProgress('downloading_files', 55, 'Files downloaded');
        }

        return [$dbPath, $filesChunkPaths];
    }

    /**
     * Refresh backup capabilities from the WP plugin if stale (>24h).
     */
    protected function refreshCapabilities(WordPressApiService $api): void
    {
        $checkedAt = $this->site->backup_capabilities_checked_at;
        if ($checkedAt && $checkedAt->diffInHours(now()) < 24) {
            return;
        }

        $capabilities = $api->getBackupCapabilities();
        if ($capabilities) {
            $this->site->update([
                'backup_capabilities' => $capabilities,
                'backup_capabilities_checked_at' => now(),
            ]);
        }
    }

    /**
     * Determine the preferred async method based on cached capabilities.
     */
    protected function getPreferredMethod(): ?string
    {
        $caps = $this->site->backup_capabilities;
        if (!$caps || empty($caps['async_methods'])) {
            return null;
        }

        $methods = $caps['async_methods'];

        // Prefer in order: CLI (fastest, no timeout), loopback, cron
        if (!empty($methods['cli'])) {
            return 'cli';
        }
        if (!empty($methods['loopback'])) {
            return 'loopback';
        }
        if (!empty($methods['cron'])) {
            return 'cron';
        }

        return null;
    }

    /**
     * Check if the WP plugin supports the chunked download endpoints.
     */
    protected function supportsChunkedDownload(WordPressApiService $api): bool
    {
        // Use cached capabilities to avoid wasting a request on the rate limit
        $caps = $this->site->backup_capabilities;
        if ($caps) {
            $supported = !empty($caps['chunked_download']) || !empty($caps['success']);
            Log::info("Backup {$this->backupId}: chunked download supported (cached) = " . ($supported ? 'yes' : 'no'));
            return $supported;
        }

        // No cached capabilities — probe the endpoint
        try {
            $response = $api->request('POST', '/backup/prepare', ['type' => 'invalid'], [], 10);
            // A 400 means the endpoint exists (it rejected invalid type)
            // A 404 means the endpoint doesn't exist (old plugin)
            $supported = $response->status() !== 404;
            if (!$supported) {
                Log::info("Backup {$this->backupId}: chunked download not supported (HTTP 404 on /backup/prepare)");
            }
            return $supported;
        } catch (\Throwable $e) {
            Log::info("Backup {$this->backupId}: chunked download check failed: {$e->getMessage()}");
            return false;
        }
    }





    protected function createArchive(string $dbPath, array $filesChunkPaths): array
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
        $zip->setCompressionName('database.sql.gz', ZipArchive::CM_STORE);

        $chunkFileNames = [];
        if (!empty($filesChunkPaths)) {
            if (count($filesChunkPaths) === 1 && basename($filesChunkPaths[0]) === 'files.zip') {
                // Legacy single files.zip (from non-chunked download)
                $zip->addFile($filesChunkPaths[0], 'files.zip');
                $zip->setCompressionName('files.zip', ZipArchive::CM_STORE);
            } else {
                // v2 format: store each chunk zip directly with CM_STORE
                foreach ($filesChunkPaths as $idx => $chunkPath) {
                    $entryName = "files_chunk_{$idx}.zip";
                    $zip->addFile($chunkPath, $entryName);
                    $zip->setCompressionName($entryName, ZipArchive::CM_STORE);
                    $chunkFileNames[] = $entryName;
                }
            }
        }

        $metaData = [
            'site_name' => $this->site->name,
            'site_url' => $this->site->url,
            'type' => $this->type,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'created_at' => now()->toIso8601String(),
            'trigger' => $this->trigger,
        ];

        if (!empty($chunkFileNames)) {
            $metaData['format_version'] = 2;
            $metaData['chunk_files'] = $chunkFileNames;
        }

        $zip->addFromString('backup-meta.json', json_encode($metaData, JSON_PRETTY_PRINT));

        if (!$zip->close()) {
            throw new \RuntimeException('Failed to finalize backup archive (ZipArchive::close failed).');
        }

        $this->reportProgress('creating_archive', 70, 'Archive created');

        $fileSize = filesize($combinedPath);
        $checksum = hash_file('sha256', $combinedPath);

        // For file list precaching, pass the first chunk path (or null if DB-only)
        $firstFilesPath = !empty($filesChunkPaths) ? $filesChunkPaths[0] : null;
        PrecacheBackupFileList::dispatch($this->backup->id, $firstFilesPath, true);

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

        // Generate manifest for incremental backup support (non-fatal)
        // Uses pre-collected manifest from the backup session if available (avoids re-scanning)
        if ($this->type === 'full') {
            try {
                $api = new WordPressApiService($this->site);
                $manifestService = new \App\Services\Backup\ManifestService();
                $manifestService->generateAndStore($api, $this->backup, $destination, $this->filesSessionToken);
            } catch (\Throwable $e) {
                Log::warning("Manifest generation failed for backup {$this->backupId} (non-fatal): {$e->getMessage()}");
            }
        }

        $this->site->update([
            'backup_ok' => true,
            'last_backup_at' => now(),
        ]);

        $config = $this->site->backupConfig;
        if ($config) {
            $config->update([
                'last_backup_at' => now(),
                'last_backup_status' => 'completed',
                'last_full_backup_at' => $this->type === 'full' ? now() : $config->last_full_backup_at,
            ]);
        }

        $destination->increment('used_bytes', $fileSize);
        (new RetentionService())->apply($this->site, $destination);

        CircuitBreakerService::recordSuccess($this->site);
        JobTracker::complete($this->uniqueId(), 'Backup complete');

        // Release unique lock immediately so new backups can start
        static::releaseUniqueLock($this->site->id);
    }

}
