<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Enums\BackupStatus;
use App\Exceptions\BackupException;
use App\Helpers\FormatHelper;
use App\Http\Controllers\Api\BackupCallbackController;
use App\Jobs\Concerns\BackupJobTrait;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Services\Backup\RetentionService;
use App\Services\Backup\Storage\S3Driver;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class CreateBackup implements ShouldBeUnique, ShouldQueue
{
    use BackupJobTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 2700;

    public int $tries = 2;

    public array $backoff = [120];

    public int $uniqueFor = 2700;

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
        return 'backup-'.$this->site->id;
    }

    protected function backupTypeLabel(): string
    {
        return 'Backup';
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Creating backup...');

        $this->tempDir = storage_path('app/temp/backup-'.uniqid());
        mkdir($this->tempDir, 0700, true);

        try {
            $destination = $this->resolveStorageDestination();
            if (! $destination) {
                throw new BackupException('No storage destination available. Configure a storage destination first.', site: $this->site);
            }

            $this->prepare($destination);

            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $this->refreshCapabilities($api);

            if ($this->shouldUseDirectUpload($destination)) {
                Log::info("Backup {$this->backupId}: starting direct-upload (push) flow", [
                    'site' => $this->site->domain,
                    'type' => $this->type,
                    'trigger' => $this->trigger,
                    'destinationType' => $destination->type,
                ]);
                $this->runDirectUploadPipeline($destination, $api);
            } else {
                Log::info("Backup {$this->backupId}: starting pull flow", [
                    'site' => $this->site->domain,
                    'type' => $this->type,
                    'trigger' => $this->trigger,
                    'destinationType' => $destination->type,
                ]);

                [$dbPath, $filesChunkPaths] = $this->downloadData();

                // v3-zip is now the single write path for new backups. Old formats
                // (v2-zip and multipart-v3) are read-only — restore still handles them.
                $this->runV3ZipPipeline($destination, $dbPath, $filesChunkPaths);
            }

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
        $this->logStep("Initializing {$this->type} backup for {$this->site->domain}");
    }

    protected function downloadData(): array
    {
        $this->checkCancelled();

        $api = app(WordPressApiServiceFactory::class)->make($this->site);
        $supportsChunked = $this->supportsChunkedDownload($api);

        $this->reportProgress('downloading_database', 10, 'Downloading database...');
        $this->logStep("Downloading database from {$this->site->domain}...");
        $dbPath = $this->tempDir.'/database.sql.gz';
        $dbStart = microtime(true);
        $dbChunkCounter = 0;

        if ($supportsChunked) {
            $api->chunkedDownload('db', $dbPath, function (int $downloaded, int $total) use (&$dbChunkCounter, $dbStart) {
                $dbChunkCounter++;
                $pct = $this->type === 'full'
                    ? 10 + (int) (($downloaded / max($total, 1)) * 15)   // 10-25%
                    : 10 + (int) (($downloaded / max($total, 1)) * 30);  // 10-40%
                $this->reportProgress('downloading_database', $pct, "Downloading database... chunk {$downloaded}/{$total}");
                if ($dbChunkCounter % 5 === 0 || $downloaded === $total) {
                    $elapsed = microtime(true) - $dbStart;
                    $speed = $elapsed > 0 ? FormatHelper::bytes((int) (($downloaded * 1024 * 512) / $elapsed)) : '0 B';
                    $this->logStep("Database chunk {$downloaded}/{$total} ({$speed}/s)");
                }
            }, fn () => $this->checkCancelled());
        } else {
            $api->streamDownload('backup/db', $dbPath);
        }

        $dbDuration = round(microtime(true) - $dbStart, 1);
        $dbSize = file_exists($dbPath) ? FormatHelper::bytes((int) filesize($dbPath)) : '0 B';
        $this->logStep("Database downloaded ({$dbSize} in {$dbDuration}s)");
        $this->reportProgress('downloading_database', $this->type === 'full' ? 25 : 40, 'Database downloaded');

        $filesChunkPaths = [];
        $this->filesSessionToken = null;
        if ($this->type === 'full') {
            $this->checkCancelled();
            $this->reportProgress('downloading_files', 30, 'Downloading files...');
            $this->logStep("Downloading files from {$this->site->domain}...");
            $filesChunkCounter = 0;

            if ($supportsChunked) {
                // Download files as individual chunk zips (no merge step)
                $filesDownloadStart = microtime(true);
                [$filesChunkPaths, $this->filesSessionToken] = $api->chunkedDownloadFilesAsChunks(
                    $this->tempDir.'/files.zip',
                    function (int $downloaded, int $total) use ($filesDownloadStart, &$filesChunkCounter) {
                        $filesChunkCounter++;
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
                        if ($filesChunkCounter % 3 === 0 || $downloaded === $total) {
                            $this->logStep("Files chunk {$downloaded}/{$total}{$eta}");
                        }
                    }
                );
                $filesDuration = round(microtime(true) - $filesDownloadStart, 1);
                $this->logStep("Files downloaded ({$filesChunkCounter} chunks in {$filesDuration}s)");
            } else {
                // Legacy: single files.zip download
                $filesPath = $this->tempDir.'/files.zip';
                $api->streamDownload('backup/files', $filesPath);
                $filesChunkPaths = [$filesPath]; // Treat as single chunk
                $this->logStep('Files downloaded (legacy single download)');
            }

            $this->reportProgress('downloading_files', 55, 'Files downloaded');
        }

        return [$dbPath, $filesChunkPaths];
    }

    /**
     * Refresh backup capabilities from the WP plugin if stale (>24h).
     */
    protected function refreshCapabilities(WordPressApiServiceInterface $api): void
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
        if (! $caps || empty($caps['async_methods'])) {
            return null;
        }

        $methods = $caps['async_methods'];

        // Prefer in order: CLI (fastest, no timeout), loopback, cron
        if (! empty($methods['cli'])) {
            return 'cli';
        }
        if (! empty($methods['loopback'])) {
            return 'loopback';
        }
        if (! empty($methods['cron'])) {
            return 'cron';
        }

        return null;
    }

    /**
     * Check if the WP plugin supports the chunked download endpoints.
     */
    protected function supportsChunkedDownload(WordPressApiServiceInterface $api): bool
    {
        // Use cached capabilities to avoid wasting a request on the rate limit
        $caps = $this->site->backup_capabilities;
        if ($caps) {
            $supported = ! empty($caps['chunked_download']) || ! empty($caps['success']);
            Log::info("Backup {$this->backupId}: chunked download supported (cached) = ".($supported ? 'yes' : 'no'));

            return $supported;
        }

        // No cached capabilities — probe the endpoint
        try {
            $response = $api->request('POST', '/backup/prepare', ['type' => 'invalid'], [], 10);
            // A 400 means the endpoint exists (it rejected invalid type)
            // A 404 means the endpoint doesn't exist (old plugin)
            $supported = $response->status() !== 404;
            if (! $supported) {
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
        $combinedPath = $this->tempDir.'/'.$fileName;

        $this->reportProgress('creating_archive', $this->type === 'full' ? 60 : 50, 'Creating backup archive...');
        $this->logStep('Creating archive...');

        $zip = new ZipArchive;
        if ($zip->open($combinedPath, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Failed to create backup archive.');
        }

        $zip->addFile($dbPath, 'database.sql.gz');
        $zip->setCompressionName('database.sql.gz', ZipArchive::CM_STORE);

        $chunkFileNames = [];
        if (! empty($filesChunkPaths)) {
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

        if (! empty($chunkFileNames)) {
            $metaData['format_version'] = 2;
            $metaData['chunk_files'] = $chunkFileNames;
        }

        $zip->addFromString('backup-meta.json', json_encode($metaData, JSON_PRETTY_PRINT));

        if (! $zip->close()) {
            throw new \RuntimeException('Failed to finalize backup archive (ZipArchive::close failed).');
        }

        $this->reportProgress('creating_archive', 70, 'Archive created');

        $fileSize = filesize($combinedPath);
        $this->logStep('Archive created ('.FormatHelper::bytes((int) $fileSize).')');
        $checksum = hash_file('sha256', $combinedPath);

        // For file list precaching, pass the first chunk path (or null if DB-only)
        $firstFilesPath = ! empty($filesChunkPaths) ? $filesChunkPaths[0] : null;
        PrecacheBackupFileList::dispatch($this->backup->id, $firstFilesPath, true);

        return [$combinedPath, $fileName, $fileSize, $checksum];
    }

    /**
     * v3-zip pipeline: build a single .zip with proper WP structure on local disk
     * (consolidating chunk zips into `files/` subtree), then upload via S3 multipart.
     * Replaces the old v2-zip and multipart-v3 paths for new backups.
     *
     * @param  list<string>  $filesChunkPaths
     */
    protected function runV3ZipPipeline(\App\Models\StorageDestination $destination, string $dbPath, array $filesChunkPaths): void
    {
        $now = now();
        $fileName = "{$this->site->domain}-{$this->type}-{$now->format('Y-m-d-His')}.zip";
        $outputPath = $this->tempDir.'/'.$fileName;

        $this->reportProgress('creating_archive', 60, 'Building backup archive (v3-zip)...');
        $this->logStep("Building consolidated v3-zip archive: {$fileName}");

        $builder = new \App\Services\Backup\BackupZipBuilder($outputPath);
        try {
            // 1. WP files: stream-copy each chunk's entries into output.zip under files/
            $totalChunks = count($filesChunkPaths);
            foreach ($filesChunkPaths as $i => $chunkPath) {
                $count = $builder->addEntriesFromZip($chunkPath, 'files/');
                @unlink($chunkPath); // free disk immediately as the chunk is consumed
                $pct = 60 + (int) (15 * ($i + 1) / max(1, $totalChunks));
                $this->reportProgress('creating_archive', min(75, $pct),
                    'Consolidated chunk '.($i + 1)."/{$totalChunks} ({$count} entries)");
            }

            // 2. Database dump (root)
            $builder->addFileFromPath($dbPath, 'database.sql.gz');
            @unlink($dbPath);

            // 3. backup-meta.json (root)
            $builder->addString('backup-meta.json', json_encode([
                'site_id' => $this->site->id,
                'site_url' => $this->site->url,
                'site_domain' => $this->site->domain,
                'site_name' => $this->site->name,
                'type' => $this->type,
                'trigger' => $this->trigger,
                'created_at' => $now->toIso8601String(),
                'wp_version' => $this->site->wp_version,
                'php_version' => $this->site->php_version,
                'format' => 'v3-zip',
                'plugins_count' => $this->site->sitePlugins()->count(),
                'themes_count' => $this->site->siteThemes()->count(),
            ], JSON_PRETTY_PRINT));

            $result = $builder->finish();
        } catch (\Throwable $e) {
            $builder->abort();
            throw $e;
        }

        // 4. Verify the freshly-built zip before upload (Faza 1 — Level A)
        $integrity = $this->verifyV3Zip($outputPath, $result['sha256']);

        // 5. Upload single file (S3 driver handles multipart automatically for >100MB)
        $remotePath = $this->upload($destination, $outputPath, $fileName);

        // 6. Finalize + sidecar metadata + replication dispatch
        $this->finalizeV3Zip($destination, $remotePath, $fileName, $result['size'], $result['sha256'], $integrity);
    }

    /**
     * Verify a freshly-built v3-zip archive: outer ZIP CHECKCONS + DB dump structure
     * + presence of expected entries + sha256 match.
     *
     * @return array{ok: bool, message: string, checks: array<string, mixed>}
     */
    protected function verifyV3Zip(string $outputPath, string $expectedSha256): array
    {
        $this->reportProgress('verifying', 76, 'Verifying archive integrity...');
        $this->logStep('Verifying v3-zip archive integrity...');

        $verifier = app(\App\Services\Backup\IntegrityVerifier::class);
        $result = $verifier->verifyV3Zip($outputPath, $expectedSha256);

        if (! $result['ok']) {
            $this->backup->update([
                'verification_status' => 'failed',
                'verification_message' => $result['message'],
            ]);
            throw new \App\Exceptions\BackupException(
                "v3-zip integrity check failed: {$result['message']}",
                site: $this->site
            );
        }

        $this->logStep($result['message']);

        return $result;
    }

    /**
     * Finalize a v3-zip backup: same accounting as v2-zip finalize but stores
     * `format = v3-zip`. Single remote file, sidecar metadata, replication dispatch.
     *
     * @param  array{ok: bool, message: string, checks: array<string, mixed>}  $integrity
     */
    protected function finalizeV3Zip(StorageDestination $destination, string $remotePath, string $fileName, int $fileSize, string $checksum, array $integrity): void
    {
        $primaryReplica = [[
            'destination_id' => $destination->id,
            'remote_path' => $remotePath,
            'uploaded_at' => now()->toIso8601String(),
            'status' => 'completed',
        ]];

        $this->backup->update([
            'status' => BackupStatus::Completed,
            'stage' => 'completed',
            'progress_percent' => 100,
            'progress_message' => 'Backup completed (v3-zip)',
            'error_message' => null,
            'file_path' => $remotePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'checksum' => $checksum,
            'format' => 'v3-zip',
            'replicas' => $primaryReplica,
            'completed_at' => now(),
            'verified_at' => now(),
            'verification_status' => 'passed',
            'verification_message' => $integrity['message'],
            'duration_seconds' => (int) $this->backup->started_at->diffInSeconds(now()),
            'is_locked' => $this->trigger === 'pre_update',
            'lock_reason' => $this->trigger === 'pre_update' ? 'pre-update' : null,
        ]);

        ActivityLogger::backupCompleted($this->site, $fileName, $fileSize);

        // Self-describing sidecar so the backup is reindexable without the Laravel DB
        $this->touchHeartbeat();
        try {
            $sidecar = \App\Services\Backup\BackupSidecarMetadata::buildForV2Zip($this->backup->fresh(), $this->site);
            $sidecar['format'] = 'v3-zip'; // override
            \App\Services\Backup\BackupSidecarMetadata::uploadAlongside(StorageFactory::make($destination), $remotePath, $sidecar);
        } catch (\Throwable $e) {
            Log::warning("Sidecar metadata write failed for backup {$this->backupId}: {$e->getMessage()}");
        }

        // Off-site replication (3-2-1)
        $secondaryDestId = $this->site->backupConfig?->secondary_storage_destination_id;
        if ($secondaryDestId && $secondaryDestId !== $destination->id) {
            ReplicateBackup::dispatch($this->backup->id, $secondaryDestId);
        }

        // Manifest for incremental support (non-fatal)
        if ($this->type === 'full') {
            $this->touchHeartbeat();
            try {
                $api = app(WordPressApiServiceFactory::class)->make($this->site);
                $manifestService = new \App\Services\Backup\ManifestService;
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
        app(RetentionService::class)->apply($this->site, $destination);

        CircuitBreakerService::recordSuccess($this->site);
        JobTracker::complete($this->uniqueId(), 'Backup complete');
        static::releaseUniqueLock($this->site->id);
    }

    /**
     * Decide whether this backup can use the server-side-build + push-to-S3 path
     * instead of the legacy pull-and-reassemble path.
     *
     * Requires:
     *  - Site feature flag `backup_strategy = 'push'`
     *  - WP plugin advertises `direct_upload: true` capability with `s3_multipart` strategy
     *  - Destination is S3-compatible (S3Driver handles s3, b2, hetzner_objectstorage)
     */
    protected function shouldUseDirectUpload(StorageDestination $destination): bool
    {
        if ($this->site->backup_strategy !== 'push') {
            return false;
        }

        $caps = $this->site->backup_capabilities ?? [];
        if (empty($caps['direct_upload'])) {
            return false;
        }
        if (! in_array('s3_multipart', $caps['strategies'] ?? [], true)) {
            return false;
        }

        return in_array($destination->type, ['s3', 'b2', 'hetzner_objectstorage'], true);
    }

    /**
     * Direct-upload pipeline (build on WP + push to S3 multipart). This is what
     * UpdraftPlus, WPMU Snapshot, Duplicator and WP Staging all do — the backup
     * archive never leaves the WP server until it lands at its final S3 home.
     *
     * Flow:
     *  1. Tell WP plugin to build the combined archive asynchronously.
     *  2. Poll prepare-status until task is ready (size + sha256 known).
     *  3. Initiate S3 multipart upload, generate presigned PUT URLs for each part.
     *  4. Tell WP plugin to push the file to those URLs.
     *  5. Complete the S3 multipart upload with returned ETags.
     *  6. Verify remote object size matches what WP reported.
     *  7. Finalize the Backup row.
     */
    protected function runDirectUploadPipeline(StorageDestination $destination, WordPressApiServiceInterface $api): void
    {
        $this->reportProgress('preparing_on_wp', 5, 'Asking WP to prepare archive...');
        $this->logStep('Requesting async prepare on WP...');

        $prepResponse = $api->request('POST', '/backup/prepare-async', [
            'type' => $this->type,
        ], [], 60);

        if (! $prepResponse->successful()) {
            throw new BackupException(
                "prepare-async failed: HTTP {$prepResponse->status()} — {$prepResponse->body()}",
                site: $this->site
            );
        }

        $prepData = $prepResponse->json();
        $token = $prepData['token'] ?? null;
        if (! $token) {
            throw new BackupException('prepare-async returned no token', site: $this->site);
        }

        $this->logStep("Async prepare started, token=".substr($token, 0, 8)."… method={$prepData['method']}");

        // 2. Poll until ready
        $status = $this->pollPrepareStatus($api, $token);
        $size = (int) $status['size'];
        $checksum = (string) $status['checksum'];

        if ($size <= 0 || ! $checksum) {
            throw new BackupException('prepare-status returned ready but missing size/checksum', site: $this->site);
        }
        $this->logStep("WP build complete: ".FormatHelper::bytes($size)." (sha256 ".substr($checksum, 0, 12)."…)");

        // 3. Initiate S3 multipart
        $this->reportProgress('uploading_direct', 30, 'Initiating S3 multipart upload...');
        $now = now();
        $fileName = "{$this->site->domain}-{$this->type}-{$now->format('Y-m-d-His')}.zip";
        $remotePath = $this->site->domain.'/'.$fileName;

        $driver = StorageFactory::make($destination);
        if (! $driver instanceof S3Driver) {
            throw new BackupException('Direct-upload requires S3-compatible storage', site: $this->site);
        }

        $uploadId = $driver->initiateMultipartUpload($remotePath);
        // S3 supports max 10,000 parts. Default 100MB parts; bump to 200MB if >1TB.
        $partSize = $size > 1_000_000_000_000 ? 200 * 1024 * 1024 : 100 * 1024 * 1024;
        $parts = $driver->generatePresignedPartUrls($remotePath, $uploadId, $size, $partSize);

        $this->logStep("S3 multipart initiated: uploadId=".substr($uploadId, 0, 12)."…, ".count($parts)." parts of ".FormatHelper::bytes($partSize));

        // 4. Tell WP to push
        $callbackToken = BackupCallbackController::generateToken($this->backup);
        $callbackUrl = route('api.backup.callback');

        try {
            $uploadResponse = $api->request('POST', '/backup/direct-upload', [
                'strategy' => 's3_multipart',
                'token' => $token,
                'parts' => $parts,
                'callback_url' => $callbackUrl,
                'callback_token' => $callbackToken,
                'backup_id' => $this->backup->id,
            ], [], 3600);
        } catch (\Throwable $e) {
            // WP push failed — abort multipart on our side to release storage
            $this->abortMultipart($driver, $remotePath, $uploadId);
            throw new BackupException("direct-upload request failed: {$e->getMessage()}", site: $this->site);
        }

        if (! $uploadResponse->successful()) {
            $this->abortMultipart($driver, $remotePath, $uploadId);
            throw new BackupException(
                "direct-upload returned HTTP {$uploadResponse->status()}: ".substr($uploadResponse->body(), 0, 500),
                site: $this->site
            );
        }

        $uploadData = $uploadResponse->json();
        $etags = $uploadData['etags'] ?? null;
        if (! is_array($etags) || $etags === []) {
            $this->abortMultipart($driver, $remotePath, $uploadId);
            throw new BackupException('direct-upload returned no etags', site: $this->site);
        }

        // 5. Complete multipart
        $this->reportProgress('uploading_direct', 90, 'Finalizing S3 multipart upload...');
        $this->logStep('Completing S3 multipart upload with '.count($etags).' parts...');
        try {
            $driver->completeMultipartUpload($remotePath, $uploadId, $etags);
        } catch (\Throwable $e) {
            $this->abortMultipart($driver, $remotePath, $uploadId);
            throw new BackupException("S3 completeMultipartUpload failed: {$e->getMessage()}", site: $this->site);
        }

        // 6. Best-effort cleanup of the prepared file on WP
        try {
            $api->request('POST', '/backup/cleanup', ['token' => $token], [], 10);
        } catch (\Throwable) {
            // non-fatal
        }

        // 7. Verify remote object matches what WP told us
        $integrity = $this->verifyRemoteObject($driver, $remotePath, $size, $checksum);

        // 8. Finalize Backup row
        $this->finalizeDirectUpload($destination, $remotePath, $fileName, $size, $checksum, $integrity);
    }

    /**
     * Poll WP plugin /backup/prepare-status with exponential backoff until task
     * is ready, failed, or we hit max wait.
     *
     * @return array{status: string, size: int, checksum: string, progress: int, message: string}
     */
    protected function pollPrepareStatus(WordPressApiServiceInterface $api, string $token): array
    {
        $maxWaitSeconds = 1800; // 30 min — matches prepare_async transient TTL of 7200 with slack
        $deadline = microtime(true) + $maxWaitSeconds;
        $delays = [5, 5, 10, 10, 15, 20, 30]; // backoff schedule
        $delayIndex = 0;

        $lastReportedProgress = -1;

        while (microtime(true) < $deadline) {
            $this->checkCancelled();
            $this->touchHeartbeat();

            $resp = $api->request('GET', '/backup/prepare-status', [], ['token' => $token], 30);

            if (! $resp->successful()) {
                if ($resp->status() === 404) {
                    throw new BackupException('prepare-status task expired or not found on WP', site: $this->site);
                }
                // transient — retry after delay
                $this->logStep("prepare-status HTTP {$resp->status()}, will retry");
            } else {
                $data = $resp->json();
                $status = $data['status'] ?? 'unknown';
                $progress = (int) ($data['progress'] ?? 0);

                if ($progress !== $lastReportedProgress) {
                    // Map WP progress 0–100 → manager 5–28% (we reserve 30% for upload start)
                    $mapped = 5 + (int) ($progress * 0.23);
                    $msg = $data['message'] ?? "WP preparing... {$progress}%";
                    $this->reportProgress('preparing_on_wp', $mapped, $msg);
                    $lastReportedProgress = $progress;
                }

                if ($status === 'ready') {
                    return $data;
                }
                if ($status === 'failed') {
                    $err = $data['error'] ?? 'unknown error on WP';
                    throw new BackupException("WP prepare failed: {$err}", site: $this->site);
                }
                // status === 'working' → keep polling
            }

            $delay = $delays[min($delayIndex, count($delays) - 1)];
            $delayIndex++;
            sleep($delay);
        }

        throw new BackupException('prepare-status polling timed out after '.$maxWaitSeconds.'s', site: $this->site);
    }

    /**
     * Verify the uploaded S3 object matches what WP reported.
     *
     * We trust the WP-computed sha256 because WP computed it on the source
     * file right after build. S3 ETag is MD5-of-parts for multipart, not full
     * sha256 — not directly comparable, so we only check size at this layer.
     * The sha256 is stored on Backup.checksum for later spot-check verification.
     *
     * @return array{ok: bool, message: string, checks: array<string, mixed>}
     */
    protected function verifyRemoteObject(S3Driver $driver, string $remotePath, int $expectedSize, string $expectedSha256): array
    {
        $this->reportProgress('verifying_remote', 95, 'Verifying remote object size...');
        $actualSize = $driver->size($remotePath);
        if ($actualSize !== $expectedSize) {
            throw new BackupException(
                "S3 object size mismatch: expected {$expectedSize} bytes, got {$actualSize}",
                site: $this->site
            );
        }
        $this->logStep("Remote verify ok: {$actualSize} bytes match WP-reported size");

        return [
            'ok' => true,
            'message' => "remote object size matches ({$actualSize} bytes); sha256 from WP source: {$expectedSha256}",
            'checks' => [
                'remote_size' => $actualSize,
                'wp_sha256' => $expectedSha256,
                'strategy' => 'direct_upload_s3_multipart',
            ],
        ];
    }

    /**
     * @param  array{ok: bool, message: string, checks: array<string, mixed>}  $integrity
     */
    protected function finalizeDirectUpload(StorageDestination $destination, string $remotePath, string $fileName, int $fileSize, string $checksum, array $integrity): void
    {
        $primaryReplica = [[
            'destination_id' => $destination->id,
            'remote_path' => $remotePath,
            'uploaded_at' => now()->toIso8601String(),
            'status' => 'completed',
        ]];

        $this->backup->update([
            'status' => BackupStatus::Completed,
            'stage' => 'completed',
            'progress_percent' => 100,
            'progress_message' => 'Backup completed (direct upload)',
            'error_message' => null,
            'file_path' => $remotePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'checksum' => $checksum,
            'format' => 'direct-s3',
            'replicas' => $primaryReplica,
            'completed_at' => now(),
            'verified_at' => now(),
            'verification_status' => 'passed',
            'verification_message' => $integrity['message'],
            'duration_seconds' => (int) $this->backup->started_at->diffInSeconds(now()),
            'is_locked' => $this->trigger === 'pre_update',
            'lock_reason' => $this->trigger === 'pre_update' ? 'pre-update' : null,
        ]);

        ActivityLogger::backupCompleted($this->site, $fileName, $fileSize);

        // Sidecar metadata so the backup is reindexable without the Laravel DB
        $this->touchHeartbeat();
        try {
            $sidecar = \App\Services\Backup\BackupSidecarMetadata::buildForV2Zip($this->backup->fresh(), $this->site);
            $sidecar['format'] = 'direct-s3';
            \App\Services\Backup\BackupSidecarMetadata::uploadAlongside(StorageFactory::make($destination), $remotePath, $sidecar);
        } catch (\Throwable $e) {
            Log::warning("Sidecar metadata write failed for backup {$this->backupId}: {$e->getMessage()}");
        }

        // Off-site replication (3-2-1)
        $secondaryDestId = $this->site->backupConfig?->secondary_storage_destination_id;
        if ($secondaryDestId && $secondaryDestId !== $destination->id) {
            ReplicateBackup::dispatch($this->backup->id, $secondaryDestId);
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
        app(RetentionService::class)->apply($this->site, $destination);

        CircuitBreakerService::recordSuccess($this->site);
        JobTracker::complete($this->uniqueId(), 'Backup complete');
        static::releaseUniqueLock($this->site->id);
    }

    /**
     * Best-effort abort of an in-flight S3 multipart upload. Logs warning on
     * failure — we don't want abort errors to mask the original cause.
     */
    protected function abortMultipart(S3Driver $driver, string $remotePath, string $uploadId): void
    {
        try {
            $driver->abortMultipartUpload($remotePath, $uploadId);
        } catch (\Throwable $e) {
            Log::warning("S3 abortMultipartUpload failed for backup {$this->backupId}: {$e->getMessage()}");
        }
    }

    /**
     * Validate the freshly-built archive before upload. Catches truncation, CRC corruption,
     * malformed metadata, empty/broken DB dumps. Throws on failure (handled by handleFailure).
     *
     * @return array{ok: bool, message: string, checks: array<string, mixed>}
     */
    protected function verifyIntegrity(string $combinedPath, string $expectedSha256): array
    {
        $this->reportProgress('verifying', 72, 'Verifying archive integrity...');
        $this->logStep('Verifying archive integrity...');

        $verifier = app(\App\Services\Backup\IntegrityVerifier::class);
        $result = $verifier->verifyArchive($combinedPath, $expectedSha256);

        if (! $result['ok']) {
            $msg = "Archive integrity check failed: {$result['message']}";

            $this->backup->update([
                'verification_status' => 'failed',
                'verification_message' => $result['message'],
            ]);

            throw new \App\Exceptions\BackupException($msg, site: $this->site);
        }

        $this->logStep($result['message']);

        return $result;
    }

    protected function upload(StorageDestination $destination, string $combinedPath, string $fileName): string
    {
        $uploadSize = FormatHelper::bytes((int) filesize($combinedPath));
        $this->reportProgress('uploading', 75, 'Uploading to storage...');
        $this->logStep("Uploading to {$destination->name} ({$uploadSize})...");
        $this->touchHeartbeat();
        $uploadStart = microtime(true);
        $remotePath = $this->site->domain.'/'.$fileName;
        $driver = StorageFactory::make($destination);
        $driver->upload($combinedPath, $remotePath);
        $uploadDuration = round(microtime(true) - $uploadStart, 1);
        $this->logStep("Upload complete ({$uploadDuration}s)");
        $this->reportProgress('finalizing', 95, 'Finalizing...');

        return $remotePath;
    }

    /**
     * @param  array{ok: bool, message: string, checks: array<string, mixed>}  $integrity
     */
    protected function finalize(StorageDestination $destination, string $remotePath, string $fileName, int $fileSize, string $checksum, array $integrity): void
    {
        $primaryReplica = [[
            'destination_id' => $destination->id,
            'remote_path' => $remotePath,
            'uploaded_at' => now()->toIso8601String(),
            'status' => 'completed',
        ]];

        $this->backup->update([
            'status' => BackupStatus::Completed,
            'stage' => 'completed',
            'progress_percent' => 100,
            'progress_message' => 'Backup completed successfully',
            'error_message' => null,
            'file_path' => $remotePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'checksum' => $checksum,
            'replicas' => $primaryReplica,
            'completed_at' => now(),
            'verified_at' => now(),
            'verification_status' => 'passed',
            'verification_message' => $integrity['message'],
            'duration_seconds' => (int) $this->backup->started_at->diffInSeconds(now()),
            'is_locked' => $this->trigger === 'pre_update',
            'lock_reason' => $this->trigger === 'pre_update' ? 'pre-update' : null,
        ]);

        ActivityLogger::backupCompleted($this->site, $fileName, $fileSize);

        // Self-describing sidecar so the backup is reindexable without the Laravel DB.
        // Best-effort: failure here is logged but doesn't fail the backup.
        try {
            $sidecar = \App\Services\Backup\BackupSidecarMetadata::buildForV2Zip($this->backup->fresh(), $this->site);
            \App\Services\Backup\BackupSidecarMetadata::uploadAlongside(StorageFactory::make($destination), $remotePath, $sidecar);
        } catch (\Throwable $e) {
            Log::warning("Sidecar metadata write failed for backup {$this->backupId}: {$e->getMessage()}");
        }

        // Dispatch off-site replication if configured (3-2-1 rule). Failure here doesn't
        // fail the backup — primary upload already succeeded.
        $secondaryDestId = $this->site->backupConfig?->secondary_storage_destination_id;
        if ($secondaryDestId && $secondaryDestId !== $destination->id) {
            ReplicateBackup::dispatch($this->backup->id, $secondaryDestId);
        }

        // Generate manifest for incremental backup support (non-fatal)
        // Uses pre-collected manifest from the backup session if available (avoids re-scanning)
        if ($this->type === 'full') {
            try {
                $api = app(WordPressApiServiceFactory::class)->make($this->site);
                $manifestService = new \App\Services\Backup\ManifestService;
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
        app(RetentionService::class)->apply($this->site, $destination);

        CircuitBreakerService::recordSuccess($this->site);
        JobTracker::complete($this->uniqueId(), 'Backup complete');

        $duration = $this->backup->duration_seconds;
        $this->logStep("Backup completed in {$duration}s");

        // Release unique lock immediately so new backups can start
        static::releaseUniqueLock($this->site->id);
    }
}
