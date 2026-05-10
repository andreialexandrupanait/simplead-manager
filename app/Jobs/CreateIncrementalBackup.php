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

            [$combinedPath, $fileName, $fileSize, $checksum] = $this->createArchive(
                $dbPath, $filesChunkPaths, $deletedFilesPath
            );
            $this->logStep('Archive created ('.FormatHelper::bytes((int) $fileSize).')');

            $integrity = $this->verifyIntegrity($combinedPath, $checksum);

            // Step 6: Upload
            $uploadSize = FormatHelper::bytes((int) $fileSize);
            $this->reportProgress('uploading', 75, 'Uploading to storage...');
            $this->logStep("Uploading to {$destination->name} ({$uploadSize})...");
            $uploadStart = microtime(true);
            $remotePath = $this->site->domain.'/'.$fileName;
            $driver = StorageFactory::make($destination);
            $driver->upload($combinedPath, $remotePath);
            $uploadDuration = round(microtime(true) - $uploadStart, 1);
            $this->logStep("Upload complete ({$uploadDuration}s)");
            $this->reportProgress('finalizing', 90, 'Finalizing...');

            // Step 7: Generate new manifest
            try {
                $manifestService->generateAndStore($api, $this->backup, $destination);
            } catch (\Throwable $e) {
                Log::warning("Manifest generation failed for incremental backup {$this->backupId} (non-fatal): {$e->getMessage()}");
            }

            // Step 8: Finalize
            $this->finalize($destination, $remotePath, $fileName, $fileSize, $checksum, $integrity);

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
