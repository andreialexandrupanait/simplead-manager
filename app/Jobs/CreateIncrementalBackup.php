<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Enums\BackupStatus;
use App\Exceptions\BackupException;
use App\Jobs\Concerns\BackupJobTrait;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Helpers\FormatHelper;
use App\Services\Backup\ManifestService;
use App\Services\Backup\RetentionService;
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

class CreateIncrementalBackup implements ShouldBeUnique, ShouldQueue
{
    use BackupJobTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 2700;

    public int $tries = 2;

    public array $backoff = [120];

    public int $uniqueFor = 2700;

    protected ?Backup $backup = null;

    protected ?string $tempDir = null;

    public function __construct(
        public Site $site,
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
        return 'Incremental backup';
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Creating incremental backup...');

        $this->tempDir = storage_path('app/temp/backup-inc-'.uniqid());
        mkdir($this->tempDir, 0700, true);

        try {
            $destination = $this->resolveStorageDestination();
            if (! $destination) {
                throw new BackupException('No storage destination available.', site: $this->site);
            }

            $manifestService = app(ManifestService::class);

            // Find parent backup with manifest
            $parentBackup = $manifestService->findLatestManifestBackup($this->site->id);

            if (! $parentBackup) {
                Log::info("No parent manifest found for site {$this->site->id}, dispatching full backup instead");
                $this->fallbackToFullBackup($destination);

                return;
            }

            $this->prepare($destination, $parentBackup);

            $api = app(WordPressApiServiceFactory::class)->make($this->site);

            // Step 1: Retrieve parent manifest
            $this->reportProgress('loading_manifest', 5, 'Loading previous manifest...');
            $this->logStep('Loading manifest from parent backup...');
            $previousManifest = $manifestService->retrieve($parentBackup);
            $this->reportProgress('loading_manifest', 10, 'Manifest loaded: '.count($previousManifest).' files');
            $this->logStep('Manifest loaded: '.count($previousManifest).' files');

            // Step 2: Send manifest to WP for diff
            $this->checkCancelled();
            $this->reportProgress('comparing', 12, 'Comparing files on WordPress...');
            $initResponse = $api->request('POST', '/backup/incremental-init', [
                'manifest' => $previousManifest,
            ], [], 300);

            if (! $initResponse->successful() || empty($initResponse->json()['success'])) {
                $error = $initResponse->json()['error']['message'] ?? 'HTTP '.$initResponse->status();
                throw new \RuntimeException("Incremental init failed: {$error}");
            }

            $initData = $initResponse->json();
            $changedCount = $initData['changed_count'] ?? 0;
            $newCount = $initData['new_count'] ?? 0;
            $deletedCount = $initData['deleted_count'] ?? 0;
            $deletedPaths = $initData['deleted_paths'] ?? [];
            $filesToken = $initData['token'] ?? null;
            $totalFileChunks = $initData['total_chunks'] ?? 0;

            Log::info("Incremental backup {$this->backupId}: {$changedCount} changed, {$newCount} new, {$deletedCount} deleted, {$totalFileChunks} chunks");

            $this->backup->update([
                'files_changed_count' => $changedCount + $newCount,
                'files_deleted_count' => $deletedCount,
            ]);

            $this->reportProgress('comparing', 15, "Found {$changedCount} changed, {$newCount} new, {$deletedCount} deleted files");
            $this->logStep("Comparing files: {$changedCount} changed, {$newCount} new, {$deletedCount} deleted");

            // Step 3: Download database (always full dump)
            $this->checkCancelled();
            $this->reportProgress('downloading_database', 20, 'Downloading database...');
            $this->logStep("Downloading database from {$this->site->domain}...");
            $dbPath = $this->tempDir.'/database.sql.gz';
            $dbStart = microtime(true);
            $dbChunkCounter = 0;
            $api->chunkedDownload('db', $dbPath, function (int $downloaded, int $total) use (&$dbChunkCounter, $dbStart) {
                $dbChunkCounter++;
                $pct = 20 + (int) (($downloaded / max($total, 1)) * 15);
                $this->reportProgress('downloading_database', $pct, "Downloading database... chunk {$downloaded}/{$total}");
                if ($dbChunkCounter % 5 === 0 || $downloaded === $total) {
                    $this->logStep("Database chunk {$downloaded}/{$total}");
                }
            }, fn () => $this->checkCancelled());
            $dbDuration = round(microtime(true) - $dbStart, 1);
            $dbSize = file_exists($dbPath) ? FormatHelper::bytes((int) filesize($dbPath)) : '0 B';
            $this->logStep("Database downloaded ({$dbSize} in {$dbDuration}s)");
            $this->reportProgress('downloading_database', 35, 'Database downloaded');

            // Step 4: Download changed files (if any)
            $filesChunkPaths = [];
            if ($totalFileChunks > 0 && $filesToken) {
                $this->checkCancelled();
                $this->reportProgress('downloading_files', 40, 'Downloading changed files...');
                $this->logStep("Downloading changed files ({$totalFileChunks} chunks)...");

                $filesChunkPaths = $this->downloadIncrementalFiles($api, $filesToken, $totalFileChunks, $this->tempDir.'/files.zip');
                $this->logStep("Changed files downloaded ({$totalFileChunks} chunks)");
                $this->reportProgress('downloading_files', 60, 'Changed files downloaded');
            }

            // Step 5: Create archive
            $this->checkCancelled();
            $this->reportProgress('creating_archive', 65, 'Creating incremental archive...');
            $this->logStep('Creating archive...');

            // Save deleted files list
            $deletedFilesPath = $this->tempDir.'/deleted-files.json';
            file_put_contents($deletedFilesPath, json_encode($deletedPaths, JSON_PRETTY_PRINT));

            // v3-zip: single write path (replaces v2-zip + multipart-v3 paths)
            $this->runV3ZipPipeline($destination, $dbPath, $filesChunkPaths, $deletedFilesPath);

            // Manifest for the next incremental in the chain (non-fatal)
            try {
                $manifestService->generateAndStore($api, $this->backup, $destination);
            } catch (\Throwable $e) {
                Log::warning("Manifest generation failed for incremental backup {$this->backupId} (non-fatal): {$e->getMessage()}");
            }

        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Download incremental file chunks using the chunked prepare flow.
     * Returns array of chunk file paths (no merge step).
     */
    protected function downloadIncrementalFiles(WordPressApiServiceInterface $api, string $token, int $totalChunks, string $saveTo): array
    {
        $dir = dirname($saveTo);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $chunkFiles = [];

        for ($i = 0; $i < $totalChunks; $i++) {
            // Execute chunk on WP
            $execResponse = $api->request('POST', '/backup/prepare-chunk-exec', [
                'token' => $token,
                'chunk_index' => $i,
            ], [], 300);

            if (! $execResponse->successful() || empty($execResponse->json()['success'])) {
                $error = $execResponse->json()['error']['message'] ?? "HTTP {$execResponse->status()}";
                throw new \RuntimeException("Incremental files chunk {$i} exec failed: {$error}");
            }

            Log::info("Incremental files chunk {$i}/{$totalChunks} executed");

            // Download chunk zip
            $chunkTempFile = $saveTo.'.chunk_'.$i.'_files.zip';
            $this->streamDownloadChunk($api, $token, $i, $chunkTempFile);
            $chunkFiles[] = $chunkTempFile;

            $pct = 40 + (int) ((($i + 1) / $totalChunks) * 20);
            $this->reportProgress('downloading_files', $pct, 'Downloaded chunk '.($i + 1)."/{$totalChunks}");
        }

        // Cleanup on WP
        try {
            $api->request('POST', '/backup/cleanup', ['token' => $token], [], 10);
        } catch (\Throwable) {
        }

        return $chunkFiles;
    }

    protected function streamDownloadChunk(WordPressApiServiceInterface $api, string $token, int $chunkIndex, string $saveTo): void
    {
        $api->streamDownloadTo('/backup/prepare-chunk-download', [
            'token' => $token,
            'chunk_index' => $chunkIndex,
            'delete' => true,
        ], $saveTo);
    }

    protected function createArchive(string $dbPath, array $filesChunkPaths, string $deletedFilesPath): array
    {
        $timestamp = now()->format('Y-m-d-His');
        $fileName = "{$this->site->domain}-incremental-{$timestamp}.zip";
        $combinedPath = $this->tempDir.'/'.$fileName;

        $zip = new ZipArchive;
        if ($zip->open($combinedPath, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Failed to create incremental backup archive.');
        }

        $zip->addFile($dbPath, 'database.sql.gz');
        $zip->setCompressionName('database.sql.gz', ZipArchive::CM_STORE);

        $chunkFileNames = [];
        foreach ($filesChunkPaths as $idx => $chunkPath) {
            if (! file_exists($chunkPath)) {
                continue;
            }
            $entryName = "files_chunk_{$idx}.zip";
            $zip->addFile($chunkPath, $entryName);
            $zip->setCompressionName($entryName, ZipArchive::CM_STORE);
            $chunkFileNames[] = $entryName;
        }

        $zip->addFile($deletedFilesPath, 'deleted-files.json');

        $metaData = [
            'site_name' => $this->site->name,
            'site_url' => $this->site->url,
            'type' => 'incremental',
            'parent_backup_id' => $this->backup->parent_backup_id,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'created_at' => now()->toIso8601String(),
            'trigger' => $this->trigger,
            'files_changed_count' => $this->backup->files_changed_count,
            'files_deleted_count' => $this->backup->files_deleted_count,
        ];

        if (! empty($chunkFileNames)) {
            $metaData['format_version'] = 2;
            $metaData['chunk_files'] = $chunkFileNames;
        }

        $zip->addFromString('backup-meta.json', json_encode($metaData, JSON_PRETTY_PRINT));

        if (! $zip->close()) {
            throw new \RuntimeException('Failed to finalize incremental backup archive.');
        }

        $this->reportProgress('creating_archive', 70, 'Archive created');

        $fileSize = filesize($combinedPath);
        $checksum = hash_file('sha256', $combinedPath);

        // Cleanup chunk temp files
        foreach ($filesChunkPaths as $f) {
            @unlink($f);
        }

        return [$combinedPath, $fileName, $fileSize, $checksum];
    }

    /**
     * v3-zip pipeline for incremental backups: build single .zip with proper WP
     * structure on local disk, including deleted-files.json for the diff state.
     *
     * @param  list<string>  $filesChunkPaths
     */
    protected function runV3ZipPipeline(StorageDestination $destination, string $dbPath, array $filesChunkPaths, string $deletedFilesPath): void
    {
        $now = now();
        $fileName = "{$this->site->domain}-incremental-{$now->format('Y-m-d-His')}.zip";
        $outputPath = $this->tempDir.'/'.$fileName;

        $this->reportProgress('creating_archive', 65, 'Building incremental archive (v3-zip)...');
        $this->logStep("Building consolidated v3-zip archive: {$fileName}");

        $builder = new \App\Services\Backup\BackupZipBuilder($outputPath);
        try {
            $totalChunks = count($filesChunkPaths);
            foreach ($filesChunkPaths as $i => $chunkPath) {
                $count = $builder->addEntriesFromZip($chunkPath, 'files/');
                @unlink($chunkPath);
                $pct = 65 + (int) (15 * ($i + 1) / max(1, $totalChunks));
                $this->reportProgress('creating_archive', min(80, $pct),
                    "Consolidated chunk ".($i + 1)."/{$totalChunks} ({$count} entries)");
            }

            $builder->addFileFromPath($dbPath, 'database.sql.gz');
            @unlink($dbPath);

            $builder->addFileFromPath($deletedFilesPath, 'deleted-files.json');
            @unlink($deletedFilesPath);

            $builder->addString('backup-meta.json', json_encode([
                'site_id' => $this->site->id,
                'site_url' => $this->site->url,
                'site_domain' => $this->site->domain,
                'site_name' => $this->site->name,
                'type' => 'incremental',
                'trigger' => $this->trigger,
                'created_at' => $now->toIso8601String(),
                'parent_backup_id' => $this->backup->parent_backup_id,
                'wp_version' => $this->site->wp_version,
                'php_version' => $this->site->php_version,
                'format' => 'v3-zip',
            ], JSON_PRETTY_PRINT));

            $result = $builder->finish();
        } catch (\Throwable $e) {
            $builder->abort();
            throw $e;
        }

        // Verify
        $verifier = app(\App\Services\Backup\IntegrityVerifier::class);
        $integrity = $verifier->verifyV3Zip($outputPath, $result['sha256']);
        if (! $integrity['ok']) {
            $this->backup->update([
                'verification_status' => 'failed',
                'verification_message' => $integrity['message'],
            ]);
            throw new \App\Exceptions\BackupException(
                "v3-zip integrity check failed: {$integrity['message']}",
                site: $this->site
            );
        }

        // Upload
        $uploadSize = FormatHelper::bytes($result['size']);
        $this->reportProgress('uploading', 82, 'Uploading to storage...');
        $this->logStep("Uploading to {$destination->name} ({$uploadSize})...");
        $this->touchHeartbeat();
        $remotePath = $this->site->domain.'/'.$fileName;
        $driver = StorageFactory::make($destination);
        $driver->upload($outputPath, $remotePath);
        $this->reportProgress('finalizing', 92, 'Finalizing...');

        // Finalize
        $this->finalizeV3Zip($destination, $remotePath, $fileName, $result['size'], $result['sha256'], $integrity);
    }

    /**
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
            'progress_message' => 'Incremental backup completed (v3-zip)',
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
        ]);

        ActivityLogger::backupCompleted($this->site, $fileName, $fileSize);

        $this->touchHeartbeat();
        try {
            $sidecar = \App\Services\Backup\BackupSidecarMetadata::buildForV2Zip($this->backup->fresh(), $this->site);
            $sidecar['format'] = 'v3-zip';
            \App\Services\Backup\BackupSidecarMetadata::uploadAlongside(StorageFactory::make($destination), $remotePath, $sidecar);
        } catch (\Throwable $e) {
            Log::warning("Sidecar metadata write failed for backup {$this->backupId}: {$e->getMessage()}");
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
            ]);
        }

        $destination->increment('used_bytes', $fileSize);
        app(RetentionService::class)->apply($this->site, $destination);

        CircuitBreakerService::recordSuccess($this->site);
        JobTracker::complete($this->uniqueId(), 'Incremental backup complete');

        $secondaryDestId = $this->site->backupConfig?->secondary_storage_destination_id;
        if ($secondaryDestId && $secondaryDestId !== $destination->id) {
            ReplicateBackup::dispatch($this->backup->id, $secondaryDestId);
        }

        static::releaseUniqueLock($this->site->id);
    }

    /**
     * @deprecated Replaced by runV3ZipPipeline. Kept for reference until clean-up.
     *
     * @param  list<string>  $filesChunkPaths
     */
    protected function runStreamingPipeline(StorageDestination $destination, string $dbPath, array $filesChunkPaths, string $deletedFilesPath): void
    {
        $now = now();
        $remotePrefix = \App\Services\Backup\BackupManifestV3::prefixFor($this->site->domain, 'incremental', $now);

        $this->reportProgress('uploading', 65, 'Streaming incremental upload to storage...');
        $this->logStep("Streaming incremental backup to {$destination->name} ({$remotePrefix})");

        $uploader = new \App\Services\Backup\StreamingBackupUploader($destination, $remotePrefix);

        try {
            $uploader->addFile($dbPath, 'database.sql.gz');
            $uploader->addFile($deletedFilesPath, 'deleted-files.json');

            $totalChunks = count($filesChunkPaths);
            foreach ($filesChunkPaths as $i => $chunkPath) {
                $uploader->addFile($chunkPath, "chunks/{$i}.zip");
                $pct = 70 + (int) (20 * ($i + 1) / max(1, $totalChunks));
                $this->reportProgress('uploading', min(90, $pct), 'Uploaded chunk '.($i + 1)."/{$totalChunks}");
            }

            $manifest = \App\Services\Backup\BackupManifestV3::build(
                siteId: $this->site->id,
                siteUrl: $this->site->url,
                siteDomain: $this->site->domain,
                siteName: $this->site->name,
                type: 'incremental',
                trigger: $this->trigger,
                wpVersion: $this->site->wp_version,
                phpVersion: $this->site->php_version,
                parentBackupId: $this->backup->parent_backup_id,
                files: $uploader->entries(),
            );
            $uploader->uploadManifest($manifest);
            $this->logStep('manifest.json uploaded');
        } catch (\Throwable $e) {
            $uploader->rollback();
            throw $e;
        }

        $this->finalizeMultipart($destination, $remotePrefix, $uploader->entries(), $uploader->totalBytes());
    }

    /**
     * @param  list<array{name: string, size: int, sha256: string}>  $entries
     */
    protected function finalizeMultipart(StorageDestination $destination, string $remotePrefix, array $entries, int $totalBytes): void
    {
        $compositeChecksum = hash('sha256', implode('', array_column($entries, 'sha256')));

        $primaryReplica = [[
            'destination_id' => $destination->id,
            'remote_path' => $remotePrefix,
            'uploaded_at' => now()->toIso8601String(),
            'status' => 'completed',
        ]];

        $this->backup->update([
            'status' => BackupStatus::Completed,
            'stage' => 'completed',
            'progress_percent' => 100,
            'progress_message' => 'Incremental backup completed (streaming)',
            'error_message' => null,
            'file_path' => $remotePrefix,
            'file_name' => \App\Services\Backup\BackupManifestV3::MANIFEST_FILENAME,
            'file_size' => $totalBytes,
            'checksum' => $compositeChecksum,
            'format' => \App\Services\Backup\BackupManifestV3::FORMAT,
            'replicas' => $primaryReplica,
            'completed_at' => now(),
            'verified_at' => now(),
            'verification_status' => 'passed',
            'verification_message' => sprintf('streaming ok: %d files, %s', count($entries), FormatHelper::bytes($totalBytes)),
            'duration_seconds' => (int) $this->backup->started_at->diffInSeconds(now()),
        ]);

        ActivityLogger::backupCompleted($this->site, $remotePrefix, $totalBytes);

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

        $destination->increment('used_bytes', $totalBytes);
        app(RetentionService::class)->apply($this->site, $destination);

        CircuitBreakerService::recordSuccess($this->site);
        JobTracker::complete($this->uniqueId(), 'Incremental backup complete');

        $secondaryDestId = $this->site->backupConfig?->secondary_storage_destination_id;
        if ($secondaryDestId && $secondaryDestId !== $destination->id) {
            ReplicateBackup::dispatch($this->backup->id, $secondaryDestId);
        }

        static::releaseUniqueLock($this->site->id);
    }

    /**
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

    protected function fallbackToFullBackup(StorageDestination $destination): void
    {
        Log::info("Falling back to full backup for site {$this->site->id}");
        JobTracker::complete($this->uniqueId(), 'No parent manifest, dispatching full backup');

        CreateBackup::dispatch(
            $this->site,
            'full',
            $this->trigger,
            $destination->id,
        );
    }

    protected function prepare(StorageDestination $destination, Backup $parentBackup): void
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
                'parent_backup_id' => $parentBackup->id,
            ]);
        } else {
            // Clean up any orphaned incremental backup from a previous failed attempt
            Backup::where('site_id', $this->site->id)
                ->where('type', 'incremental')
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
                'parent_backup_id' => $parentBackup->id,
                'type' => 'incremental',
                'trigger' => $this->trigger,
                'status' => BackupStatus::InProgress,
                'includes_database' => true,
                'includes_files' => true,
                'wp_version' => $this->site->wp_version,
                'php_version' => $this->site->php_version,
                'plugins_count' => $this->site->sitePlugins()->count(),
                'themes_count' => $this->site->siteThemes()->count(),
                'db_size_mb' => $this->site->db_size_mb,
                'started_at' => now(),
            ]);
            $this->backupId = $this->backup->id;
        }

        $this->reportProgress('initializing', 5, 'Initializing incremental backup...');
        $this->logStep("Initializing incremental backup for {$this->site->domain}");
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
            'progress_message' => 'Incremental backup completed successfully',
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
        ]);

        ActivityLogger::backupCompleted($this->site, $fileName, $fileSize);

        try {
            $sidecar = \App\Services\Backup\BackupSidecarMetadata::buildForV2Zip($this->backup->fresh(), $this->site);
            \App\Services\Backup\BackupSidecarMetadata::uploadAlongside(StorageFactory::make($destination), $remotePath, $sidecar);
        } catch (\Throwable $e) {
            Log::warning("Sidecar metadata write failed for backup {$this->backupId}: {$e->getMessage()}");
        }

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
            ]);
        }

        $destination->increment('used_bytes', $fileSize);
        app(RetentionService::class)->apply($this->site, $destination);

        CircuitBreakerService::recordSuccess($this->site);
        JobTracker::complete($this->uniqueId(), 'Incremental backup complete');

        $duration = $this->backup->duration_seconds;
        $this->logStep("Incremental backup completed in {$duration}s");

        // Release unique lock immediately so new backups can start
        static::releaseUniqueLock($this->site->id);
    }
}
