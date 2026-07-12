<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Enums\BackupStatus;
use App\Helpers\FormatHelper;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Services\Backup\ManifestService;
use App\Services\Backup\PostRestoreVerifier;
use App\Services\Backup\SiteOperationLock;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\JobTracker;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

class RestoreBackup implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    /**
     * Attempts exist only so a restore can politely wait (release/requeue)
     * while another backup/restore holds the site lock. Real work never
     * retries: $maxExceptions = 1 fails the job on the first thrown error —
     * blindly re-running a half-applied restore is exactly the hazard the
     * site lock exists to prevent.
     */
    public int $tries = 4;

    public int $maxExceptions = 1;

    /**
     * The unique lock must outlive the job timeout but never wedge forever:
     * a SIGKILLed worker leaves it behind, and without an expiry the Retry
     * button silently no-ops until someone runs backup:release-lock.
     */
    public int $uniqueFor = 7200;

    public int $memory = 1024;

    protected ?string $tempDir = null;

    protected ?string $siteLockToken = null;

    protected bool $pluginWasUpdated = false;

    public function __construct(
        public Backup $backup,
        public bool $restoreDatabase = true,
        public bool $restoreFiles = true,
        public array $selectedFiles = [],
        /**
         * True when the user explicitly bypassed a FAILED pre-restore safety
         * backup (typed-confirmation flow). Logged loudly so a later failure
         * investigation immediately knows there is no safety net.
         */
        public bool $safetyBackupSkipped = false,
    ) {
        $this->onQueue('backups');
    }

    public function uniqueId(): string
    {
        return 'restore-'.$this->backup->id;
    }

    /**
     * Release the ShouldBeUnique lock (P1-08). Must go through the lock
     * primitive — same as Illuminate\Bus\UniqueLock — so it targets the
     * cache store's lock_connection, not the data connection that
     * Cache::forget() would (silently) hit on the redis store.
     */
    public static function releaseUniqueLock(int $backupId): void
    {
        Cache::lock('laravel_unique_job:'.static::class.':restore-'.$backupId)->forceRelease();
    }

    public function handle(): void
    {
        ini_set('memory_limit', '1G');

        // Re-run guard. A SIGKILLed worker (deploy/OOM) is redelivered by the
        // queue after retry_after, which coincides with the site lock's TTL —
        // so the redelivery can find a free lock and blindly re-run the whole
        // restore on the live site. Refuse that here, independent of lock timing:
        //   - an already-completed restore must never run again;
        //   - a redelivered attempt (attempts() > 1) may ONLY proceed when the
        //     restore is genuinely still Pending (it never actually started —
        //     e.g. earlier attempts only ever waited on a busy site lock and
        //     politely requeued). Any other status means a prior attempt already
        //     began (InProgress) or already terminated (Failed): re-running it
        //     in full is the exact data-loss hazard the site lock exists to
        //     prevent — a killed/failed restore that quietly re-applies ~2h
        //     later against a site an operator may have already hand-repaired
        //     (P0-06). The operator inspects the site and re-triggers manually.
        $freshStatus = $this->backup->fresh()?->restore_status;

        if ($freshStatus === BackupStatus::Completed) {
            Log::info("Restore of backup {$this->backup->id} already completed; skipping redelivered attempt.");

            return;
        }

        if ($this->attempts() > 1 && $freshStatus !== BackupStatus::Pending) {
            $this->fail(new \RuntimeException(
                "Restore of backup {$this->backup->id} was redelivered after a prior attempt that did "
                ."not complete cleanly (status: {$freshStatus?->value}); not re-running automatically. "
                .'Inspect the site and re-trigger manually.'
            ));

            return;
        }

        $site = $this->backup->site;

        $this->siteLockToken = SiteOperationLock::acquire(
            $site->id,
            SiteOperationLock::OPERATION_RESTORE,
            'backup:'.$this->backup->id,
        );

        if ($this->siteLockToken === null) {
            $holder = SiteOperationLock::current($site->id);
            $holderLabel = $holder ? $holder['operation'] : 'another operation';

            if ($this->attempts() >= $this->tries) {
                $this->fail(new \RuntimeException(
                    "Restore aborted: site is still busy with {$holderLabel} after {$this->attempts()} attempts."
                ));

                return;
            }

            $this->logRestoreStep("Site busy ({$holderLabel}); restore requeued for 3 minutes.");
            $this->release(180);

            return;
        }

        $this->tempDir = storage_path('app/temp/restore-'.uniqid());
        mkdir($this->tempDir, 0700, true);

        try {
            // P1-39: pre-flight disk-space check. A restore extracts the archive,
            // and a chain restore keeps per-chain extract dirs + a merged tree + a
            // re-zipped files.zip + a copy for the download endpoint — peaking at
            // several times the (compressed) archive size. Refuse up front rather
            // than fill the disk mid-restore and pause fleet-wide backups.
            // (Inside the try so the lock is released and the row marked failed.)
            $this->assertDiskSpaceForRestore();

            $this->backup->update([
                'restore_status' => BackupStatus::InProgress,
                'restore_stage' => 'downloading',
                'restore_progress_percent' => 10,
                'restore_progress_message' => 'Downloading backup from storage...',
                'restore_error_message' => null,
            ]);
            JobTracker::start($this->uniqueId(), 'Starting restore...');
            if ($this->safetyBackupSkipped) {
                $this->logRestoreStep('WARNING: SAFETY BACKUP SKIPPED by user — no pre-restore safety net exists for this restore.');
                Log::warning("Restore of backup {$this->backup->id} proceeding WITHOUT safety backup (user override)", [
                    'site_id' => $this->backup->site_id,
                ]);
            }
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
            SiteOperationLock::release($this->backup->site_id, $this->siteLockToken);
            $this->cleanup();
        }
    }

    /**
     * Safety net for deaths handle() cannot catch (timeout, maxExceptions,
     * fatal after requeue). Must be idempotent — the in-process catch block
     * usually ran first. A restore that died mid-flight may have left the
     * site half-restored, so the notification is critical and explicit.
     */
    public function failed(?\Throwable $exception): void
    {
        $backup = $this->backup->fresh();

        if ($backup && in_array($backup->restore_status, [BackupStatus::InProgress, BackupStatus::Pending], true)) {
            $message = $exception?->getMessage() ?? 'Restore job died unexpectedly (killed or timed out).';
            $backup->update([
                'restore_status' => BackupStatus::Failed,
                'restore_stage' => 'failed',
                'restore_progress_message' => 'Restore failed: '.Str::limit($message, 200),
                'restore_error_message' => $message,
            ]);
        }

        JobTracker::fail($this->uniqueId(), 'Restore failed'.($exception ? ': '.get_class($exception) : ''));

        static::releaseUniqueLock($this->backup->id);

        // failed() runs on a fresh deserialized instance, so the runtime
        // token is gone — release by ref match instead of blind force.
        $holder = SiteOperationLock::current($this->backup->site_id);
        if ($holder !== null
            && $holder['operation'] === SiteOperationLock::OPERATION_RESTORE
            && $holder['ref'] === 'backup:'.$this->backup->id) {
            SiteOperationLock::forceRelease($this->backup->site_id);
        }

        $site = $this->backup->site;
        if ($site) {
            ActivityLogger::restoreFailed($site, $exception?->getMessage() ?? 'Restore job died unexpectedly.');
            NotifyRestoreFailed::dispatch(
                $site,
                $this->backup,
                $exception?->getMessage() ?? 'Restore job died unexpectedly (killed or timed out).'
            );
        }
    }

    /**
     * Try downloading a backup archive from any healthy replica, falling back
     * across destinations on failure. Primary is tried first (most likely warm
     * + matches existing semantics), then any secondaries from $backup->replicas.
     *
     * @throws \RuntimeException if every replica fails
     */
    protected function downloadFromReplica(Backup $backup, string $localPath): void
    {
        $candidates = $this->collectReplicaCandidates($backup);
        if ($candidates === []) {
            throw new \RuntimeException("Backup #{$backup->id} has no available replicas to restore from.");
        }

        $errors = [];
        foreach ($candidates as $candidate) {
            $destination = StorageDestination::find($candidate['destination_id']);
            if (! $destination || ! $destination->is_active) {
                $errors[] = "destination #{$candidate['destination_id']} not available";

                continue;
            }

            try {
                $driver = StorageFactory::make($destination);
                $driver->download($candidate['remote_path'], $localPath);

                if (! is_file($localPath) || filesize($localPath) === 0) {
                    throw new \RuntimeException('downloaded file empty');
                }

                if ($candidate['is_primary'] === false) {
                    $this->logRestoreStep("Note: restored from secondary replica ({$destination->name})");
                }

                return;
            } catch (\Throwable $e) {
                $errors[] = "{$destination->name}: {$e->getMessage()}";
                @unlink($localPath);

                continue;
            }
        }

        throw new \RuntimeException(
            "Backup #{$backup->id} unavailable on all ".count($candidates).' replica(s): '.implode(' | ', $errors)
        );
    }

    /**
     * @return list<array{destination_id: int, remote_path: string, is_primary: bool}>
     */
    protected function collectReplicaCandidates(Backup $backup): array
    {
        $candidates = [];
        $seen = [];

        if ($backup->storage_destination_id && $backup->file_path) {
            $candidates[] = [
                'destination_id' => (int) $backup->storage_destination_id,
                'remote_path' => $backup->file_path,
                'is_primary' => true,
            ];
            $seen[(int) $backup->storage_destination_id] = true;
        }

        foreach ($backup->replicas ?? [] as $r) {
            $destId = (int) ($r['destination_id'] ?? 0);
            $path = $r['remote_path'] ?? null;
            $status = $r['status'] ?? 'completed';
            if (! $destId || ! $path || $status !== 'completed' || isset($seen[$destId])) {
                continue;
            }
            $candidates[] = [
                'destination_id' => $destId,
                'remote_path' => $path,
                'is_primary' => false,
            ];
            $seen[$destId] = true;
        }

        return $candidates;
    }

    /**
     * Restore a single (non-incremental) backup — original flow.
     */
    protected function restoreSingleBackup(): void
    {
        /** @var Site $site */
        $site = $this->backup->site;

        if ($this->backup->format === 'v3-zip') {
            $this->materialiseV3ZipBackup($this->backup, $this->tempDir);
        } elseif ($this->backup->format === \App\Services\Backup\BackupManifestV3::FORMAT) {
            $this->materialiseMultipartBackup($this->backup, $this->tempDir);
        } else {
            // Legacy v2-zip path: pull single archive, verify checksum, extract.
            $localPath = $this->tempDir.'/'.$this->backup->file_name;
            $this->downloadFromReplica($this->backup, $localPath);

            $downloadSize = file_exists($localPath) ? FormatHelper::bytes((int) filesize($localPath)) : '0 B';
            $this->logRestoreStep("Backup downloaded ({$downloadSize})");
            $this->reportRestoreProgress('downloading', 25, 'Backup downloaded');

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
        }

        $api = app(WordPressApiServiceFactory::class)->make($site);
        $this->ensurePluginUpToDate($api);
        $this->doRestore($api, $this->tempDir);
    }

    /**
     * Materialise a v3-zip backup into $targetDir in the layout doRestore() expects:
     * extracts the single .zip and re-packages the `files/` subtree as `files.zip`
     * (which doRestore sends to WP). The database.sql.gz lands at root naturally.
     */
    protected function materialiseV3ZipBackup(Backup $backup, string $targetDir): void
    {
        $localPath = $targetDir.'/'.$backup->file_name;
        $this->reportRestoreProgress('downloading', 15, 'Downloading v3-zip backup...');
        $this->downloadFromReplica($backup, $localPath);

        $downloadSize = file_exists($localPath) ? FormatHelper::bytes((int) filesize($localPath)) : '0 B';
        $this->logRestoreStep("Backup downloaded ({$downloadSize})");
        $this->reportRestoreProgress('downloading', 25, 'Backup downloaded');

        if ($backup->checksum) {
            $this->reportRestoreProgress('verifying', 30, 'Verifying backup integrity...');
            $hash = hash_file('sha256', $localPath);
            if ($hash !== $backup->checksum) {
                throw new \RuntimeException('v3-zip checksum verification failed. The file may be corrupted.');
            }
            $this->logRestoreStep('Integrity verified');
        }

        $this->reportRestoreProgress('extracting', 35, 'Extracting backup archive...');
        $zip = new ZipArchive;
        if ($zip->open($localPath) !== true) {
            throw new \RuntimeException('Failed to open v3-zip backup archive.');
        }
        $zip->extractTo($targetDir);
        $zip->close();
        @unlink($localPath);

        // After extract: $targetDir contains database.sql.gz, backup-meta.json, files/wp-admin/...
        // Re-package files/ subtree as files.zip with WP paths at root (what doRestore expects)
        $filesDir = $targetDir.'/files';
        $filesZip = $targetDir.'/files.zip';

        if (is_dir($filesDir)) {
            $this->reportRestoreProgress('extracting', 42, 'Re-packaging files for WordPress...');
            $repack = new ZipArchive;
            if ($repack->open($filesZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to create files.zip for WP transfer.');
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($filesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $relative = substr($file->getRealPath(), strlen($filesDir) + 1);
                    $repack->addFile($file->getRealPath(), $relative);
                }
            }
            $repack->close();

            // Remove extracted files/ subtree to free disk
            $rmIter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($filesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rmIter as $entry) {
                $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
            @rmdir($filesDir);
        }

        $this->reportRestoreProgress('extracting', 45, 'Backup ready for restore');
    }

    /**
     * Download a multipart-v3 backup into $targetDir and synthesize the layout
     * the legacy doRestore() code expects:
     *   - database.sql.gz   (kept as-is)
     *   - files_chunk_N.zip (renamed from chunks/N.zip)
     *   - backup-meta.json  (synthesized from manifest.json with format_version=2)
     *   - deleted-files.json (incremental only, kept as-is)
     */
    protected function materialiseMultipartBackup(Backup $backup, string $targetDir): void
    {
        $this->reportRestoreProgress('downloading', 10, 'Reading manifest...');
        $this->logRestoreStep("Restoring multipart-v3 backup #{$backup->id} from prefix {$backup->file_path}");

        // Pick a healthy replica destination (primary first, then secondaries)
        $destination = $this->resolveReplicaDestinationForMultipart($backup);
        $driver = StorageFactory::make($destination);

        $manifestRemote = $backup->file_path.'/'.\App\Services\Backup\BackupManifestV3::MANIFEST_FILENAME;
        $manifestLocal = $targetDir.'/'.\App\Services\Backup\BackupManifestV3::MANIFEST_FILENAME;
        $driver->download($manifestRemote, $manifestLocal);

        $manifest = \App\Services\Backup\BackupManifestV3::decode(file_get_contents($manifestLocal));
        $files = $manifest['files'];
        $totalFiles = count($files);
        $this->logRestoreStep("Manifest loaded: {$totalFiles} files to download");

        $chunkFileNames = [];
        foreach ($files as $i => $entry) {
            $remoteName = $entry['name'];
            $remotePath = $backup->file_path.'/'.$remoteName;

            // Map chunks/N.zip → files_chunk_N.zip in local layout
            $localName = $this->multipartLocalName($remoteName);
            $localPath = $targetDir.'/'.$localName;

            // Make sure subdirs exist for chunk files
            @mkdir(dirname($localPath), 0700, true);
            $driver->download($remotePath, $localPath);

            // Per-file SHA256 verification — multipart-v3 trusts manifest sha256s
            if (! empty($entry['sha256'])) {
                $actual = hash_file('sha256', $localPath);
                if ($actual !== $entry['sha256']) {
                    throw new \RuntimeException("multipart-v3 file {$remoteName} sha256 mismatch (expected {$entry['sha256']}, got {$actual})");
                }
            }

            if (str_starts_with($remoteName, 'chunks/')) {
                $chunkFileNames[] = $localName;
            }

            $pct = 10 + (int) (30 * ($i + 1) / $totalFiles);
            $this->reportRestoreProgress('downloading', min(40, $pct), 'Downloaded '.($i + 1)."/{$totalFiles} files");
        }

        // Synthesize the v2-style backup-meta.json so doRestore() doesn't need to know
        $synthMeta = [
            'site_name' => $manifest['site_name'] ?? null,
            'site_url' => $manifest['site_url'] ?? null,
            'type' => $manifest['type'] ?? 'full',
            'wp_version' => $manifest['wp_version'] ?? null,
            'php_version' => $manifest['php_version'] ?? null,
            'created_at' => $manifest['created_at'] ?? null,
            'trigger' => $manifest['trigger'] ?? 'manual',
            'format_version' => 2,
            'chunk_files' => $chunkFileNames,
        ];
        file_put_contents($targetDir.'/backup-meta.json', json_encode($synthMeta, JSON_PRETTY_PRINT));
        @unlink($manifestLocal);

        $this->reportRestoreProgress('extracting', 45, 'Backup ready for restore');
    }

    /**
     * Resolve the storage destination to download from for a multipart-v3 backup.
     * Falls back across replicas if primary is unavailable. Throws if none work.
     */
    protected function resolveReplicaDestinationForMultipart(Backup $backup): \App\Models\StorageDestination
    {
        $candidates = $this->collectReplicaCandidates($backup);
        foreach ($candidates as $candidate) {
            $destination = \App\Models\StorageDestination::find($candidate['destination_id']);
            if ($destination && $destination->is_active) {
                return $destination;
            }
        }
        throw new \RuntimeException("Backup #{$backup->id} has no active replica destination available.");
    }

    /**
     * Map a multipart-v3 manifest entry name to the local filename layout that
     * doRestore() / mergeChunkZipsForRestore() expect.
     */
    protected function multipartLocalName(string $remoteName): string
    {
        if (preg_match('#^chunks/(\d+)\.zip$#', $remoteName, $m)) {
            return "files_chunk_{$m[1]}.zip";
        }

        return $remoteName;
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
        mkdir($mergedDir, 0700, true);

        // P1-39: copy the latest DB dump to a stable path as we go so each
        // chain member's extract dir can be freed immediately after it is
        // merged — otherwise every per-chain extract accumulates on disk for
        // the whole chain and amplifies peak usage 3-4x the site size.
        $finalDbPath = $this->tempDir.'/database.sql.gz';
        $allDeletedPaths = [];

        // Process each backup in the chain
        foreach ($chain as $i => $chainBackup) {
            $stepNum = $i + 1;
            $pct = 10 + (int) (($stepNum / $chainLength) * 50); // 10-60%

            /** @var StorageDestination|null $destination */
            $this->reportRestoreProgress('downloading', $pct,
                "Downloading backup {$stepNum}/{$chainLength}...");
            $this->logRestoreStep("Downloading backup {$stepNum}/{$chainLength}...");

            $extractDir = $this->tempDir.'/extract_'.$i;
            mkdir($extractDir, 0700, true);

            if ($chainBackup->format === 'v3-zip') {
                // v3-zip: download single .zip, extract, repackage files/ as files.zip
                $this->materialiseV3ZipBackup($chainBackup, $extractDir);
            } elseif ($chainBackup->format === \App\Services\Backup\BackupManifestV3::FORMAT) {
                // Multipart-v3: download files into the extract dir directly (no outer zip)
                $this->materialiseMultipartBackup($chainBackup, $extractDir);
            } else {
                // Legacy v2-zip: download outer archive, verify, extract
                $localPath = $this->tempDir.'/chain_'.$i.'.zip';
                $this->downloadFromReplica($chainBackup, $localPath);

                if ($chainBackup->checksum) {
                    $hash = hash_file('sha256', $localPath);
                    if ($hash !== $chainBackup->checksum) {
                        throw new \RuntimeException("Checksum mismatch for backup #{$chainBackup->id} in chain (expected {$chainBackup->checksum}, got {$hash}).");
                    }
                }

                $zip = new ZipArchive;
                if ($zip->open($localPath) !== true) {
                    throw new \RuntimeException("Failed to open backup #{$chainBackup->id} archive.");
                }
                $zip->extractTo($extractDir);
                $zip->close();
                @unlink($localPath);
            }

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

            // Always use the latest database dump — copy it out immediately so
            // this extract dir can be freed before the next chain member.
            $dbFile = $extractDir.'/database.sql.gz';
            if (file_exists($dbFile)) {
                copy($dbFile, $finalDbPath);
            }

            // Free this chain member's extract dir now that its files are merged
            // and its DB (if any) has been copied out (P1-39).
            $this->deleteDir($extractDir);
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

        // Latest DB dump was already copied to $finalDbPath as the chain was
        // processed (P1-39), so nothing to move here.

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
        mkdir($extractDir, 0700, true);

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
        $expected = count($this->selectedFiles);
        $captured = 0;

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
                    $captured++;
                }
            }

            if ($dest->close() !== true) {
                $source->close();
                throw new \RuntimeException('Failed to finalise selective archive (zip write error).');
            }
            $source->close();
        } else {
            $extractDir = $this->tempDir.'/selective-extract';
            mkdir($extractDir, 0700, true);

            $cmd = ['tar', 'xzf', $innerArchivePath, '-C', $extractDir];
            foreach ($this->selectedFiles as $file) {
                $cmd[] = './'.ltrim($file, './');
            }

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            // P1-31: a failed/truncated tar extraction MUST fail the restore
            // loudly. Previously the exit code and stderr were discarded, so a
            // partial/empty extraction was packaged and reported as success.
            $process = proc_open($cmd, $descriptors, $pipes);
            if (! is_resource($process)) {
                throw new \RuntimeException('Failed to launch tar for selective restore extraction.');
            }

            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    "Selective restore extraction failed (tar exit code {$exitCode}): ".trim((string) $stderr)
                );
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
                    $captured++;
                }
            }

            if ($dest->close() !== true) {
                throw new \RuntimeException('Failed to finalise selective archive from tar.gz (zip write error).');
            }
        }

        // P1-31: never report success on a partial/empty selective extract.
        // If the user asked for files but none made it into the archive, the
        // extraction silently produced nothing — fail loudly instead.
        if ($expected > 0 && $captured === 0) {
            throw new \RuntimeException(
                'Selective restore produced an empty archive: none of the '.$expected.' selected file(s) could be extracted from the backup.'
            );
        }

        // Independently verify the on-disk archive really holds entries, so a
        // silent zip-write failure can never masquerade as a successful restore.
        $verify = new ZipArchive;
        if ($verify->open($selectivePath) !== true) {
            throw new \RuntimeException('Selective restore archive could not be reopened for verification.');
        }
        $entryCount = $verify->numFiles;
        $verify->close();

        if ($expected > 0 && $entryCount === 0) {
            throw new \RuntimeException('Selective restore archive is empty after extraction — refusing to report success.');
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

            // Full file restores use the connector's atomic staged swap
            // (connector >= 2.15.0; older connectors ignore the flag and
            // merge in place). Selective restores MUST merge — their archive
            // holds only the chosen files, and a swap would wipe the rest.
            $fileMode = empty($this->selectedFiles) ? 'staged' : 'merge';

            $result = $api->request('POST', '/backup/restore', [
                'type' => $type,
                'download_url' => $downloadUrl,
                'file_mode' => $fileMode,
            ], [], 1800);
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

    /**
     * P1-39: refuse a restore that clearly cannot fit on the working volume.
     * Estimate peak temp usage at 4x the (compressed) archive — chain restores
     * hold per-chain extracts + a merged tree + a re-zipped files.zip + the
     * download-endpoint copy simultaneously. Floors at 2 GB so a tiny archive
     * still demands sane headroom. Fails open when free space is unmeasurable.
     */
    protected function assertDiskSpaceForRestore(): void
    {
        $archiveBytes = (int) ($this->backup->file_size ?? 0);
        $required = max(2 * 1024 * 1024 * 1024, $archiveBytes * 4);

        $guard = app(\App\Services\Backup\DiskSpaceGuard::class);
        if ($guard->hasSpaceFor($required)) {
            return;
        }

        $free = $guard->freeBytes();
        throw new \RuntimeException(sprintf(
            'Insufficient disk space to restore backup #%d safely: need ~%s free, have %s. Free up space and retry.',
            $this->backup->id,
            FormatHelper::bytes($required),
            $free === null ? 'unknown' : FormatHelper::bytes($free),
        ));
    }

    /**
     * Recursively remove a directory (P1-39 incremental temp cleanup).
     */
    protected function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
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
