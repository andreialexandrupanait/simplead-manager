<?php

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Http\Controllers\Api\BackupCallbackController;
use App\Services\Backup\Storage\DropboxDriver;
use App\Services\Backup\Storage\S3Driver;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\ActivityLogger;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use App\Services\WordPressApiService;
use Illuminate\Support\Facades\Cache;
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
    public int $uniqueFor = 1800;

    protected ?Backup $backup = null;
    protected ?string $tempDir = null;
    protected ?string $s3UploadId = null;
    protected ?string $s3RemotePath = null;

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

            $api = new WordPressApiService($this->site);
            $this->refreshCapabilities($api);

            // Direct upload disabled: combined ZIP fails for large sites (>1GB files.zip)
            // because ZipArchive::close() fails when adding large files to combined archive.
            // Pull-based flow downloads db and files separately, avoiding this issue.
            $useDirectUpload = false;

            Log::info("Backup {$this->backupId}: flow decision", [
                'site' => $this->site->domain,
                'type' => $this->type,
                'trigger' => $this->trigger,
                'useDirectUpload' => $useDirectUpload,
                'destinationType' => $destination->type,
                'flow' => $useDirectUpload
                    ? ($destination->type === 's3' ? 'direct_s3' : 'relay_' . $destination->type)
                    : 'pull',
            ]);

            if ($useDirectUpload && $destination->type === 's3') {
                $this->handleDirectS3Upload($api, $destination);
            } elseif ($useDirectUpload && in_array($destination->type, ['dropbox', 'local'])) {
                $this->handleRelayUpload($api, $destination);
            } else {
                // Existing pull-based flow
                [$dbPath, $filesPath] = $this->downloadData();
                [$combinedPath, $fileName, $fileSize, $checksum] = $this->createArchive($dbPath, $filesPath);
                $remotePath = $this->upload($destination, $combinedPath, $fileName);
                $this->finalize($destination, $remotePath, $fileName, $fileSize, $checksum);
            }

        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    protected function checkCancelled(): void
    {
        if (!$this->backupId) {
            return;
        }
        $status = Backup::where('id', $this->backupId)->value('status');
        if ($status === BackupStatus::Cancelled || $status === 'cancelled') {
            Log::info("Backup {$this->backupId}: cancelled by user, aborting job");
            throw new \RuntimeException('Backup cancelled by user');
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

        $filesPath = null;
        if ($this->type === 'full') {
            $this->checkCancelled();
            $this->reportProgress('downloading_files', 30, 'Downloading files...');
            $filesPath = $this->tempDir . '/files.zip';

            if ($supportsChunked) {
                $api->chunkedDownload('files', $filesPath, function (int $downloaded, int $total) {
                    $pct = 30 + (int) (($downloaded / max($total, 1)) * 25); // 30-55%
                    $mb = round($downloaded / 1048576, 1);
                    $totalMb = round($total / 1048576, 1);
                    $this->reportProgress('downloading_files', $pct, "Downloading files... {$mb}/{$totalMb} MB");
                });
            } else {
                $api->streamDownload('backup/files', $filesPath);
            }

            $this->reportProgress('downloading_files', 55, 'Files downloaded');
        }

        return [$dbPath, $filesPath];
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

    /**
     * Check if the WP plugin supports direct upload (WP → storage).
     */
    protected function supportsDirectUpload(WordPressApiService $api): bool
    {
        try {
            $response = $api->request('POST', '/backup/capabilities', [], [], 10);
            if (!$response->successful()) {
                Log::info("Backup {$this->backupId}: capabilities check returned HTTP {$response->status()}");
                return false;
            }
            $data = $response->json();
            $supported = !empty($data['direct_upload']);
            Log::info("Backup {$this->backupId}: direct_upload supported = " . ($supported ? 'yes' : 'no'));
            return $supported;
        } catch (\Throwable $e) {
            Log::info("Backup {$this->backupId}: capabilities check failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Prepare the backup archive on WP, trying async first then falling back to sync.
     * Returns ['token' => string, 'size' => int, 'checksum' => string].
     */
    protected function prepareArchiveOnWp(WordPressApiService $api): array
    {
        $this->reportProgress('preparing', 10, 'Preparing backup archive on WordPress...');

        // Try async preparation first
        try {
            $requestData = ['type' => $this->type];
            $preferredMethod = $this->getPreferredMethod();
            if ($preferredMethod) {
                $requestData['preferred_method'] = $preferredMethod;
            }

            $asyncResponse = $api->request('POST', '/backup/prepare-async', $requestData, [], 30);

            if ($asyncResponse->successful()) {
                $asyncData = $asyncResponse->json();

                if (!empty($asyncData['success']) && !empty($asyncData['async']) && !empty($asyncData['token'])) {
                    $token = $asyncData['token'];
                    $method = $asyncData['method'] ?? 'unknown';
                    Log::info("Backup {$this->backupId}: async preparation started via {$method}, token: {$token}");

                    if ($this->backup) {
                        $this->backup->update(['preparation_method' => $method]);
                    }

                    $result = $this->pollAsyncPreparation($api, $token);
                    if ($result) {
                        return $result;
                    }
                    // If polling failed, fall through to sync
                    Log::warning("Backup {$this->backupId}: async polling failed, falling back to sync");
                }
                // async: false means all async methods failed — fall through to sync
            }
        } catch (\Throwable $e) {
            // 404 = old plugin without async endpoint, or other error — fall back to sync
            Log::info("Backup {$this->backupId}: async not available ({$e->getMessage()}), using sync");
        }

        // Sync fallback: call prepare-combined directly (increase timeout to 900s)
        $this->reportProgress('preparing', 10, 'Preparing archive synchronously...');
        if ($this->backup) {
            $this->backup->update(['preparation_method' => 'sync']);
        }
        $prepareResponse = $api->request('POST', '/backup/prepare-combined', [
            'type' => $this->type,
        ], [], 900);
        $prepareResponse->throw();
        $prepare = $prepareResponse->json();

        if (empty($prepare['success']) || empty($prepare['token'])) {
            throw new \RuntimeException('Prepare-combined failed: ' . ($prepare['error']['message'] ?? 'Unknown error'));
        }

        $this->reportProgress('preparing', 25, 'Archive prepared (' . round((int) $prepare['size'] / 1048576, 1) . ' MB)');

        return [
            'token' => $prepare['token'],
            'size' => (int) $prepare['size'],
            'checksum' => $prepare['checksum'],
        ];
    }

    /**
     * Poll the async preparation status until done or failed.
     * Returns ['token' => string, 'size' => int, 'checksum' => string] on success, null on failure.
     */
    protected function pollAsyncPreparation(WordPressApiService $api, string $token): ?array
    {
        $pollInterval = 5;
        // Dynamic max polls: leave 300s (5 min) for upload after polling
        $maxPollSeconds = max(300, $this->timeout - 300);
        $maxPolls = (int) floor($maxPollSeconds / $pollInterval);
        $lastProgress = 0;
        $lastProgressAt = time();
        $stallTimeout = 300; // 5 minutes without progress change
        $initialStallTimeout = 60; // 60 seconds at progress=0 means the async method likely failed silently

        for ($i = 0; $i < $maxPolls; $i++) {
            sleep($pollInterval);

            try {
                $statusResponse = $api->request('POST', '/backup/prepare-status', [
                    'token' => $token,
                ], [], 30);

                if (!$statusResponse->successful()) {
                    Log::warning("Backup {$this->backupId}: status poll returned HTTP {$statusResponse->status()}");
                    continue; // Transient network error — keep polling
                }

                $status = $statusResponse->json();

                if (!isset($status['status'])) {
                    continue;
                }

                if ($status['status'] === 'done') {
                    $this->reportProgress('preparing', 25, 'Archive prepared (' . round((int) $status['size'] / 1048576, 1) . ' MB)');

                    // Release the lock on WP side
                    try {
                        $api->request('POST', '/backup/prepare-async', ['type' => 'db'], [], 5);
                    } catch (\Throwable) {}

                    return [
                        'token' => $token,
                        'size' => (int) $status['size'],
                        'checksum' => $status['checksum'],
                    ];
                }

                if ($status['status'] === 'failed') {
                    Log::error("Backup {$this->backupId}: async preparation failed: " . ($status['error'] ?? 'unknown'));
                    return null;
                }

                // Still working — update progress
                $progress = (int) ($status['progress'] ?? 0);
                $message = $status['message'] ?? 'Preparing...';

                // Map WP prep progress (0-100%) to backup progress (10-25%)
                $mappedProgress = 10 + (int) ($progress * 0.15);
                $this->reportProgress('preparing', $mappedProgress, $message);

                // Stall detection — use shorter timeout when progress is still 0
                // (indicates the async method likely failed silently, e.g. broken loopback auth)
                if ($progress > $lastProgress) {
                    $lastProgress = $progress;
                    $lastProgressAt = time();
                } else {
                    $currentStallTimeout = ($lastProgress === 0) ? $initialStallTimeout : $stallTimeout;
                    $stalledFor = time() - $lastProgressAt;
                    if ($stalledFor > $currentStallTimeout) {
                        Log::warning("Backup {$this->backupId}: async preparation stalled for {$stalledFor}s at {$progress}%");
                        return null; // Signal caller to fall back to sync
                    }
                }
            } catch (\Throwable $e) {
                // Network hiccup — continue polling
                Log::warning("Backup {$this->backupId}: poll error: {$e->getMessage()}");
                continue;
            }
        }

        Log::error("Backup {$this->backupId}: async preparation timed out after " . ($maxPolls * $pollInterval) . "s");
        return null;
    }

    /**
     * Direct S3 upload: WP creates combined archive, uploads parts directly to S3 via presigned URLs.
     */
    protected function handleDirectS3Upload(WordPressApiService $api, StorageDestination $destination): void
    {
        // Step 1: Prepare archive on WP (async or sync)
        $prepared = $this->prepareArchiveOnWp($api);
        $wpToken = $prepared['token'];
        $fileSize = $prepared['size'];
        $checksum = $prepared['checksum'];

        // Step 2: Initiate S3 multipart upload
        $timestamp = now()->format('Y-m-d-His');
        $fileName = "{$this->site->domain}-{$this->type}-{$timestamp}.zip";
        $remotePath = $this->site->domain . '/' . $fileName;

        $driver = StorageFactory::make($destination);
        if (!$driver instanceof S3Driver) {
            throw new \RuntimeException('Expected S3Driver for direct S3 upload');
        }

        $this->s3RemotePath = $remotePath;
        $uploadId = $driver->initiateMultipartUpload($remotePath);
        $this->s3UploadId = $uploadId;

        // Step 3: Generate presigned URLs
        $parts = $driver->generatePresignedPartUrls($remotePath, $uploadId, $fileSize);

        $this->reportProgress('uploading', 30, 'Uploading directly from WordPress to S3...');

        // Step 4: Tell WP to upload each part directly to S3
        $callbackToken = BackupCallbackController::generateToken($this->backup);
        $callbackUrl = rtrim(config('app.url'), '/') . '/api/backup-callback';

        $uploadResponse = $api->request('POST', '/backup/direct-upload', [
            'strategy' => 's3_multipart',
            'token' => $wpToken,
            'parts' => $parts,
            'callback_url' => $callbackUrl,
            'callback_token' => $callbackToken,
            'backup_id' => $this->backup->id,
        ], [], 1800);
        $uploadResponse->throw();
        $uploadResult = $uploadResponse->json();

        if (empty($uploadResult['success']) || empty($uploadResult['etags'])) {
            throw new \RuntimeException('Direct upload failed: ' . ($uploadResult['error']['message'] ?? 'Unknown error'));
        }

        // Step 5: Complete the multipart upload
        $this->reportProgress('finalizing', 90, 'Completing S3 multipart upload...');
        $driver->completeMultipartUpload($remotePath, $uploadId, $uploadResult['etags']);
        $this->s3UploadId = null; // Upload completed, no need to abort on failure

        // Step 6: Verify file on S3
        $s3Size = $driver->size($remotePath);
        if ($s3Size < $fileSize * 0.95) { // Allow small variance from multipart overhead
            throw new \RuntimeException("S3 file size mismatch: expected ~{$fileSize}, got {$s3Size}");
        }

        // Step 7: Cleanup prepared archive on WP
        try {
            $api->request('POST', '/backup/cleanup', ['token' => $wpToken], [], 10);
        } catch (\Throwable) {
            // Best effort
        }

        // Step 8: Finalize
        $this->backup->update(['upload_method' => 'direct_s3']);
        $this->finalize($destination, $remotePath, $fileName, $fileSize, $checksum);
    }

    /**
     * Relay upload: WP creates combined archive, pushes chunks to Manager relay,
     * which streams to Dropbox or appends to local file.
     */
    protected function handleRelayUpload(WordPressApiService $api, StorageDestination $destination): void
    {
        // Step 1: Prepare archive on WP (async or sync)
        $prepared = $this->prepareArchiveOnWp($api);
        $wpToken = $prepared['token'];
        $fileSize = $prepared['size'];
        $checksum = $prepared['checksum'];

        // Step 2: Set up relay context
        $timestamp = now()->format('Y-m-d-His');
        $fileName = "{$this->site->domain}-{$this->type}-{$timestamp}.zip";
        $remotePath = $this->site->domain . '/' . $fileName;
        $callbackToken = BackupCallbackController::generateToken($this->backup);
        $cacheKey = "backup-relay:{$this->backup->id}";

        if ($destination->type === 'dropbox') {
            /** @var DropboxDriver $driver */
            $driver = StorageFactory::make($destination);
            Cache::put($cacheKey, [
                'strategy' => 'dropbox',
                'remote_path' => $driver->fullPathPublic($remotePath),
                'offset' => 0,
            ], 14400);
        } else {
            // Local storage
            $driver = StorageFactory::make($destination);
            $localFullPath = $this->getLocalFullPath($destination, $remotePath);
            $dir = dirname($localFullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            Cache::put($cacheKey, [
                'strategy' => 'local',
                'file_path' => $localFullPath,
            ], 14400);
        }

        $this->reportProgress('uploading', 30, 'Uploading via relay...');

        // Step 3: Tell WP to push chunks to relay
        $relayUrl = rtrim(config('app.url'), '/') . '/api/backup-relay/' . $this->backup->id;
        $callbackUrl = rtrim(config('app.url'), '/') . '/api/backup-callback';

        $uploadResponse = $api->request('POST', '/backup/direct-upload', [
            'strategy' => 'chunked_push',
            'token' => $wpToken,
            'upload_url' => $relayUrl,
            'upload_token' => $callbackToken,
            'chunk_size' => 8 * 1024 * 1024, // 8MB chunks
            'callback_url' => $callbackUrl,
            'callback_token' => $callbackToken,
            'backup_id' => $this->backup->id,
        ], [], 1800);
        $uploadResponse->throw();
        $uploadResult = $uploadResponse->json();

        if (empty($uploadResult['success'])) {
            throw new \RuntimeException('Relay upload failed: ' . ($uploadResult['error']['message'] ?? 'Unknown error'));
        }

        // Step 4: Verify
        $this->reportProgress('finalizing', 90, 'Verifying upload...');

        if ($destination->type === 'local') {
            $localFullPath = $this->getLocalFullPath($destination, $remotePath);
            if (!file_exists($localFullPath)) {
                throw new \RuntimeException('Local file not found after relay upload');
            }
            $actualChecksum = hash_file('sha256', $localFullPath);
            if ($actualChecksum !== $checksum) {
                @unlink($localFullPath);
                throw new \RuntimeException("Checksum mismatch: expected {$checksum}, got {$actualChecksum}");
            }
        }

        // Step 5: Cleanup prepared archive on WP
        try {
            $api->request('POST', '/backup/cleanup', ['token' => $wpToken], [], 10);
        } catch (\Throwable) {
            // Best effort
        }

        // Step 6: Finalize
        $this->backup->update(['upload_method' => 'relay_' . $destination->type]);
        $this->finalize($destination, $remotePath, $fileName, $fileSize, $checksum);
    }

    protected function getLocalFullPath(StorageDestination $destination, string $remotePath): string
    {
        $basePath = rtrim($destination->config['path'] ?? storage_path('backups'), '/');
        return $basePath . '/' . ltrim($remotePath, '/');
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
            // Store without compression — files.zip is already compressed
            $zip->addFile($filesPath, 'files.zip');
            $zip->setCompressionName('files.zip', ZipArchive::CM_STORE);
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

        if (!$zip->close()) {
            throw new \RuntimeException('Failed to finalize backup archive (ZipArchive::close failed).');
        }

        $this->reportProgress('creating_archive', 70, 'Archive created');

        $fileSize = filesize($combinedPath);
        $checksum = hash_file('sha256', $combinedPath);

        PrecacheBackupFileList::dispatch($this->backup->id, $filesPath, true);

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
        Log::error("Backup failed for site {$this->site->id} ({$this->site->domain})", [
            'backup_id' => $this->backupId,
            'type' => $this->type,
            'trigger' => $this->trigger,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);

        if ($this->backup) {
            $this->backup->refresh();
            if ($this->backup->status === BackupStatus::Cancelled) {
                return; // Don't overwrite cancelled status
            }
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
        Log::error("Backup job permanently failed for site {$this->site->id} ({$this->site->domain})", [
            'backup_id' => $this->backupId,
            'exception' => $exception ? get_class($exception) : 'Unknown',
            'message' => $exception?->getMessage(),
        ]);

        $exceptionClass = $exception ? get_class($exception) : 'Unknown';

        // Abort any in-progress S3 multipart upload
        if ($this->s3UploadId && $this->s3RemotePath && $this->storageDestinationId) {
            try {
                $destination = StorageDestination::find($this->storageDestinationId);
                if ($destination && $destination->type === 's3') {
                    $driver = StorageFactory::make($destination);
                    if ($driver instanceof S3Driver) {
                        $driver->abortMultipartUpload($this->s3RemotePath, $this->s3UploadId);
                    }
                }
            } catch (\Throwable) {
                // Best effort cleanup
            }
        }

        // Clean up relay cache if present
        if ($this->backupId) {
            Cache::forget("backup-relay:{$this->backupId}");
        }

        // Mark the backup record as failed so it doesn't stay stuck in "in_progress"
        $backup = $this->backupId ? Backup::find($this->backupId) : null;
        if ($backup && !in_array($backup->status, [BackupStatus::Completed, BackupStatus::Failed, BackupStatus::Cancelled])) {
            $backup->update([
                'status' => BackupStatus::Failed,
                'stage' => 'failed',
                'progress_message' => 'Backup failed: ' . Str::limit($exception?->getMessage() ?? 'Unknown error', 200),
                'error_message' => $exception?->getMessage() ?? 'Job exceeded maximum attempts or timed out',
                'completed_at' => now(),
                'duration_seconds' => $backup->started_at ? (int) $backup->started_at->diffInSeconds(now()) : null,
            ]);
        }

        CircuitBreakerService::recordFailure($this->site, "Backup failed: {$exceptionClass}");
        JobTracker::fail($this->uniqueId(), "Backup failed: {$exceptionClass}");
    }
}
