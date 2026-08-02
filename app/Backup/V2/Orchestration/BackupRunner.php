<?php

declare(strict_types=1);

namespace App\Backup\V2\Orchestration;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Exceptions\PreflightFailed;
use App\Backup\V2\Exceptions\SiteUnderStrain;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Plugin\DownloadedChunk;
use App\Backup\V2\Plugin\PluginClient;
use App\Backup\V2\StateMachine\BackupStateMachine;
use App\Backup\V2\Storage\BackupSessionProgressStore;
use App\Backup\V2\Storage\HardenedMultipartUploader;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\WorkDir;
use App\Backup\V2\Support\BackupLogger;
use Aws\S3\S3Client;
use Closure;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Drives a single BackupSession through the V2 FSM to a verified, completed
 * backup on S3 — the piece that ties capability probe, DB dump, file chunking,
 * hardened multipart upload, verification and manifest/_COMPLETE together.
 *
 * FSM path (every transition goes through BackupSession::transitionTo — no
 * shortcuts):
 *   requested → capability_check → inventory → database_export → file_diff →
 *   chunking → upload_initializing → uploading → upload_verifying → finalizing →
 *   completed
 *
 * Endpoint ↔ state:
 *   capability_check   → plugin capabilities()
 *   inventory          → plugin filesInventory()            (files scope)
 *   database_export    → plugin dbDump() + dbChunkDownload()→S3 database/ (db scope)
 *   uploading          → plugin filesChunkExec()+filesChunkDownload()→S3 files/
 *   upload_verifying   → headObject size + sha256 re-verify per confirmed object
 *   finalizing         → manifest.json / checksums.json / metadata.json, then
 *                        _COMPLETE written LAST, then → completed
 *
 * Idempotent + resumable: every uploaded object is recorded in
 * session.confirmed_objects; a resumed run skips confirmed objects (never
 * re-pulls a pull-and-freed chunk, never double-uploads), and the DB dump is
 * short-circuited once checkpoint.db_done is set. Multipart resume is handled a
 * layer down by HardenedMultipartUploader via session.confirmed_parts.
 *
 * verify-before-complete: the session only reaches `completed` after every
 * declared object is verified present in storage and _COMPLETE is the final write.
 * Any missing/mismatched object → `corrupt` (never `completed`).
 */
final class BackupRunner
{
    /** Ordered happy path — used to skip already-passed phases on resume. */
    private const ORDER = [
        S::Requested->value,
        S::CapabilityCheck->value,
        S::Inventory->value,
        S::DatabaseExport->value,
        S::FileDiff->value,
        S::Chunking->value,
        S::UploadInitializing->value,
        S::Uploading->value,
        S::UploadVerifying->value,
        S::Finalizing->value,
        S::Completed->value,
    ];

    private readonly BackupLogger $logger;

    private readonly BackupSessionProgressStore $progress;

    /**
     * @param  int|null  $fileChunkThreshold  bytes per file chunk (null → plugin default 100 MiB)
     * @param  int|null  $dbSegmentBytes  uncompressed bytes per DB segment (null → client default)
     * @param  Closure(string $kind, int $index, int $confirmedCount):void|null  $faultAfterObject
     *                                                                                              Test seam: invoked AFTER an object is durably confirmed (crash injection for the resume test).
     * @param  Closure(BackupSession):(list<array{p:string,sha256:string,s:int,m?:int}>|null)|null  $baseManifestProvider
     *                                                                                                                     Incremental only: returns the materialised base file-state (from ChainResolver) to diff
     *                                                                                                                     against, or null for a full/no-baseline run. Wired by the dispatcher/test so the runner stays
     *                                                                                                                     decoupled from where base manifests live.
     */
    public function __construct(
        private readonly BackupSession $session,
        private readonly PluginClient $client,
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly ObjectLayout $layout,
        ?BackupLogger $logger = null,
        private readonly ?int $fileChunkThreshold = null,
        private readonly ?int $dbSegmentBytes = null,
        private readonly string $compression = 'store',
        private readonly ?Closure $faultAfterObject = null,
        private readonly ?Closure $baseManifestProvider = null,
        // Reads (upload_verifying, presence checks) go through their own client so
        // they can retry a throttle without stacking a retry layer underneath
        // HardenedMultipartUploader, which already retries each part itself.
        // Defaults to $s3, so every existing caller and test keeps working.
        private readonly ?S3Client $readS3 = null,
    ) {
        $this->logger = ($logger ?? new BackupLogger)->forSession(
            'backup',
            $this->session->id,
            $this->session->site_id,
        );
        $this->progress = new BackupSessionProgressStore($this->session);
        $this->profile = ResourceProfile::named($this->session->resource_profile);
        $this->impact = new SiteImpactMonitor;
    }

    private readonly ResourceProfile $profile;

    private readonly SiteImpactMonitor $impact;

    /** @var array<string, string> object key → sha256 of its plaintext */
    private array $plaintextHashes = [];

    private bool $cipherResolved = false;

    private ?\App\Backup\V2\Crypto\ObjectCipher $cipherInstance = null;

    /**
     * Run (or resume) the session to a terminal state. Returns the session.
     */
    public function run(): BackupSession
    {
        if ($this->session->state->isTerminal()) {
            return $this->session;
        }

        $this->reenterAfterPause();

        try {
            $this->phase(S::CapabilityCheck, 'probing host capabilities', fn () => $this->capabilityCheck());
            $this->phase(S::Inventory, 'building file inventory', fn () => $this->inventory());
            $this->phase(S::DatabaseExport, 'exporting database', fn () => $this->databaseExport());
            $this->phase(S::FileDiff, 'computing file diff', fn () => $this->fileDiff());
            $this->phase(S::Chunking, 'planning file chunks', fn () => $this->chunking());
            $this->phase(S::UploadInitializing, 'initialising upload', fn () => $this->uploadInitializing());
            $this->phase(S::Uploading, 'uploading file chunks', fn () => $this->uploading());
            $this->phase(S::UploadVerifying, 'verifying stored objects', fn () => $this->verify());
            $this->phase(S::Finalizing, 'writing manifest + completion marker', fn () => $this->finalize());
        } catch (CorruptBackupException $e) {
            $this->session->recordError(BackupErrorCode::ChecksumMismatch, $e->getMessage());
            $this->session->transitionTo(S::Corrupt);

            return $this->session;
        } catch (SiteUnderStrain $e) {
            // Parked, deliberately, not failed. Continuing would cost the site's
            // visitors more than finishing tonight is worth, and the checkpoint
            // survives — so the price of stopping too early is a delay, which is
            // the right way round for a judgement made about somebody else's
            // server.
            $this->session->recordError($e->errorCode, $e->getMessage());
            if (BackupStateMachine::canTransition($this->session->state, S::RetryWait)) {
                $this->mergeCheckpoint(['resume_from' => $this->session->state->value]);
                $this->session->next_retry_at = now()->addHour();
                $this->session->transitionTo(S::RetryWait, 'paused: the site was struggling');
            }

            return $this->session;
        } catch (PreflightFailed $e) {
            // Terminal, not parked. A host with no free disk, an ancient plugin
            // or a bucket that rejects writes does not fix itself in five
            // minutes, and retrying costs the site another walk of its
            // filesystem to reach the same answer. It ends here, with the
            // precondition named in the alert.
            $this->session->recordError($e->errorCode, $e->getMessage());
            if (BackupStateMachine::canTransition($this->session->state, S::Failed)) {
                $this->session->transitionTo(S::Failed, 'preflight refused the backup');
            }

            return $this->session;
        } catch (Throwable $e) {
            // Everything that is not corruption used to escape this method
            // entirely. The session stayed in whatever phase it died in, with no
            // error recorded and no terminal state — and a session frozen at
            // `uploading` is indistinguishable from one still uploading, so
            // nothing alerted and nobody knew.
            //
            // It parks in retry_wait, NOT failed. `failed` is terminal, and run()
            // returns immediately from a terminal session — so recording the
            // error as failed here made a crashed backup impossible to resume,
            // which is the one property this engine exists for. retry_wait is
            // equally visible and equally un-confusable with "still uploading",
            // but it keeps the checkpoint live: the next attempt skips every
            // object already confirmed. The job's failed() hook is what declares
            // a session dead, once the queue has stopped retrying.
            $this->session->recordError(BackupErrorCode::EngineError, $e->getMessage());
            if (BackupStateMachine::canTransition($this->session->state, S::RetryWait)) {
                $this->mergeCheckpoint(['resume_from' => $this->session->state->value]);
                $this->session->next_retry_at = now()->addMinutes(5);
                $this->session->transitionTo(S::RetryWait, 'engine error: '.class_basename($e));
            }

            throw $e;
        }

        return $this->session;
    }

    // ── phase dispatch ───────────────────────────────────────────────────

    /**
     * Put a parked session back on the linear path before dispatching phases.
     *
     * retry_wait and paused are not on ORDER, so orderIndex() reports them as
     * PHP_INT_MAX — "past everything". Dispatching phases from there skipped all
     * ten of them and returned the session still parked: a resume that did
     * nothing at all and said nothing about it. The phase we parked from is
     * recorded in the checkpoint, so re-entering it is exact rather than guessed,
     * and phase() then re-runs that one phase from its own checkpoint.
     */
    private function reenterAfterPause(): void
    {
        if (! in_array($this->session->state, [S::RetryWait, S::Paused], true)) {
            return;
        }

        $from = S::tryFrom((string) ($this->checkpoint()['resume_from'] ?? ''));
        // Nothing recorded (a session parked by an older build, or by an operator
        // before any phase ran) restarts from the top. Every phase is idempotent
        // and skips its own confirmed work, so that is safe, only slower.
        $target = $from !== null && in_array($from->value, self::ORDER, true)
            ? $from
            : S::CapabilityCheck;

        $this->session->transitionTo($target, 'resuming from '.$this->session->state->value);
    }

    /**
     * Enter $target (through the state machine) and run $work, unless the session
     * has already progressed past $target (resume). Re-entering the CURRENT state
     * is an idempotent self-transition, so a crash mid-phase re-runs that phase.
     */
    private function phase(S $target, string $stage, callable $work): void
    {
        if ($this->orderIndex($this->session->state) > $this->orderIndex($target)) {
            return; // already past this phase on a resumed run
        }

        $this->session->transitionTo($target, $stage);
        $this->session->heartbeat();
        $work();
    }

    private function orderIndex(S $state): int
    {
        $i = array_search($state->value, self::ORDER, true);

        return $i === false ? PHP_INT_MAX : (int) $i;
    }

    // ── phases ───────────────────────────────────────────────────────────

    private function capabilityCheck(): void
    {
        $caps = $this->client->capabilities();

        // Refuse here or not at all. Everything after this point costs the client's
        // site real work — walking its filesystem, building zips, holding a
        // transaction open — and none of it is recoverable if the host was never
        // in a state to finish. The probe already asked all of these questions and
        // used almost none of the answers.
        $preflight = new PreflightCheck;
        $preflight->assertHostIsReady($caps, $this->profile->minFreeDiskMb);
        $preflight->assertStorageAccepts($this->s3, $this->bucket, $this->layout);

        $this->mergeCheckpoint([
            'capabilities' => [
                'plugin_version' => $caps['plugin']['version'] ?? null,
                'wp_version' => $caps['wordpress']['version'] ?? null,
                'php_version' => $caps['php']['version'] ?? null,
                'db_server_version' => $caps['database']['server_version'] ?? null,
                'db_engine' => $caps['database']['engine_family'] ?? null,
                'consistent_snapshot' => (bool) ($caps['database']['consistent_snapshot_supported'] ?? false),
                'non_innodb_tables' => $caps['database']['non_innodb_tables'] ?? [],
            ],
        ]);

        // A DB-bearing backup on a host that cannot take a consistent snapshot must
        // NOT be silently completed as if it were consistent. Record the limitation;
        // databaseExport() enforces it against the dump's own consistent=false.
        if ($this->includeDatabase() && ! ($caps['database']['consistent_snapshot_supported'] ?? false)) {
            $this->session->recordError(
                BackupErrorCode::SnapshotUnavailable,
                'Host cannot take a consistent DB snapshot; a consistent DB backup is not possible.',
            );
            $this->mergeCheckpoint(['db_consistency_warning' => true]);
        }
    }

    private function inventory(): void
    {
        if (! $this->includeFiles()) {
            return;
        }

        // The profile decides how big a zip the host is asked to build and
        // whether it spends CPU compressing it. Both were accepted by this class
        // and by the plugin all along, and nobody ever sent them — so every site,
        // on every profile, got the plugin's 100 MiB default with no compression
        // choice at all. The explicit constructor values stay as a test seam.
        $options = [
            'include_defaults' => true,
            'compression' => $this->compression ?: $this->profile->compression,
            'threshold' => $this->fileChunkThreshold ?? $this->profile->fileChunkBytes,
        ];

        // Incremental: hand the plugin the materialised base file-state so it plans ONLY changed+new
        // files and reports tombstones. A full (or a run with no baseline) sends no base_manifest and
        // the plugin plans the whole tree exactly as before.
        $baseManifest = $this->resolveBaseManifest();
        if ($baseManifest !== null) {
            $options['base_manifest'] = $baseManifest;
        }

        $inv = $this->client->filesInventory($this->pluginSessionId(), $this->rules(), $options);

        $this->session->exclusion_policy_hash = (string) ($inv['exclusion_policy_hash'] ?? '');
        $this->session->scope_hash = $this->scopeHash();
        $this->session->save();

        $this->mergeCheckpoint([
            'files_inventory' => [
                'total_files' => (int) ($inv['total_files'] ?? 0),
                'total_bytes' => (int) ($inv['total_bytes'] ?? 0),
                'excluded_files' => (int) ($inv['excluded_files'] ?? 0),
                'excluded_bytes' => (int) ($inv['excluded_bytes'] ?? 0),
                'chunk_count' => (int) ($inv['chunk_count'] ?? 0),
                'compression' => (string) ($inv['compression'] ?? $this->compression),
                'mode' => (string) ($inv['mode'] ?? ($baseManifest !== null ? 'incremental' : 'full')),
            ],
        ]);

        if ($baseManifest !== null) {
            $this->mergeCheckpoint([
                'incremental' => [
                    'base_file_count' => count($baseManifest),
                    'tombstones' => array_values(array_map('strval', (array) ($inv['tombstones'] ?? []))),
                    'diff' => $inv['diff'] ?? null,
                ],
            ]);
        }
    }

    /**
     * Materialised base file-state for an incremental (or null for a full/no-baseline run).
     *
     * @return list<array{p:string,sha256:string,s:int,m?:int}>|null
     */
    private function resolveBaseManifest(): ?array
    {
        if ($this->session->type !== 'incremental' || $this->baseManifestProvider === null) {
            return null;
        }

        $base = ($this->baseManifestProvider)($this->session);

        return is_array($base) ? $base : null;
    }

    private function databaseExport(): void
    {
        if (! $this->includeDatabase()) {
            return;
        }
        if (($this->checkpoint()['db_done'] ?? false) === true) {
            return; // every DB segment already confirmed on a prior run
        }

        // batch_rows is the real lever on the host's peak memory during a dump:
        // it is how many rows a single SELECT pulls into PHP at once. time_budget
        // bounds how long one request holds the snapshot open. Both were already
        // understood by the dumper; the profile is what finally decides them.
        $params = [
            'exclude_tables' => $this->excludeTables(),
            'segment_bytes' => $this->dbSegmentBytes ?? $this->profile->dbSegmentBytes,
            'time_budget' => $this->profile->dbTimeBudget,
            'batch_rows' => $this->profile->dbBatchRows,
        ];

        $dump = $this->client->dbDump($this->pluginSessionId(), $params);

        $done = (bool) ($dump['done'] ?? false);
        $consistent = (bool) ($dump['consistent'] ?? false);
        if (! $done || ! $consistent) {
            // The dump did not complete a single-snapshot consistent run — refuse to
            // pass it off as a good DB backup.
            throw new CorruptBackupException(
                'DB dump did not complete consistently (done='
                .var_export($done, true).', consistent='.var_export($consistent, true).').'
            );
        }

        $segments = array_values($dump['segments'] ?? []);
        $this->mergeCheckpoint([
            'db' => [
                'database' => $dump['database'] ?? null,
                'server_version' => $dump['server_version'] ?? null,
                'engine' => $dump['engine'] ?? null,
                'table_count' => (int) ($dump['table_count'] ?? 0),
                'total_rows' => (int) ($dump['total_rows'] ?? 0),
                'tables' => $dump['tables'] ?? [],
                'segment_count' => count($segments),
            ],
        ]);

        foreach ($segments as $segment) {
            $index = $this->segmentIndex((string) ($segment['file'] ?? ''));
            $objectId = 'database:'.$index;
            if ($this->isConfirmed($objectId)) {
                continue;
            }

            $chunk = $this->client->dbChunkDownload($this->pluginSessionId(), $index, deleteAfter: true);
            try {
                $this->assertPulledOk($chunk, 'database segment '.$index);
                $this->assertValidGzip($chunk->path, 'database segment '.$index);

                $key = $this->layout->database('chunk_'.$index.'.sql.gz');
                $result = $this->uploadObject($chunk->path, $key);

                $this->recordObject($objectId, [
                    'kind' => 'database',
                    'chunk_index' => $index,
                    'key' => $key,
                    'size' => $result->size,
                    'sha256' => $result->sha256,
                    'reported_sha256' => $chunk->reportedSha256,
                ]);
            } finally {
                $chunk->delete();
            }

            $this->fault('database', $index);
        }

        $this->mergeCheckpoint(['db_done' => true]);
    }

    private function fileDiff(): void
    {
        // For a full there is no baseline. For an incremental the plugin already did the diff at
        // inventory time (only changed+new were planned; tombstones reported); this phase records the
        // outcome for the manifest chain fields. DB is a full dump regardless (see databaseExport()).
        $incremental = $this->checkpoint()['incremental'] ?? null;

        $this->mergeCheckpoint(['file_diff' => [
            'mode' => $this->session->type,
            'baseline' => $incremental !== null ? ($this->session->full_base_id ?? null) : null,
            'tombstone_count' => is_array($incremental) ? count((array) ($incremental['tombstones'] ?? [])) : 0,
            'diff' => is_array($incremental) ? ($incremental['diff'] ?? null) : null,
        ]]);
    }

    private function chunking(): void
    {
        // The chunk plan is materialised on-demand per chunk in uploading() (the
        // plugin persisted the plan server-side at inventory time). Nothing to do
        // here beyond keeping the FSM honest.
    }

    private function uploadInitializing(): void
    {
        // Sweep any multipart uploads abandoned by a previously crashed attempt of
        // THIS backup so we never leak billable in-progress uploads. Bounded to this
        // backup's own prefix.
        try {
            $this->uploader()->reapAbandonedUploads($this->layout->listPrefix(), new \DateTimeImmutable('-6 hours'));
        } catch (Throwable $e) {
            $this->logger->warning('reap on init failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    private function uploading(): void
    {
        if (! $this->includeFiles()) {
            return;
        }

        $chunkCount = (int) ($this->checkpoint()['files_inventory']['chunk_count'] ?? 0);

        for ($index = 0; $index < $chunkCount; $index++) {
            $objectId = 'files:'.$index;
            if ($this->isConfirmed($objectId)) {
                continue; // already uploaded on a prior run — never re-pull/re-upload
            }

            // Time the round-trip. This is the only place the manager finds out
            // how the site is coping: everything else it can see is its own
            // work. A host that has started to struggle answers slower here
            // before it answers with an error anywhere.
            $startedAt = microtime(true);
            try {
                $exec = $this->client->filesChunkExec($this->pluginSessionId(), $index);
            } catch (Throwable $e) {
                $this->impact->observeFailure($e->getMessage());

                throw $e;
            }
            $this->impact->observe(microtime(true) - $startedAt);

            // Empty-chunk contract: a chunk that materialised 0 files is NOT
            // downloaded, NOT uploaded and NOT put in the manifest.
            $empty = (bool) ($exec['empty'] ?? false);
            $chunkSize = (int) ($exec['chunk_size'] ?? 0);
            if ($empty || $chunkSize === 0) {
                $this->noteSkippedChunk($index);

                continue;
            }

            $chunk = $this->client->filesChunkDownload($this->pluginSessionId(), $index, deleteAfter: true);
            try {
                $this->assertPulledOk($chunk, 'file chunk '.$index);
                $this->assertValidZip($chunk->path, 'file chunk '.$index);

                $key = $this->layout->files('chunk_'.$index.'.zip');
                $result = $this->uploadObject($chunk->path, $key);

                $this->recordObject($objectId, [
                    'kind' => 'files',
                    'chunk_index' => $index,
                    'key' => $key,
                    'size' => $result->size,
                    'sha256' => $result->sha256,
                    'reported_sha256' => $chunk->reportedSha256,
                    'file_count' => (int) ($exec['file_count'] ?? 0),
                ]);
                $this->recordFileEntries((array) ($exec['files'] ?? []));
            } finally {
                $chunk->delete();
            }

            $this->fault('files', $index);

            // Hand the host back its CPU and disk before asking for the next
            // chunk. Without this the loop pulls as fast as the site can answer,
            // which on shared hosting is the difference between a backup nobody
            // notices and one that shows up in the visitors' page load times.
            $this->profile->pause();
        }
    }

    private function verify(): void
    {
        foreach ($this->confirmedObjects() as $objectId => $object) {
            $key = (string) $object['key'];

            try {
                $head = $this->readS3()->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
            } catch (Throwable $e) {
                throw new CorruptBackupException("Object missing in storage: {$key} ({$e->getMessage()})");
            }

            $remoteSize = (int) $head['ContentLength'];
            if ($remoteSize !== (int) $object['size']) {
                throw new CorruptBackupException(
                    "Size mismatch for {$key}: expected {$object['size']}, storage has {$remoteSize}"
                );
            }

            // sha256 re-verification straight from storage (download + hash). Objects
            // are pulled-and-freed and small; TODO(P3): use S3 ChecksumSHA256 / ranged
            // verification for very large objects instead of a full re-download.
            $tmp = (string) WorkDir::temp('v2verify_');
            try {
                $this->readS3()->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $tmp]);
                $remoteSha = (string) hash_file('sha256', $tmp);
            } finally {
                @unlink($tmp);
            }

            if (! hash_equals((string) $object['sha256'], $remoteSha)) {
                throw new CorruptBackupException(
                    "Checksum mismatch for {$key}: expected {$object['sha256']}, storage has {$remoteSha}"
                );
            }
        }
    }

    private function finalize(): void
    {
        $manifest = $this->buildManifest();
        $manifestJson = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $checksumsJson = (string) json_encode($this->buildChecksums($manifest), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $metadataJson = (string) json_encode($this->buildMetadata($manifest), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $this->putJson($this->layout->manifest(), $manifestJson);
        $this->putJson($this->layout->checksums(), $checksumsJson);
        $this->putJson($this->layout->metadata(), $metadataJson);

        // verify-before-complete: the manifest + checksums + metadata must be present
        // in storage BEFORE the completion marker is written.
        foreach ([$this->layout->manifest(), $this->layout->checksums(), $this->layout->metadata()] as $key) {
            $this->assertObjectPresent($key);
        }

        // _COMPLETE is ALWAYS the last object written. Its presence is the single
        // source of truth that a backup is whole.
        $completeBody = (string) json_encode([
            'completed_at' => now()->toIso8601String(),
            'format_version' => (string) config('backup_v2.format_version', 'simplead-backup/1'),
            'manifest_sha256' => hash('sha256', $manifestJson),
            'object_count' => count($this->confirmedObjects()),
        ], JSON_UNESCAPED_SLASHES);
        $this->putJson($this->layout->completeMarker(), $completeBody);
        $this->assertObjectPresent($this->layout->completeMarker());

        // Retention gives a destination's used_bytes back by exactly the size
        // recorded on the `backups` row, so anything left out of that figure is
        // never reclaimed — a small, systematic, one-directional drift in the
        // number quotas are enforced on. The four sidecars are part of what this
        // backup occupies, so they are part of what it reports.
        $this->mergeCheckpoint(['sidecar_bytes' => strlen($manifestJson)
            + strlen($checksumsJson)
            + strlen($metadataJson)
            + strlen($completeBody)]);

        $this->session->transitionTo(S::Completed, 'backup complete');

        // Verify-at-creation (P6): the moment a backup reaches `completed`, produce
        // its create-verification record and — on pass — stamp verified_at, which
        // is written through to the `backups` row. Deliberately non-fatal: a verifier
        // failure records a failed/corrupt verification but never un-completes an
        // already-whole backup (verify-before-complete has already guaranteed it).
        try {
            (new \App\Backup\V2\Verification\BackupVerifier)
                ->verifyOnComplete($this->session, $this->s3, $this->bucket, $this->layout);
        } catch (Throwable $e) {
            $this->logger->warning('create-verification threw (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    // ── manifest assembly ────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(): array
    {
        $cp = $this->checkpoint();
        $objects = [];
        foreach ($this->confirmedObjects() as $object) {
            $objects[] = [
                'kind' => $object['kind'],
                'key' => $object['key'],
                'chunk_index' => (int) $object['chunk_index'],
                'size' => (int) $object['size'],
                'sha256' => $object['sha256'],
            ];
        }
        usort($objects, static fn (array $a, array $b): int => [$a['kind'], $a['chunk_index']] <=> [$b['kind'], $b['chunk_index']]);

        $fileEntries = array_values($cp['file_entries'] ?? []);
        usort($fileEntries, static fn (array $a, array $b): int => (string) ($a['p'] ?? '') <=> (string) ($b['p'] ?? ''));

        return [
            'format_version' => (string) config('backup_v2.format_version', 'simplead-backup/1'),
            'engine' => 'simplead-backup',
            'engine_version' => $cp['capabilities']['plugin_version'] ?? null,
            'backup_type' => $this->session->type,
            'session_id' => $this->session->id,
            'plugin_session_id' => $this->pluginSessionId(),
            'scope' => [
                'database' => $this->includeDatabase(),
                'files' => $this->includeFiles(),
            ],
            'scope_hash' => $this->scopeHash(),
            'exclusion_policy_hash' => $this->session->exclusion_policy_hash,
            'full_base_id' => $this->session->full_base_id,
            'chain_position' => $this->session->chain_position,
            // For an incremental this points at the base full whose materialised state was diffed
            // against (the chain root); null for a full. resolveChain() rebuilds the ordered chain
            // from full_base_id + chain_position, so this is a human-facing back-reference.
            'base_manifest_ref' => $this->session->type === 'incremental' ? $this->session->full_base_id : null,
            'objects' => $objects,
            'files' => [
                'included' => $fileEntries,
                'included_count' => count($fileEntries),
                'excluded_count' => (int) ($cp['files_inventory']['excluded_files'] ?? 0),
                'excluded_bytes' => (int) ($cp['files_inventory']['excluded_bytes'] ?? 0),
                'total_bytes' => (int) ($cp['files_inventory']['total_bytes'] ?? 0),
                'skipped_empty_chunks' => array_values($cp['skipped_empty_chunks'] ?? []),
                // Paths present in the base but deleted in this incremental — restore removes them.
                'tombstones' => array_values(array_map('strval', (array) ($cp['incremental']['tombstones'] ?? []))),
            ],
            'database' => [
                'name' => $cp['db']['database'] ?? null,
                'tables' => $cp['db']['tables'] ?? [],
                'table_count' => (int) ($cp['db']['table_count'] ?? 0),
                'total_rows' => (int) ($cp['db']['total_rows'] ?? 0),
                'excluded_tables' => $this->excludeTables(),
                'segment_count' => (int) ($cp['db']['segment_count'] ?? 0),
            ],
            'environment' => [
                'wp_version' => $cp['capabilities']['wp_version'] ?? null,
                'php_version' => $cp['capabilities']['php_version'] ?? null,
                'db_server_version' => $cp['db']['server_version'] ?? ($cp['capabilities']['db_server_version'] ?? null),
                'db_engine' => $cp['capabilities']['db_engine'] ?? null,
                'consistent_snapshot' => (bool) ($cp['capabilities']['consistent_snapshot'] ?? false),
            ],
            'started_at' => optional($this->session->started_at)->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function buildChecksums(array $manifest): array
    {
        $checksums = [];
        foreach ($manifest['objects'] as $object) {
            $checksums[(string) $object['key']] = [
                'sha256' => $object['sha256'],
                'size' => $object['size'],
            ];
        }

        return [
            'algorithm' => 'sha256',
            'objects' => $checksums,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function buildMetadata(array $manifest): array
    {
        $totalBytes = 0;
        foreach ($manifest['objects'] as $object) {
            $totalBytes += (int) $object['size'];
        }

        return [
            'backup_type' => $this->session->type,
            'format_version' => $manifest['format_version'],
            'engine_version' => $manifest['engine_version'],
            'object_count' => count($manifest['objects']),
            'stored_bytes' => $totalBytes,
            'resource_profile' => $this->session->resource_profile,
            'environment' => $manifest['environment'],
            'started_at' => $manifest['started_at'],
            'completed_at' => $manifest['completed_at'],
        ];
    }

    // ── S3 helpers ───────────────────────────────────────────────────────

    /**
     * The client for reading storage back. Falls back to the write client when the
     * caller did not supply one, which is what the lab and the unit tests do.
     */
    private function readS3(): S3Client
    {
        return $this->readS3 ?? $this->s3;
    }

    private function uploader(): HardenedMultipartUploader
    {
        return HardenedMultipartUploader::fromConfig(
            client: $this->s3,
            bucket: $this->bucket,
            progress: $this->progress,
            logger: $this->logger,
        );
    }

    /**
     * Put an object in storage, encrypted if this client has a key.
     *
     * The two hashes are not redundant and getting them the wrong way round
     * breaks the engine quietly. `sha256` is of what storage actually holds — it
     * is what upload_verifying re-downloads and re-hashes, so it must be the
     * CIPHERTEXT or every backup would fail its own verification. The plaintext
     * hash is what a restore checks after decrypting, and is the only thing that
     * says the bytes that come back are the bytes that went in.
     */
    private function uploadObject(string $localPath, string $key): \App\Backup\V2\Storage\MultipartUploadResult
    {
        $cipher = $this->cipher();
        if ($cipher === null) {
            return $this->uploader()->upload($localPath, $key);
        }

        $plaintextSha = (string) hash_file('sha256', $localPath);
        $encrypted = (string) WorkDir::temp('v2enc_');

        try {
            $cipher->encryptFile($localPath, $encrypted);
            $result = $this->uploader()->upload($encrypted, $key);
        } finally {
            @unlink($encrypted);
        }

        $this->plaintextHashes[$key] = $plaintextSha;

        return $result;
    }

    /**
     * The client's cipher, resolved once per run. Null when encryption is off.
     */
    private function cipher(): ?\App\Backup\V2\Crypto\ObjectCipher
    {
        if ($this->cipherResolved) {
            return $this->cipherInstance;
        }

        $this->cipherResolved = true;
        /** @var \App\Models\Site|null $site */
        $site = $this->session->site;
        $this->cipherInstance = ! $site instanceof \App\Models\Site
            ? null
            : (new \App\Backup\V2\Crypto\BackupKeyring)->forSite($site);

        return $this->cipherInstance;
    }

    private function putJson(string $key, string $body): void
    {
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $body,
            'ContentType' => 'application/json',
        ]);
    }

    private function assertObjectPresent(string $key): void
    {
        try {
            $this->readS3()->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (Throwable $e) {
            throw new CorruptBackupException("Expected object not present after write: {$key} ({$e->getMessage()})");
        }
    }

    // ── validation ───────────────────────────────────────────────────────

    private function assertPulledOk(DownloadedChunk $chunk, string $what): void
    {
        if (! $chunk->integrityOk()) {
            throw new RuntimeException(
                "Pulled {$what} failed integrity (status={$chunk->httpStatus}, size={$chunk->size}, "
                ."reported={$chunk->reportedSha256}, local={$chunk->sha256})."
            );
        }
    }

    private function assertValidZip(string $path, string $what): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            $zip->close();
            throw new RuntimeException("Pulled {$what} is not a valid zip archive (code {$opened}).");
        }
        $numFiles = $zip->numFiles;
        $zip->close();
        if ($numFiles < 1) {
            throw new RuntimeException("Pulled {$what} zip contains no entries.");
        }
    }

    private function assertValidGzip(string $path, string $what): void
    {
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            throw new RuntimeException("Pulled {$what} is not a readable gzip stream.");
        }
        $read = gzread($gz, 512);
        gzclose($gz);
        if ($read === false || $read === '') {
            throw new RuntimeException("Pulled {$what} gzip stream is empty/corrupt.");
        }
    }

    // ── session bookkeeping ──────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $object
     */
    private function recordObject(string $objectId, array $object): void
    {
        // Carry the plaintext hash alongside the stored one. Verification reads
        // `sha256` against what storage holds; restore reads this against what
        // comes back out of the cipher.
        $key = (string) ($object['key'] ?? '');
        if (isset($this->plaintextHashes[$key])) {
            $object['plaintext_sha256'] = $this->plaintextHashes[$key];
            $object['encrypted'] = true;
        }

        $this->session->refresh();
        $objects = $this->session->confirmed_objects ?? [];
        $objects[$objectId] = $object;
        $this->session->confirmed_objects = $objects;
        $this->session->cursor = ['last_object' => $objectId];
        $this->session->save();
        $this->session->heartbeat();
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function recordFileEntries(array $entries): void
    {
        $cp = $this->checkpoint();
        $existing = $cp['file_entries'] ?? [];
        foreach ($entries as $e) {
            $existing[] = [
                'p' => $e['p'] ?? null,
                's' => (int) ($e['s'] ?? 0),
                'm' => (int) ($e['m'] ?? 0),
                'sha256' => $e['sha256'] ?? null,
                'chunk_index' => (int) ($e['chunk_index'] ?? 0),
            ];
        }
        $this->mergeCheckpoint(['file_entries' => $existing]);
    }

    private function noteSkippedChunk(int $index): void
    {
        $cp = $this->checkpoint();
        $skipped = $cp['skipped_empty_chunks'] ?? [];
        if (! in_array($index, $skipped, true)) {
            $skipped[] = $index;
        }
        $this->mergeCheckpoint(['skipped_empty_chunks' => array_values($skipped)]);
    }

    private function isConfirmed(string $objectId): bool
    {
        return array_key_exists($objectId, $this->confirmedObjects());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function confirmedObjects(): array
    {
        $this->session->refresh();

        return $this->session->confirmed_objects ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkpoint(): array
    {
        $this->session->refresh();

        return $this->session->checkpoint ?? [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function mergeCheckpoint(array $patch): void
    {
        $this->session->refresh();
        $this->session->checkpoint = array_merge($this->session->checkpoint ?? [], $patch);
        $this->session->save();
    }

    private function fault(string $kind, int $index): void
    {
        if ($this->faultAfterObject !== null) {
            ($this->faultAfterObject)($kind, $index, count($this->confirmedObjects()));
        }
    }

    // ── scope helpers ────────────────────────────────────────────────────

    private function includeDatabase(): bool
    {
        $scope = $this->session->scope ?? [];
        if (array_key_exists('database', $scope)) {
            return (bool) $scope['database'];
        }

        return in_array($this->session->type, ['full', 'database'], true);
    }

    private function includeFiles(): bool
    {
        $scope = $this->session->scope ?? [];
        if (array_key_exists('files', $scope)) {
            return (bool) $scope['files'];
        }

        return in_array($this->session->type, ['full', 'files'], true);
    }

    /**
     * @return list<mixed>
     */
    private function rules(): array
    {
        return array_values((array) ($this->session->scope['rules'] ?? []));
    }

    /**
     * @return list<string>
     */
    private function excludeTables(): array
    {
        return array_values(array_map('strval', (array) ($this->session->scope['exclude_tables'] ?? [])));
    }

    private function scopeHash(): string
    {
        return hash('sha256', (string) json_encode([
            'type' => $this->session->type,
            'database' => $this->includeDatabase(),
            'files' => $this->includeFiles(),
            'rules' => $this->rules(),
            'exclude_tables' => $this->excludeTables(),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function pluginSessionId(): string
    {
        return 'sambk_'.$this->session->id;
    }

    private function segmentIndex(string $file): int
    {
        if (preg_match('/chunk_(\d+)\.sql\.gz$/', $file, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }
}
