<?php

declare(strict_types=1);

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    // Allow direct CLI use by the lab harness (like the dumper); block web access without WP.
    exit;
}

/**
 * SAM_Backup_Restore_Engine
 * =========================
 * Staged, atomic restore for the simplead-backup engine — the read/swap side of the
 * backup. Ported and hardened from the connector's staged restore (samstg_* DB swap +
 * journaled file swap) into an independent, testable class. NEVER imports from the
 * connector and NEVER uses shell_exec/exec/system/proc_open — pure PHP + mysqli + gzopen.
 *
 * Model (manager PULLS objects from S3 and PUSHES chunks here — see the endpoint):
 *
 *   prepare(mode, scope, mirror_roots, keep_paths, db_tables, tombstones)
 *     → creates a private restore session (token) with staging areas.
 *   stage_files_chunk(seq, zip, sha256)   → a materialised file chunk zip into staging.
 *   stage_db_chunk(seq, sql.gz, sha256)   → a DB segment into staging.
 *     (Both are idempotent + verified by sha256; nothing touches live yet.)
 *   apply()
 *     → the CRITICAL window (maintenance mode ON only here):
 *          DB  : import staged segments into sambk_stg_* (zero error tolerance),
 *                then a single atomic multi-table RENAME swap, keeping the pre-restore
 *                tables as sambk_old_* until commit/rollback.
 *          FILE: extract staged chunks into ABSPATH/sam-restore-staging-{token}/ (same
 *                filesystem → rename() is atomic), prune to the exact final state
 *                (keep_paths) + drop tombstones, then a JOURNALED per-path swap into the
 *                live tree, moving the displaced live files into a trash dir. MIRROR mode
 *                additionally moves aside live files under mirror_roots that are absent
 *                from the backup (EXACT reproduction); SAFE_MERGE never deletes.
 *       Rollback data (trash + journal + old tables) is KEPT until commit/rollback so a
 *       failed post-restore validation can always return the site to its pre-apply state.
 *   commit()   → drop sambk_old_* + delete trash/staging (restore confirmed good).
 *   rollback() → reverse the journal + rename sambk_old_* back (return to pre-apply).
 *
 * A failure at any point before a swap leaves the live site untouched; a failure DURING a
 * swap is rolled back from the journal/trash. The site is never left in a broken state.
 */
final class SAM_Backup_Restore_Engine {

    const STAGE_PREFIX  = 'sam-restore-staging-';
    const TRASH_PREFIX  = 'sam-restore-trash-';
    const DB_STG_PREFIX = 'sambk_stg_';
    const DB_OLD_PREFIX = 'sambk_old_';

    const MODE_SAFE_MERGE = 'safe_merge';
    const MODE_MIRROR     = 'mirror';

    /**
     * How long an `applying`/`queued` claim stays believable, in seconds.
     *
     * Kept BELOW RestoreRunner::QUEUED_GRACE_SECONDS (120) in the manager: when a
     * dispatch is claimed but never runs, the manager gives up waiting and retries
     * synchronously, and that retry has to be let through here. The two constants
     * are a pair — moving one without the other re-opens the wedge.
     */
    const QUEUED_TTL = 90;

    /** Files that are NEVER swapped (live DB credentials + maintenance flag must survive). */
    const PROTECTED_FILES = array('wp-config.php', '.maintenance');

    private string $token;
    private string $abspath;   // live site root (no trailing slash)
    private string $work_dir;  // private per-session area (plan/status/chunks/apply-state)

    /** @var array<string,mixed>|null injectable mysqli config (null → WP DB_* constants) */
    private $db_cfg;

    /** @var callable|null test fault injector: function(string $phase, int $step): void */
    private $fault = null;

    /** @var resource|null held for the duration of an apply/rollback (see lock_apply()) */
    private $lock_handle = null;

    /**
     * @param array<string,mixed>|null $db_cfg
     */
    public function __construct(string $token, string $abspath, string $work_dir, $db_cfg = null) {
        $this->token    = self::safe_token($token);
        $this->abspath  = rtrim($abspath, '/');
        $this->work_dir = rtrim($work_dir, '/');
        $this->db_cfg   = $db_cfg;
    }

    /**
     * Log, when there is a logger.
     *
     * This class is deliberately standalone — the lab harness loads it on its own,
     * without the plugin bootstrap — so it asks for the logger rather than
     * depending on it.
     *
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $message, array $context = array()): void {
        if (class_exists('SAM_Backup_Logger')) {
            SAM_Backup_Logger::log($level, $message, $context + array('token' => $this->token));
        }
    }

    /** Test seam: inject a crash at a named phase/step to prove journaled rollback. */
    public function set_fault(?callable $fault): void {
        $this->fault = $fault;
    }

    public function token(): string {
        return $this->token;
    }

    // ── prepare ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $opts mode|scope|mirror_roots|keep_paths|db_tables|tombstones
     * @return array<string,mixed>
     */
    public function prepare(array $opts): array {
        $mode = (string) ($opts['mode'] ?? self::MODE_SAFE_MERGE);
        if ($mode !== self::MODE_SAFE_MERGE && $mode !== self::MODE_MIRROR) {
            throw new RuntimeException("Unknown restore mode: {$mode}");
        }

        $this->mkdir($this->work_dir);
        $this->mkdir($this->chunks_dir('files'));
        $this->mkdir($this->chunks_dir('database'));

        $plan = array(
            'token'        => $this->token,
            'mode'         => $mode,
            'scope'        => (array) ($opts['scope'] ?? array()),
            'mirror_roots' => array_values(array_map('strval', (array) ($opts['mirror_roots'] ?? array()))),
            // Exact final-state relative paths (chain latest-wins). Staging is pruned to this
            // set so a chunk that also carried a since-overwritten/deleted file cannot resurrect it.
            'keep_paths'   => array_values(array_map('strval', (array) ($opts['keep_paths'] ?? array()))),
            'db_tables'    => array_values(array_map('strval', (array) ($opts['db_tables'] ?? array()))),
            'tombstones'   => array_values(array_map('strval', (array) ($opts['tombstones'] ?? array()))),
            'created_at'   => gmdate('c'),
        );
        $this->write_json_strict($this->work_dir . '/plan.json', $plan);

        // The disk as it stands BEFORE anything is pushed here. The apply's own watermark can only
        // start once the chunks have already landed, so measured from there the temp filesystem
        // never appears to be under pressure — its peak happened during staging, in earlier
        // requests, each its own PHP process with no memory of the last. A stamp on disk is the
        // only thing those requests share.
        $this->write_json($this->work_dir . '/disk-baseline.json', $this->disk_snapshot());

        $this->set_status('prepared', array('mode' => $mode));

        return array('ok' => true, 'token' => $this->token, 'mode' => $mode);
    }

    // ── staging (nothing touches live) ───────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    public function stage_files_chunk(int $seq, string $src_zip, string $sha256): array {
        return $this->stage_chunk('files', $seq, 'zip', $src_zip, $sha256);
    }

    /**
     * @return array<string,mixed>
     */
    public function stage_db_chunk(int $seq, string $src_gz, string $sha256): array {
        return $this->stage_chunk('database', $seq, 'sql.gz', $src_gz, $sha256);
    }

    /**
     * @return array<string,mixed>
     */
    private function stage_chunk(string $kind, int $seq, string $ext, string $src, string $sha256): array {
        if ($seq < 0) {
            throw new RuntimeException('chunk seq must be >= 0');
        }
        // Staging is over once an apply has started, and saying so is the point.
        //
        // apply_files() deletes each chunk as it consumes it, so the idempotent early return below
        // stops recognising a redelivered chunk and the copy path runs instead — ending in a status
        // write that pushed `applying`/`applied`/`committed` back to `staging`. That erased the
        // re-entry guard's only input and re-armed has_staged(), which is how one restore could be
        // applied twice. A late chunk is refused, and the endpoint turns that into a 422.
        $state = (string) ($this->status()['state'] ?? 'prepared');
        if ($state !== 'prepared' && $state !== 'staging') {
            throw new RuntimeException(
                "restore session is in state '{$state}'; chunks can no longer be staged for this token"
            );
        }

        $dest = $this->chunks_dir($kind) . '/chunk_' . $seq . '.' . $ext;

        // Idempotent: a chunk already staged with the SAME sha is a no-op (resumable retry).
        if (is_file($dest) && hash_file('sha256', $dest) === $sha256) {
            return array('ok' => true, 'kind' => $kind, 'seq' => $seq, 'size' => (int) filesize($dest), 'sha256' => $sha256, 'reused' => true);
        }

        if (!is_file($src)) {
            throw new RuntimeException("staged source not found: {$src}");
        }
        // Copy into staging then verify the landed bytes (defence against a torn transfer).
        if (!@copy($src, $dest)) {
            throw new RuntimeException("failed to stage {$kind} chunk {$seq}");
        }
        $actual = hash_file('sha256', $dest);
        if (!hash_equals($sha256, (string) $actual)) {
            @unlink($dest);
            throw new RuntimeException("staged {$kind} chunk {$seq} sha256 mismatch (expected {$sha256}, got {$actual})");
        }

        $this->set_status('staging', array('last_kind' => $kind, 'last_seq' => $seq));

        return array('ok' => true, 'kind' => $kind, 'seq' => $seq, 'size' => (int) filesize($dest), 'sha256' => $actual, 'reused' => false);
    }

    // ── apply (the only mutating, maintenance-guarded window) ────────────────

    /**
     * @return array<string,mixed>
     */
    public function apply(bool $detached = false): array {
        // The lock first, because everything below reads a file that another process could be
        // writing. The status check is a diagnostic — an unlocked read of a JSON document — and on
        // a host where the loopback fires AND wp-cron fires, both workers read `queued`, both are
        // let through as detached, and two applies run over one token. flock is what actually
        // stops that; it is also released by the kernel if the process dies, which no flag written
        // into a file can promise.
        $this->lock_apply();

        try {
            return $this->apply_locked($detached);
        } finally {
            $this->unlock_apply();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function apply_locked(bool $detached): array {
        // Re-entry is REFUSED, not retried, and this is the whole point.
        //
        // apply_files() and drop_orphaned_restore_tables() both clear the previous run's trash and
        // sambk_old_* tables at the start — the data rollback depends on. So a second apply over a
        // first one that is still running, or that already finished, destroys the ability to undo
        // either. That is precisely what happened the first time a manager timed out waiting: it
        // gave up, called rollback, and the rollback had nothing left to work with while the site
        // had in fact been restored correctly.
        $status  = $this->status();
        $current = (string) ($status['state'] ?? '');
        $phase   = (string) ($status['phase'] ?? '');

        // `applying` + phase `queued` is the claim the async dispatcher stakes BEFORE handing the
        // work to a detached request — it exists so a poll landing in that gap does not read the
        // previous state and conclude nothing happened. The detached request is the one arriving
        // here, so it must be let through; refusing it deadlocks the restore in `queued` forever,
        // which is exactly what the first async attempt did.
        //
        // But ONLY the detached request. The exemption used to be granted to whoever asked, which
        // meant a manager retrying restore/apply during that same gap — a redelivered job, most
        // obviously — walked straight past the guard and started a second apply alongside the
        // first. Two applies over one token destroy each other's rollback data. `$detached` is set
        // by the two internal callers (the loopback route and the cron hook) and by nothing the
        // manager can reach.
        //
        // The third exemption is a claim that was staked and never executed. The dispatcher marks
        // `queued` before handing off, and a hand-off that fails invisibly — a loopback the host
        // rejects, a cron event nothing ever runs — used to leave the token in `queued` with no
        // expiry: the manager's synchronous retry was refused here, rollback was refused too, and
        // the session stayed wedged until the sweep deleted it an hour later. A claim older than
        // QUEUED_TTL is a dead claim. The flock below is what actually keeps two applies apart, so
        // a zombie worker arriving late still cannot overlap this one.
        if ($current === 'applying'
            && ! ($detached && $phase === 'queued')
            && ! $this->queued_claim_expired($status)) {
            throw new RuntimeException(
                'an apply is already running for this token; poll restore/status instead of starting another'
            );
        }

        if ($current === 'applied' && $this->load_apply_state() !== null) {
            $state = (array) $this->load_apply_state();

            return array(
                'ok'      => true,
                'applied' => true,
                'already' => true,
                'db'      => $state['db'] ?? null,
                'files'   => $state['files'] ?? null,
            );
        }

        // A retry after a failure that got as far as swapping the database must not run the swap
        // again. The second swap renames the ALREADY-RESTORED tables into sambk_old_*, so the
        // pre-restore data — the thing rollback exists to return — is gone, and the rollback that
        // follows quietly returns the site to the restored state it was trying to escape. The way
        // out of a half-applied restore is rollback or commit, never another apply.
        $prior = $this->load_apply_state();
        if ($prior !== null && isset($prior['db']) && is_array($prior['db'])) {
            throw new RuntimeException(
                'a previous apply already swapped the database for this token; roll back or commit before retrying'
            );
        }

        $plan = $this->load_plan();
        $apply_state = array('files' => null, 'db' => null, 'mode' => $plan['mode']);

        $maintenance = false;
        try {
            $has_db    = $this->has_staged('database');
            $has_files = $this->has_staged('files');

            if (!$has_db && !$has_files) {
                throw new RuntimeException('nothing staged to apply');
            }

            $this->set_status('applying', array('phase' => 'starting', 'started_at' => gmdate('c')));
            $this->disk_watch_start();
            $this->maintenance_on();
            $maintenance = true;

            // DB first: import + atomic RENAME swap (kept as sambk_old_* for rollback).
            if ($has_db) {
                // The phase is written as it changes so a polling manager can tell a long apply
                // apart from a dead one. Without it `applying` is a constant string for however
                // many minutes the swap takes, which reads exactly like a process that has stopped.
                $this->set_status('applying', array('phase' => 'database', 'started_at' => gmdate('c')));
                $apply_state['db'] = $this->apply_database($plan);
                $this->write_json_strict($this->work_dir . '/apply-state.json', $apply_state);
            }

            // Files: extract → prune → journaled per-path swap (trash kept for rollback).
            if ($has_files) {
                $this->set_status('applying', array('phase' => 'files', 'started_at' => gmdate('c')));
                $apply_state['files'] = $this->apply_files($plan);
                $this->write_json_strict($this->work_dir . '/apply-state.json', $apply_state);
            }

            $this->disk_sample();
            $apply_state['disk'] = $this->disk_report();
            $this->write_json_strict($this->work_dir . '/apply-state.json', $apply_state);

            $this->maintenance_off();
            $maintenance = false;

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            if (function_exists('wp_cache_flush')) {
                @wp_cache_flush();
            }

            $this->set_status('applied', array(
                'db'    => $apply_state['db'] !== null,
                'files' => $apply_state['files'] !== null,
                // Carried in the status too, not only in the apply state: the manager reads the
                // status after the fact, and this is the one measurement it cannot take itself.
                'disk'  => $apply_state['disk'],
            ));

            return array(
                'ok'      => true,
                'applied' => true,
                'db'      => $apply_state['db'],
                'files'   => $apply_state['files'],
                'disk'    => $apply_state['disk'],
            );
        } catch (\Throwable $e) {
            // Each phase rolls ITSELF back — which leaves the case where the database swap
            // succeeded and the file phase then failed. The files came back, the database stayed
            // restored, and the status said `failed`: a manager reading that would reasonably
            // believe the site was untouched while half of it had been replaced. Undo the swap
            // here, and when even that cannot be done, say which half is which.
            $detail = array('error' => $e->getMessage());

            if (isset($apply_state['db']) && is_array($apply_state['db'])) {
                try {
                    $this->rollback_database($apply_state['db']);
                    $detail['db_rolled_back'] = true;
                } catch (\Throwable $inner) {
                    $detail['db_left_restored'] = true;
                    $detail['db_rollback_error'] = $inner->getMessage();
                    $this->log('error', 'could not undo the database swap after a failed apply', array(
                        'token' => $this->token,
                        'error' => $inner->getMessage(),
                    ));
                }
            }

            // Lift maintenance so the site is reachable again.
            if ($maintenance) {
                $this->maintenance_off();
            }
            $this->set_status('failed', $detail);
            throw $e;
        }
    }

    // ── commit / rollback / status / cleanup ─────────────────────────────────

    /**
     * Restore confirmed good: drop the retained pre-apply tables and delete trash/staging.
     *
     * @return array<string,mixed>
     */
    public function commit(): array {
        $state = $this->load_apply_state();

        // Only touch the DB when this restore actually applied a DB swap (a files-only restore
        // must never open a database connection).
        if (is_array($state) && isset($state['db']) && is_array($state['db'])) {
            $this->drop_tables(array_values((array) ($state['db']['old_map'] ?? array())));
            $this->drop_tables(array_values((array) ($state['db']['name_map'] ?? array()))); // any leftover stg
            $this->drop_own_staging_tables(); // anything this token imported and never swapped
        }

        if (is_array($state) && isset($state['files']) && is_array($state['files'])) {
            $this->recursive_delete((string) ($state['files']['trash_dir'] ?? ''));
            $this->recursive_delete((string) ($state['files']['staging_dir'] ?? ''));
        }

        $this->set_status('committed', array());

        // Written AFTER the status, so a failure while tidying cannot un-commit a finished restore.
        $this->discard_staged_chunks();

        return array('ok' => true, 'committed' => true);
    }

    /**
     * Return the site to its pre-apply state (reverse the journal + rename old tables back).
     *
     * @return array<string,mixed>
     */
    public function rollback(): array {
        // Same lock as apply(): reversing a journal another process is still appending to is the
        // one thing that must never happen, and the status file cannot prevent it.
        $this->lock_apply();

        try {
            return $this->rollback_locked();
        } finally {
            $this->unlock_apply();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function rollback_locked(): array {
        // Never while an apply is still moving. Reversing a journal that is still being written
        // leaves the site in a state that is neither the old one nor the new one — and since the
        // manager reaches for rollback exactly when it has stopped hearing from us, this is the
        // likeliest moment for the two to collide.
        //
        // A claim that expired is not an apply that is still moving: nothing was ever handed to a
        // worker, so there is nothing to collide with. Refusing those too is what left a token that
        // could be neither applied nor rolled back.
        $status = $this->status();
        if ((string) ($status['state'] ?? '') === 'applying' && ! $this->queued_claim_expired($status)) {
            throw new RuntimeException(
                'an apply is still running; wait for it to finish or fail before rolling back'
            );
        }

        $state = $this->load_apply_state();
        if (!is_array($state)) {
            // No record of an apply. That is usually the truth — but a process killed during the
            // file phase leaves no record and a half-swapped tree, so ask the journal before
            // concluding the site was never touched.
            $this->maintenance_on();
            try {
                $replayed = $this->rollback_files_from_disk();
            } catch (\Throwable $e) {
                $this->maintenance_off();
                throw $e;
            }
            $this->maintenance_off();

            $this->set_status('rolled_back', $replayed ? array('from_journal' => true) : array('noop' => true));

            return array('ok' => true, 'rolled_back' => true, 'noop' => !$replayed);
        }

        $maintenance = true;
        $this->maintenance_on();

        try {
            if (isset($state['files']) && is_array($state['files'])) {
                $this->rollback_files($state['files']);
            } else {
                // The DB phase recorded itself and the file phase did not, which means the process
                // stopped between them. The journal on disk is the only account of what moved.
                $this->rollback_files_from_disk();
            }
            if (isset($state['db']) && is_array($state['db'])) {
                $this->rollback_database($state['db']);
            }
        } catch (\Throwable $e) {
            // Never leave the site behind a maintenance flag because the rollback failed; the
            // status already carries what went wrong.
            $this->maintenance_off();
            throw $e;
        }

        $this->maintenance_off();
        $maintenance = false;

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if (function_exists('wp_cache_flush')) {
            @wp_cache_flush();
        }

        $this->set_status('rolled_back', array());
        $this->discard_staged_chunks();

        return array('ok' => true, 'rolled_back' => true);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array {
        $status = $this->read_json($this->work_dir . '/status.json');
        return is_array($status) ? $status : array('state' => 'unknown', 'token' => $this->token);
    }

    public function cleanup(): void {
        $this->recursive_delete($this->work_dir);
    }

    // ── disk watermark ───────────────────────────────────────────────────────

    /**
     * The least free space seen on each filesystem during the apply, and what it started from.
     *
     * The restore's peak footprint is the one number about a restore that cannot be measured from
     * outside: the apply happens with the site in maintenance, and WordPress answers 503 to
     * everything then — including this plugin's own capabilities endpoint. So the manager's
     * preflight arithmetic has always been a prediction nobody could check.
     *
     * Sampling from in here makes it checkable on every restore. If the prediction is wrong, it
     * says so on a small site, in a report, instead of on a large one, halfway through a swap.
     *
     * @var array<string,array<string,int|null>>
     */
    private array $disk_watermark = array();

    private function disk_watch_start(): void {
        // Prefer the reading taken at prepare time: the chunks are already staged by now, so
        // starting the baseline here would measure the temp filesystem after its peak had passed
        // and report that a restore cost it nothing.
        $stamped = $this->read_json($this->work_dir . '/disk-baseline.json');
        $stamped = is_array($stamped) ? $stamped : array();

        $this->disk_watermark = array();
        foreach ($this->disk_paths() as $name => $path) {
            $now = $this->free_bytes($path);
            $baseline = isset($stamped[$name]) && is_int($stamped[$name]) ? $stamped[$name] : $now;

            $this->disk_watermark[$name] = array(
                'path'      => $path,
                'baseline'  => $baseline,
                'min_free'  => ($now === null || $now > $baseline) ? $baseline : $now,
                'samples'   => 1,
            );
        }
    }

    /**
     * @return array<string,int|null>
     */
    private function disk_snapshot(): array {
        $out = array();
        foreach ($this->disk_paths() as $name => $path) {
            $out[$name] = $this->free_bytes($path);
        }

        return $out;
    }

    /**
     * One sample. Cheap enough (two stat calls) to take at every chunk boundary, which is where the
     * footprint actually moves: a chunk is extracted, swapped in, and dropped.
     */
    private function disk_sample(): void {
        // Doubles as the session's heartbeat. Called at every chunk boundary, so a restore that
        // runs for hours keeps its status file recently modified — which is how the sweep tells a
        // slow restore from an abandoned one. Nothing else here updates a timestamp the sweep can
        // see, because every write during an apply goes into a subdirectory.
        @touch($this->work_dir . '/status.json');

        foreach ($this->disk_paths() as $name => $path) {
            if (!isset($this->disk_watermark[$name])) {
                continue;
            }
            $free = $this->free_bytes($path);
            if ($free === null) {
                continue;
            }
            $seen = $this->disk_watermark[$name]['min_free'];
            if ($seen === null || $free < $seen) {
                $this->disk_watermark[$name]['min_free'] = $free;
            }
            $this->disk_watermark[$name]['samples']++;
        }
    }

    /**
     * What the samples add up to: peak_used = baseline - min_free, per filesystem.
     *
     * @return array<string,mixed>
     */
    private function disk_report(): array {
        $out = array();
        foreach ($this->disk_watermark as $name => $w) {
            $peak = ($w['baseline'] === null || $w['min_free'] === null)
                ? null
                : max(0, (int) $w['baseline'] - (int) $w['min_free']);
            $out[$name] = array(
                'path'            => $w['path'],
                'baseline_bytes'  => $w['baseline'],
                'min_free_bytes'  => $w['min_free'],
                'peak_used_bytes' => $peak,
                'samples'         => $w['samples'],
            );
        }

        return $out;
    }

    /**
     * The two filesystems a restore leans on, which are not always the same one: the chunks land in
     * the plugin's temp directory, the trash and the file being swapped in are inside the site.
     *
     * @return array<string,string>
     */
    private function disk_paths(): array {
        return array(
            'temp' => class_exists('SAM_Backup_Temp') ? SAM_Backup_Temp::root() : sys_get_temp_dir(),
            'site' => $this->abspath,
        );
    }

    private function free_bytes(string $path): ?int {
        $dir = is_dir($path) ? $path : dirname($path);
        $free = @disk_free_space($dir);

        return ($free === false || $free === null) ? null : (int) $free;
    }

    /**
     * Drop the staged chunks, keeping the small state files.
     *
     * The chunks are the whole size of the restore point and nothing ever deleted them: commit()
     * cleaned the two directories inside the site and stopped, while cleanup() — which removes the
     * lot — had no callers. Every restore therefore left a full copy of the site in the host's temp
     * directory, permanently. Measured on the first client-site restore: 500 MB of free disk that
     * never came back.
     *
     * Deliberately NOT cleanup(): status.json lives here, and the manager reads it after the fact to
     * find out what happened — a redelivered job asking "did it finish?" must not be answered
     * `unknown` because we tidied up. The state files are a few kilobytes; the hourly sweep takes
     * the whole directory once nobody could still be asking.
     */
    public function discard_staged_chunks(): void {
        $this->recursive_delete($this->work_dir . '/chunks');
    }

    /**
     * Claim the apply before it is detached.
     *
     * Between the kick and the background worker actually starting there is a gap — small, but the
     * manager polls into it. Without this the status still reads `staged`, which looks exactly like
     * a dispatch that never happened, and the manager would reasonably try again.
     */
    public function mark_apply_queued(): void {
        $this->set_status('applying', array('phase' => 'queued', 'queued_at' => gmdate('c')));
    }

    /**
     * Undo the claim when the host turns out to have no way to detach the work, so the synchronous
     * retry is not turned away by apply()'s own re-entry guard.
     */
    public function clear_apply_queued(): void {
        $this->set_status('staged', array('async_unavailable' => true));
    }

    /**
     * Take the session's apply lock, or refuse.
     *
     * Non-blocking on purpose: a caller that cannot have the lock is told so
     * immediately rather than left holding a request open behind a restore that
     * may take an hour.
     */
    private function lock_apply(): void {
        $this->mkdir($this->work_dir);

        $handle = @fopen($this->work_dir . '/apply.lock', 'c');
        if ($handle === false) {
            // A session directory we cannot write to is one we cannot safely apply
            // in either — better to say so than to proceed unserialised.
            throw new RuntimeException('could not open the apply lock for this token');
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException(
                'an apply is already running for this token; poll restore/status instead of starting another'
            );
        }

        $this->lock_handle = $handle;
    }

    private function unlock_apply(): void {
        if ($this->lock_handle !== null) {
            @flock($this->lock_handle, LOCK_UN);
            fclose($this->lock_handle);
            $this->lock_handle = null;
        }
    }

    /**
     * Whether an `applying`/`queued` claim is old enough to be treated as dead.
     *
     * queued_at was written from the day the claim existed and read by nothing, so
     * a dispatch that reported success and never ran pinned the token forever. The
     * window is deliberately shorter than the manager's own grace period before it
     * falls back to a synchronous apply — otherwise its retry arrives here and is
     * refused by the very state the failed dispatch left behind.
     *
     * @param array<string,mixed> $status
     */
    private function queued_claim_expired(array $status): bool {
        if ((string) ($status['phase'] ?? '') !== 'queued') {
            return false;
        }

        $queued_at = strtotime((string) ($status['queued_at'] ?? ''));
        if ($queued_at === false) {
            // A claim with no readable timestamp cannot be aged; treat it as stale
            // rather than let it wedge the token indefinitely.
            return true;
        }

        return (time() - $queued_at) > self::QUEUED_TTL;
    }

    // ── DB apply / rollback ──────────────────────────────────────────────────

    /**
     * Import staged DB segments into sambk_stg_* (zero error tolerance), then swap
     * atomically over the live tables (RENAME), keeping the originals as sambk_old_*.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed> {name_map, old_map, tables}
     */
    private function apply_database(array $plan): array {
        $this->drop_own_staging_tables(); // clear this token's debris from a prior crashed import

        $whitelist = array_flip((array) $plan['db_tables']); // empty = all tables
        $mysqli    = $this->db_connect();

        $name_map = array(); // original table => sambk_stg_ name
        try {
            $segments = $this->staged_files('database'); // ascending seq
            foreach ($segments as $gz) {
                $this->import_sql_stream($mysqli, $gz, $name_map, $whitelist);
            }
        } catch (\Throwable $e) {
            $this->drop_tables_conn($mysqli, array_values($name_map));
            @$mysqli->close();
            throw $e;
        }

        if (empty($name_map)) {
            @$mysqli->close();
            throw new RuntimeException('DB restore: staged segments contained no restorable tables (after table scope filter).');
        }

        $this->fault('db_before_swap', 0);

        try {
            $old_map = $this->swap_staged_tables($mysqli, $name_map);
        } catch (\Throwable $e) {
            @$mysqli->close();
            throw $e;
        }
        @$mysqli->close();

        return array('name_map' => $name_map, 'old_map' => $old_map, 'tables' => array_keys($name_map));
    }

    /**
     * Import one gzipped SQL segment statement-by-statement into staging tables.
     *
     * @param array<string,string> $name_map  (by-ref) original => staging table name
     * @param array<string,int>    $whitelist table-name => 1 (empty = all)
     */
    private function import_sql_stream(mysqli $mysqli, string $gz_file, array &$name_map, array $whitelist): void {
        $gz = gzopen($gz_file, 'rb');
        if ($gz === false) {
            throw new RuntimeException("DB restore: cannot open segment {$gz_file}");
        }

        $query = '';
        try {
            while (!gzeof($gz)) {
                $line = gzgets($gz);
                if ($line === false) {
                    break;
                }
                $trimmed = trim($line);
                if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                    continue;
                }
                if (strpos($trimmed, '/*') === 0 && strpos($trimmed, '*/;') !== false) {
                    continue;
                }

                $query .= $line;

                if (substr(rtrim($query), -1) === ';') {
                    $this->run_staged_statement($mysqli, $query, $name_map, $whitelist);
                    $query = '';
                }
            }
            if (trim($query) !== '') {
                $this->run_staged_statement($mysqli, $query, $name_map, $whitelist);
            }
        } finally {
            gzclose($gz);
        }
    }

    /**
     * Execute one dump statement against its staging table. Any failure aborts the whole
     * import (no error tolerance). Statements for tables outside the whitelist are skipped.
     *
     * @param array<string,string> $name_map
     * @param array<string,int>    $whitelist
     */
    private function run_staged_statement(mysqli $mysqli, string $query, array &$name_map, array $whitelist): void {
        $table = $this->statement_table($query);

        // Table-scoped (selective) restore: silently skip statements for out-of-scope tables.
        if ($table !== null && !empty($whitelist) && !isset($whitelist[$table])) {
            return;
        }

        $rewritten = $this->rewrite_statement_table($query, $name_map);

        // Defence in depth: a statement the rewriter did not touch runs against the LIVE DB.
        // Our own dumps only produce rewritable statements + SET/UNLOCK/comments — anything
        // else (corruption/tampering) aborts instead of executing raw.
        if ($rewritten === $query
            && !preg_match('/^\s*(SET\s|UNLOCK\s+TABLES|LOCK\s+TABLES|START\s+TRANSACTION|COMMIT|BEGIN|\/\*|--|$)/i', $query)) {
            $snippet = substr((string) preg_replace('/\s+/', ' ', trim($query)), 0, 160);
            throw new RuntimeException("DB restore aborted: unexpected statement (not rewritable to staging): {$snippet}");
        }

        if ($mysqli->query($rewritten) === false) {
            $error   = $mysqli->error ?: 'unknown database error';
            $snippet = substr((string) preg_replace('/\s+/', ' ', trim($query)), 0, 160);
            throw new RuntimeException("DB restore failed: {$error} (statement: {$snippet}...)");
        }
    }

    /**
     * The FIRST backtick-quoted table identifier of a dump statement (or null).
     */
    private function statement_table(string $sql): ?string {
        $verbs = 'DROP\s+TABLE\s+IF\s+EXISTS|DROP\s+TABLE|CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS|CREATE\s+TABLE|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|LOCK\s+TABLES';
        if (preg_match('/^\s*(' . $verbs . ')\s+`([^`]+)`/i', $sql, $m) === 1) {
            return $m[2];
        }
        return null;
    }

    /**
     * Rewrite the first backtick-quoted table identifier to its sambk_stg_* staging name.
     *
     * @param array<string,string> $name_map
     */
    private function rewrite_statement_table(string $sql, array &$name_map): string {
        $verbs = 'DROP\s+TABLE\s+IF\s+EXISTS|DROP\s+TABLE|CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS|CREATE\s+TABLE|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|LOCK\s+TABLES';
        if (!preg_match('/^\s*(' . $verbs . ')\s+`([^`]+)`/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
            return $sql;
        }
        $table = $m[2][0];
        if (!isset($name_map[$table])) {
            $name_map[$table] = $this->prefixed_table_name($table, self::DB_STG_PREFIX);
        }
        $offset = (int) $m[2][1];
        return substr($sql, 0, $offset) . $name_map[$table] . substr($sql, $offset + strlen($table));
    }

    /**
     * Short digest of the token, stamped into every table this session creates.
     *
     * Without it the staging and retained-original tables are indistinguishable
     * between concurrent restores, and the blanket cleanup that ran at the start
     * of every apply dropped ALL of them — including another token's only copy
     * of its pre-restore data.
     */
    private function token8(): string {
        return substr(md5($this->token), 0, 8);
    }

    private function prefixed_table_name(string $table, string $prefix): string {
        // 10 + 8 + 1 = 19 characters, so the >64 truncation below still leaves
        // 36 characters of the original name — enough to stay recognisable.
        $prefix = $prefix . $this->token8() . '_';

        $name = $prefix . $table;
        if (strlen($name) > 64) {
            $head = $prefix . substr(md5($table), 0, 8) . '_';
            $name = $head . substr($table, -(64 - strlen($head)));
        }
        return $name;
    }

    /**
     * Atomic multi-table RENAME swap (staging over live), keeping originals as sambk_old_*.
     *
     * @param array<string,string> $name_map
     * @return array<string,string> old_map: original => sambk_old_ name
     */
    private function swap_staged_tables(mysqli $mysqli, array $name_map): array {
        $live = array_flip($this->show_tables($mysqli));
        $pairs   = array();
        $old_map = array();

        foreach ($name_map as $table => $staged) {
            if (isset($live[$table])) {
                $old = $this->prefixed_table_name($table, self::DB_OLD_PREFIX);
                $old_map[$table] = $old;
                $pairs[] = "`{$table}` TO `{$old}`";
            }
            $pairs[] = "`{$staged}` TO `{$table}`";
        }

        if ($mysqli->query('RENAME TABLE ' . implode(', ', $pairs)) !== false) {
            return $old_map;
        }

        $error = $mysqli->error ?: 'unknown database error';

        // A failed multi-table RENAME normally moves nothing (atomic), but verify + reverse.
        $after   = array_flip($this->show_tables($mysqli));
        $reverse = array();
        foreach ($name_map as $table => $staged) {
            $old = isset($old_map[$table]) ? $old_map[$table] : null;
            if ($old === null || !isset($after[$old])) {
                continue;
            }
            if (!isset($after[$table])) {
                $reverse[] = "`{$old}` TO `{$table}`";
            } elseif (!isset($after[$staged])) {
                $reverse[] = "`{$table}` TO `{$staged}`";
                $reverse[] = "`{$old}` TO `{$table}`";
            }
        }
        if (!empty($reverse)) {
            if ($mysqli->query('RENAME TABLE ' . implode(', ', $reverse)) === false) {
                throw new RuntimeException(
                    'DB restore swap failed AND rollback failed: ' . $error
                    . ' Originals are preserved under the ' . self::DB_OLD_PREFIX . ' prefix; manual recovery required.'
                );
            }
        }

        $this->drop_tables_conn($mysqli, array_values($name_map));
        throw new RuntimeException('DB restore swap failed; the live database was left untouched: ' . $error);
    }

    /**
     * Reverse a committed DB swap: rename sambk_old_* back over live, drop the restored copies.
     *
     * @param array<string,mixed> $db_state
     */
    private function rollback_database(array $db_state): void {
        $old_map = (array) ($db_state['old_map'] ?? array());
        $tables  = array_values(array_map('strval', (array) ($db_state['tables'] ?? array())));
        if (empty($old_map) && empty($tables)) {
            return;
        }

        $mysqli = $this->db_connect();
        try {
            $present = array_flip($this->show_tables($mysqli));
            $pairs   = array();
            $drop_after = array();
            foreach ($tables as $table) {
                $old = isset($old_map[$table]) ? (string) $old_map[$table] : null;
                if ($old !== null && isset($present[$old])) {
                    // Move the restored copy aside, then the original back into place.
                    $tmp = $this->prefixed_table_name($table, self::DB_STG_PREFIX);
                    if (isset($present[$table])) {
                        $pairs[] = "`{$table}` TO `{$tmp}`";
                        $drop_after[] = $tmp;
                    }
                    $pairs[] = "`{$old}` TO `{$table}`";
                }
            }
            if (!empty($pairs)) {
                if ($mysqli->query('RENAME TABLE ' . implode(', ', $pairs)) === false) {
                    throw new RuntimeException('DB rollback RENAME failed: ' . ($mysqli->error ?: 'unknown'));
                }
            }
            $this->drop_tables_conn($mysqli, $drop_after);
        } finally {
            @$mysqli->close();
        }
    }

    /**
     * Clear THIS token's import debris before re-importing.
     *
     * Deliberately blind to sambk_old_*: those are the pre-restore originals, and
     * dropping them is how a retried apply used to make its own rollback
     * impossible. They are removed by commit(), by rollback(), or by the sweep
     * once the session they belong to is gone — never here.
     */
    private function drop_own_staging_tables(): void {
        $mysqli = $this->db_connect();
        try {
            $prefix = self::DB_STG_PREFIX . $this->token8() . '_';
            $mine = array();
            foreach ($this->show_tables($mysqli) as $table) {
                if (strpos($table, $prefix) === 0) {
                    $mine[] = $table;
                }
            }
            $this->drop_tables_conn($mysqli, $mine);
        } finally {
            @$mysqli->close();
        }
    }

    /**
     * Drop restore tables belonging to sessions that no longer exist.
     *
     * Called from the hourly sweep, which knows which session directories are
     * still on disk. A table whose token digest matches none of them cannot be
     * rolled back to by anyone.
     *
     * Tables from before the per-token prefix existed carry no digest; they are
     * only removed when there is no restore session on the host at all, because
     * there is no way to tell whose they are.
     *
     * @param array<int,string> $live_digests token8() values of sessions still on disk
     */
    public function drop_tables_of_dead_sessions(array $live_digests): int {
        $live = array_flip(array_map('strval', $live_digests));
        $mysqli = $this->db_connect();

        try {
            $dead = array();

            foreach ($this->show_tables($mysqli) as $table) {
                foreach (array(self::DB_STG_PREFIX, self::DB_OLD_PREFIX) as $prefix) {
                    if (strpos($table, $prefix) !== 0) {
                        continue;
                    }

                    $rest = substr($table, strlen($prefix));

                    if (preg_match('/^([0-9a-f]{8})_/', $rest, $m)) {
                        if (!isset($live[$m[1]])) {
                            $dead[] = $table;
                        }
                    } elseif (empty($live)) {
                        $dead[] = $table; // pre-0.9.0 debris, and nothing left that could own it
                    }
                }
            }

            $this->drop_tables_conn($mysqli, $dead);

            return count($dead);
        } finally {
            @$mysqli->close();
        }
    }

    private function drop_tables(array $tables): void {
        if (empty($tables)) {
            return;
        }
        $mysqli = $this->db_connect();
        try {
            $this->drop_tables_conn($mysqli, $tables);
        } finally {
            @$mysqli->close();
        }
    }

    private function drop_tables_conn(mysqli $mysqli, array $tables): void {
        foreach ($tables as $table) {
            if ($table === '') {
                continue;
            }
            @$mysqli->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /**
     * @return array<int,string>
     */
    private function show_tables(mysqli $mysqli): array {
        $res = $mysqli->query('SHOW TABLES');
        if ($res === false) {
            return array();
        }
        $out = array();
        while (($row = $res->fetch_row()) !== null) {
            $out[] = (string) $row[0];
        }
        $res->free();
        return $out;
    }

    private function db_connect(): mysqli {
        mysqli_report(MYSQLI_REPORT_OFF);
        $cfg = $this->resolve_db_cfg();
        $mysqli = mysqli_init();
        if ($mysqli === false) {
            throw new RuntimeException('mysqli_init failed');
        }
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
        $socket = $cfg['socket'] ?? null;
        if (!@$mysqli->real_connect(
            (string) ($cfg['host'] ?? 'localhost'),
            (string) ($cfg['user'] ?? ''),
            (string) ($cfg['password'] ?? ''),
            (string) ($cfg['database'] ?? ''),
            (int) ($cfg['port'] ?? 3306),
            $socket !== null && $socket !== '' ? (string) $socket : null
        )) {
            throw new RuntimeException('DB connection failed: ' . mysqli_connect_error());
        }
        @$mysqli->set_charset((string) ($cfg['charset'] ?? 'utf8mb4'));
        // Restore text carries its own SET FOREIGN_KEY_CHECKS=0; belt-and-braces here too.
        @$mysqli->query('SET FOREIGN_KEY_CHECKS=0');
        @$mysqli->query("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
        return $mysqli;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolve_db_cfg(): array {
        if (is_array($this->db_cfg)) {
            return $this->db_cfg;
        }
        // WordPress DB_* constants (single source of truth in WP).
        list($host, $port, $socket) = self::parse_host(defined('DB_HOST') ? (string) DB_HOST : 'localhost');
        return array(
            'host'     => $host,
            'port'     => $port,
            'socket'   => $socket,
            'user'     => defined('DB_USER') ? (string) DB_USER : '',
            'password' => defined('DB_PASSWORD') ? (string) DB_PASSWORD : '',
            'database' => defined('DB_NAME') ? (string) DB_NAME : '',
            'charset'  => defined('DB_CHARSET') && DB_CHARSET ? (string) DB_CHARSET : 'utf8mb4',
        );
    }

    /**
     * @return array{0:string,1:int,2:?string}
     */
    private static function parse_host(string $db_host): array {
        $host = $db_host; $port = 3306; $socket = null;
        if (strpos($db_host, ':') !== false) {
            list($h, $tail) = explode(':', $db_host, 2);
            $host = $h !== '' ? $h : 'localhost';
            if ($tail !== '' && ctype_digit($tail)) {
                $port = (int) $tail;
            } elseif ($tail !== '') {
                $socket = $tail;
            }
        }
        return array($host, $port, $socket);
    }

    // ── File apply / rollback ────────────────────────────────────────────────

    /**
     * A relative path (tombstone / mirror root) is safe to act on only if it
     * carries no traversal segment or NUL byte — the same rule extract_zip_into()
     * applies to zip entries. Tombstones/roots ride in from the S3 backup manifest
     * verbatim, so without this a poisoned manifest could unlink or move a file
     * outside the restore staging area / webroot (e.g. '../wp-config.php').
     */
    private function is_safe_relative(string $rel): bool {
        if ($rel === '' || strpos($rel, "\0") !== false) {
            return false;
        }

        $norm = str_replace('\\', '/', $rel);

        // Absolute paths are not relative paths, whatever else they are.
        if ($norm[0] === '/' || preg_match('#^[A-Za-z]:#', $norm) === 1) {
            return false;
        }

        // Segment by segment, not substring. Testing for '..' anywhere in the
        // string rejected ordinary filenames — logo..png, jquery..min.js — which
        // then never entered the restore set, and in MIRROR that reads as "absent
        // from the backup": the live copy was moved to the trash and deleted at
        // commit. A file present in both the site and the backup was destroyed
        // for having two dots in its name.
        foreach (explode('/', $norm) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * One canonical spelling of a relative path, for comparing paths that reached
     * us from different sources. Case is left alone: the filesystems this runs on
     * are case-sensitive, and folding it would let one file mask another.
     */
    private function normalize_rel(string $rel): string {
        $norm = str_replace('\\', '/', $rel);
        $norm = preg_replace('#/+#', '/', $norm);
        $norm = (string) $norm;

        while (strpos($norm, './') === 0) {
            $norm = substr($norm, 2);
        }

        return ltrim($norm, '/');
    }

    /**
     * Paths MIRROR must never delete, however absent from the backup they look.
     *
     * The staging and trash directories live inside the webroot because a swap has
     * to be a rename on one filesystem — which puts them squarely in the walk when
     * a mirror root is the webroot itself. MIRROR would queue the trash for
     * deletion into the trash, and the engine's own directory along with it,
     * leaving the manager unable to reach status, commit or rollback on a site it
     * had just half-restored.
     */
    private function is_mirror_protected(string $rel): bool {
        if (in_array($rel, self::PROTECTED_FILES, true)) {
            return true;
        }

        $first = strtok($rel, '/');
        if ($first !== false
            && (strpos($first, self::STAGE_PREFIX) === 0 || strpos($first, self::TRASH_PREFIX) === 0)) {
            return true;
        }

        return strpos($rel, 'wp-content/plugins/simplead-backup/') === 0;
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed> {staging_dir, trash_dir, journal, mirror}
     */
    private function apply_files(array $plan): array {
        $mode        = (string) $plan['mode'];
        $keep        = array_flip((array) $plan['keep_paths']); // empty = keep everything staged
        $tombstones  = (array) $plan['tombstones'];
        $mirror_roots = (array) $plan['mirror_roots'];

        $tombstone_set = array();
        foreach ($tombstones as $tomb) {
            $tomb = ltrim((string) $tomb, '/');
            if ($this->is_safe_relative($tomb)) { // reject traversal / NUL from a poisoned manifest
                $tombstone_set[$tomb] = true;
            }
        }

        $staging_dir = $this->abspath . '/' . self::STAGE_PREFIX . $this->token;
        $trash_dir   = $this->abspath . '/' . self::TRASH_PREFIX . $this->token;
        $this->recursive_delete($staging_dir);
        $this->recursive_delete($trash_dir);
        $this->mkdir($staging_dir);
        $this->mkdir($trash_dir);

        // 1) Index the staged chunks instead of extracting them.
        //
        //    This used to extract every chunk into one staging tree and then prune it, which meant
        //    a whole second copy of the site on the client's server — on top of the chunks and the
        //    trash, a peak of roughly three times the site. Reading each zip's central directory
        //    buys the two things that mattered about the full tree — latest-wins across the chain,
        //    and knowing the entire set before the first live file moves — for a few kilobytes.
        $chunks    = $this->staged_files('files');
        $owner     = array();  // rel path => index of the chunk carrying its FINAL version
        foreach ($chunks as $i => $zip_path) {
            foreach ($this->zip_entries($zip_path) as $rel) {
                // Verbatim, not normalised: the same string has to serve as a zip entry name to
                // extract, a path relative to the webroot to swap, and a key into keep_paths.
                if (!$this->is_restorable_path($rel, $keep, $tombstone_set)) {
                    continue;
                }
                $owner[$rel] = $i; // a later chunk in the chain wins
            }
        }

        $per_chunk = array();
        foreach ($owner as $rel => $i) {
            $per_chunk[$i][] = (string) $rel;
        }

        // 2) MIRROR: live files under a mirror_root that are absent from the backup get deleted
        //    (moved to trash) so the tree EXACTLY reproduces the backup. SAFE_MERGE never deletes.
        //
        //    The set to keep comes from `keep_paths` — the manifest — and not from what happens to
        //    land in staging, which is how it was computed before. The difference shows up on the
        //    day it hurts: a chunk that fails to carry a file the manifest promises would have had
        //    MIRROR delete the live copy, because "absent from staging" was read as "absent from
        //    the backup". Only when the plan names no paths at all do we fall back to what the
        //    chunks actually carried.
        $final_set = empty($keep) ? $owner : $keep;

        // A second index of the same set, under a canonical spelling. The keys of $owner are zip
        // entry names and the keys of $keep are manifest paths; the same file can legitimately be
        // written './x.png' in one and 'x.png' in the other. Consulted only to SPARE a file from
        // deletion, never to add one, so a spelling difference can cost a redundant keep and never
        // a live file.
        $final_norm = array();
        foreach (array_keys($final_set) as $kept) {
            $final_norm[$this->normalize_rel((string) $kept)] = true;
        }

        $delete_units = array();
        if ($mode === self::MODE_MIRROR) {
            foreach ($mirror_roots as $root) {
                $root = trim((string) $root, '/');
                // Empty root = whole webroot (legit); reject only traversal / NUL.
                if (strpos($root, '..') !== false || strpos($root, "\0") !== false) {
                    continue;
                }
                $live_root = $this->abspath . '/' . $root;
                foreach ($this->relative_files($live_root) as $rel_under) {
                    $rel = ($root === '' ? '' : $root . '/') . $rel_under;
                    if ($this->is_mirror_protected($rel)) {
                        continue;
                    }
                    $known = isset($final_set[$rel]) || isset($final_norm[$this->normalize_rel($rel)]);
                    if (!$known || isset($tombstone_set[$rel])) {
                        $delete_units[] = $rel;
                    }
                }
            }
            // Explicit tombstones under no mirror_root are still honoured in MIRROR.
            foreach (array_keys($tombstone_set) as $tomb) {
                if (!isset($final_set[$tomb]) && is_file($this->abspath . '/' . $tomb)) {
                    $delete_units[] = $tomb;
                }
            }
            $delete_units = array_values(array_unique($delete_units));
        }

        if (empty($owner) && empty($delete_units)) {
            $this->recursive_delete($staging_dir);
            $this->recursive_delete($trash_dir);
            throw new RuntimeException('file restore: nothing to apply (no staged files, no mirror deletions).');
        }

        // 3) Chunk by chunk: extract only the paths this chunk owns, swap them in through the
        //    journal, then drop both the working copy and the chunk itself. Disk in use at any
        //    moment is one chunk, not one site.
        //
        //    What this gives up: a failure at chunk 15 of 19 means fourteen chunks' worth of files
        //    were already swapped and have to come back through the journal, where before nothing
        //    would have moved at all. Acceptable, because corruption is already excluded by the
        //    sha256 check at staging time, and the likeliest cause of a mid-apply failure — a full
        //    disk — is what the manager's preflight now refuses up front.
        $unit_dir     = $staging_dir . '/.sam-unit';
        $journal      = array();
        $journal_file = $trash_dir . '/journal.jsonl';
        $failure      = null;
        $step_no      = 0;

        try {
            foreach ($chunks as $i => $zip_path) {
                if (empty($per_chunk[$i])) {
                    @unlink($zip_path);
                    continue;
                }

                $this->recursive_delete($unit_dir);
                $this->mkdir($unit_dir);

                foreach ($this->extract_zip_into($zip_path, $unit_dir, $per_chunk[$i]) as $rel) {
                    $this->swap_in_unit($rel, $unit_dir, $trash_dir, $journal, $journal_file);
                    $this->fault('files_swap', ++$step_no);
                }

                // Sampled here, at the widest point of the chunk: its files are extracted, what
                // they displaced is in the trash, and nothing has been freed yet.
                $this->disk_sample();

                $this->recursive_delete($unit_dir);
                // The chunk's content is live now and whatever it displaced is in the trash, which
                // is what a rollback reads. Keeping the zip would only hold disk the site may need
                // for the chunks still to come.
                @unlink($zip_path);
            }
            foreach ($delete_units as $rel) {
                $this->delete_unit($rel, $trash_dir, $journal, $journal_file);
                $this->fault('files_swap', ++$step_no);
            }
            $this->disk_sample();
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
        }

        if ($failure !== null) {
            $failures = $this->reverse_journal($journal, $staging_dir, $trash_dir);

            if (!empty($failures)) {
                // Keep the trash: it is the only place the missing originals could still be.
                throw new RuntimeException(sprintf(
                    'file restore swap failed (%s) AND %d file(s) could not be rolled back; the trash has been kept at %s',
                    $failure,
                    count($failures),
                    $trash_dir
                ));
            }

            $this->recursive_delete($trash_dir);
            $this->recursive_delete($staging_dir);
            throw new RuntimeException("file restore swap failed ({$failure}); the original files were rolled back.");
        }

        // Success: KEEP trash + journal + (now mostly-empty) staging until commit/rollback.
        return array(
            'staging_dir' => $staging_dir,
            'trash_dir'   => $trash_dir,
            'journal'     => $journal,
            'mode'        => $mode,
        );
    }

    /**
     * Move one staged file into the live tree, moving any displaced live file into trash.
     * Journaled BEFORE the second rename so a crash between the two is still recoverable.
     *
     * @param array<int,array<string,mixed>> $journal
     */
    private function swap_in_unit(string $rel, string $staging_dir, string $trash_dir, array &$journal, string $journal_file): void {
        $live    = $this->abspath . '/' . $rel;
        $staged  = $staging_dir . '/' . $rel;
        $trashed = $trash_dir . '/' . $rel;

        $step = array('unit' => $rel, 'op' => 'swap', 'live_in_trash' => false, 'staged_live' => false);

        if (file_exists($live) || is_link($live)) {
            $this->mkdir(dirname($trashed));
            if (!@rename($live, $trashed)) {
                throw new RuntimeException("could not move live '{$rel}' aside");
            }
            $step['live_in_trash'] = true;
        }

        $journal[] = $step;
        $idx = count($journal) - 1;
        if ($step['live_in_trash']) {
            $this->journal_append($journal_file, $rel, 'swap', 'live_trashed');
        }

        $this->mkdir(dirname($live));
        if (!@rename($staged, $live)) {
            throw new RuntimeException("could not move staged '{$rel}' into place");
        }
        $journal[$idx]['staged_live'] = true;
        $this->journal_append($journal_file, $rel, 'swap', 'staged_live');
    }

    /**
     * MIRROR deletion: move a live-only file into trash (recoverable on rollback).
     *
     * @param array<int,array<string,mixed>> $journal
     */
    private function delete_unit(string $rel, string $trash_dir, array &$journal, string $journal_file): void {
        $live    = $this->abspath . '/' . $rel;
        $trashed = $trash_dir . '/' . $rel;
        if (!file_exists($live) && !is_link($live)) {
            return;
        }
        $this->mkdir(dirname($trashed));
        if (!@rename($live, $trashed)) {
            throw new RuntimeException("could not delete live '{$rel}' (mirror)");
        }
        $journal[] = array('unit' => $rel, 'op' => 'delete', 'live_in_trash' => true, 'staged_live' => false);
        $this->journal_append($journal_file, $rel, 'delete', 'live_trashed');
    }

    /**
     * Record one completed move, as one line.
     *
     * The journal used to be rewritten in full after every rename — twice per
     * file. On a site with fifty thousand files that is quadratic work and fifty
     * thousand windows in which the file is truncated, and it was written with
     * the result discarded, so a journal that could not be written was simply
     * lost. It is the only record of what to undo, so a failure to write it stops
     * the apply here, while the trash still matches what the journal says.
     */
    private function journal_append(string $journal_file, string $rel, string $op, string $event): void {
        $line = json_encode(array(
            'unit'  => $rel,
            'op'    => $op,
            'event' => $event,
        ), JSON_UNESCAPED_SLASHES) . "\n";

        $this->mkdir(dirname($journal_file));

        if (@file_put_contents($journal_file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException(
                "could not write the restore journal for '{$rel}'; stopping before moving anything else"
            );
        }
    }

    /**
     * Rebuild the journal from the trash directory.
     *
     * apply-state.json only gains its `files` entry once the whole file phase has
     * returned, so a process killed mid-apply leaves it null and rollback used to
     * conclude there were no files to put back — while the tree was half swapped.
     * The events on disk describe exactly what moved.
     *
     * @return array<int,array<string,mixed>>
     */
    private function journal_from_disk(string $trash_dir): array {
        $file = $trash_dir . '/journal.jsonl';
        if (!is_file($file)) {
            return array();
        }

        $steps = array();
        $index = array();

        foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (!is_array($event) || !isset($event['unit'])) {
                continue; // a torn final line: everything before it still stands
            }

            $rel = (string) $event['unit'];
            if (!isset($index[$rel])) {
                $index[$rel] = count($steps);
                $steps[] = array(
                    'unit'          => $rel,
                    'op'            => (string) ($event['op'] ?? 'swap'),
                    'live_in_trash' => false,
                    'staged_live'   => false,
                );
            }

            $at = $index[$rel];
            if (($event['event'] ?? '') === 'live_trashed') {
                $steps[$at]['live_in_trash'] = true;
            } elseif (($event['event'] ?? '') === 'staged_live') {
                $steps[$at]['staged_live'] = true;
            }
        }

        return $steps;
    }

    /**
     * Reverse the journal (used both for mid-swap failure AND post-apply rollback).
     *
     * @param array<int,array<string,mixed>> $journal
     */
    private function reverse_journal(array $journal, string $staging_dir, string $trash_dir): array {
        $failures = array();

        for ($i = count($journal) - 1; $i >= 0; $i--) {
            $step = $journal[$i];
            $rel  = (string) $step['unit'];
            $live = $this->abspath . '/' . $rel;

            if (!empty($step['staged_live']) && (file_exists($live) || is_link($live))) {
                // The staged file is currently live — put it back into staging.
                $this->mkdir(dirname($staging_dir . '/' . $rel));
                // When the original is waiting in the trash this eviction is only tidying: the
                // rename below puts the original back over it either way. It is only a failure
                // when the file was newly added by the restore and would otherwise stay behind.
                if (!@rename($live, $staging_dir . '/' . $rel) && empty($step['live_in_trash'])) {
                    $failures[] = array('unit' => $rel, 'reason' => 'could not remove the restored file');
                }
            }

            if (!empty($step['live_in_trash'])) {
                $trashed = $trash_dir . '/' . $rel;

                if (!file_exists($trashed) && !is_link($trashed)) {
                    // Nothing in the trash. Either an earlier attempt already put this one back,
                    // or the trash is gone — which used to be indistinguishable, because the live
                    // file had already been moved out to staging by the time we looked.
                    if (!file_exists($live) && !is_link($live)) {
                        $failures[] = array('unit' => $rel, 'reason' => 'the original is neither in the trash nor in place');
                    }
                    continue;
                }

                $this->mkdir(dirname($live));
                if (!@rename($trashed, $live)) {
                    $failures[] = array('unit' => $rel, 'reason' => 'could not move the original back into place');
                }
            }
        }

        return $failures;
    }

    /**
     * @param array<string,mixed> $files_state
     */
    private function rollback_files(array $files_state): void {
        $journal     = (array) ($files_state['journal'] ?? array());
        $staging_dir = (string) ($files_state['staging_dir'] ?? '');
        $trash_dir   = (string) ($files_state['trash_dir'] ?? '');

        $failures = $this->reverse_journal($journal, $staging_dir, $trash_dir);

        // The trash and staging directories are the evidence. Deleting them after a reversal that
        // did not fully work is what turned "some of your files are gone" into "some of your files
        // are gone and there is nothing left to recover them from" — while the caller went on to
        // report the rollback as successful.
        if (!empty($failures)) {
            $this->set_status('failed', array(
                'error'           => 'rollback could not return every file to its pre-apply state',
                'rollback_failed' => true,
                'failures'        => array_slice($failures, 0, 20),
                'trash_dir'       => $trash_dir,
            ));

            throw new RuntimeException(sprintf(
                'rollback incomplete: %d file(s) could not be restored; the trash has been kept at %s',
                count($failures),
                $trash_dir
            ));
        }

        $this->recursive_delete($trash_dir);
        $this->recursive_delete($staging_dir);
    }

    /**
     * Roll back a file phase that never got as far as recording itself.
     *
     * The paths are derived from the token exactly as apply_files() derives them,
     * and the steps come from the journal the swap wrote as it went.
     */
    private function rollback_files_from_disk(): bool {
        $staging_dir = $this->abspath . '/' . self::STAGE_PREFIX . $this->token;
        $trash_dir   = $this->abspath . '/' . self::TRASH_PREFIX . $this->token;

        $journal = $this->journal_from_disk($trash_dir);
        if (empty($journal)) {
            return false;
        }

        $this->log('warn', 'rolling back from the on-disk journal', array(
            'token' => $this->token,
            'steps' => count($journal),
        ));

        $this->rollback_files(array(
            'journal'     => $journal,
            'staging_dir' => $staging_dir,
            'trash_dir'   => $trash_dir,
        ));

        return true;
    }

    // ── zip extraction (path-traversal safe) ─────────────────────────────────

    /**
     * Open a staged chunk, tolerating the benign trailing bytes a streamed zip can carry.
     */
    private function open_zip(string $zip_file): ZipArchive {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension not available.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CHECKCONS) !== true) {
            // CHECKCONS may reject archives with benign extra bytes; retry lenient.
            if ($zip->open($zip_file) !== true) {
                throw new RuntimeException("cannot open staged zip {$zip_file}");
            }

            // Worth a line: the consistency check is the cheapest warning of a
            // damaged chunk, and taking the lenient path repeatedly is a pattern
            // the support bundle should show rather than hide.
            $this->log('warn', 'staged zip opened without consistency check', array(
                'token' => $this->token,
                'chunk' => basename($zip_file),
            ));
        }

        return $zip;
    }

    /**
     * The file entries a chunk carries, without extracting anything — the central directory only.
     *
     * @return array<int,string>
     */
    private function zip_entries(string $zip_file): array {
        $zip = $this->open_zip($zip_file);
        $names = array();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === '' || substr($name, -1) === '/') {
                continue;
            }
            $names[] = $name;
        }
        $zip->close();

        return $names;
    }

    /**
     * Is this entry part of the restore point's final state?
     *
     * @param array<string,mixed> $keep       flipped keep_paths; empty means "whatever is staged"
     * @param array<string,bool>  $tombstones flipped, already validated
     */
    private function is_restorable_path(string $rel, array $keep, array $tombstones): bool {
        if (!$this->is_safe_relative($rel)) {
            return false;
        }
        if (in_array($rel, self::PROTECTED_FILES, true)) {
            return false; // live DB credentials and the maintenance flag are never swapped
        }
        if (isset($tombstones[$rel])) {
            return false; // deleted later in the chain: a chunk must not resurrect it
        }

        return empty($keep) || isset($keep[$rel]);
    }

    /**
     * Extract the named entries (and only those) into $dest.
     *
     * @param  array<int,string> $only entry names to extract
     * @return array<int,string> the entries actually written, in extraction order
     */
    private function extract_zip_into(string $zip_file, string $dest, array $only): array {
        $zip = $this->open_zip($zip_file);
        $real_dest = realpath($dest);
        if ($real_dest === false) {
            $zip->close();
            throw new RuntimeException("staging dir missing: {$dest}");
        }
        // Nothing here is skipped quietly.
        //
        // Every one of these used to be a `continue`: the entry simply did not appear in the
        // returned list, was never swapped in, and the chunk zip carrying it was deleted a few
        // lines later by the caller. The apply then reported success. A file the backup contained
        // was missing from the restored site and its only remaining copy was gone — with no error
        // anywhere. A restore that cannot place a file has failed, and saying so lets the caller
        // reverse the journal while the trash is still there.
        $written = array();
        try {
            foreach ($only as $name) {
                if (!$this->is_safe_relative($name)) {
                    throw new RuntimeException("refusing unsafe entry name in chunk: {$name}");
                }
                $target_dir = $real_dest . '/' . dirname($name);
                if (!is_dir($target_dir)) {
                    @mkdir($target_dir, 0755, true);
                }
                $resolved = realpath($target_dir);
                if ($resolved === false || strpos($resolved . '/', $real_dest . '/') !== 0) {
                    throw new RuntimeException("entry escaped the staging root: {$name}");
                }
                $safe_path = $resolved . '/' . basename($name);
                // Suppressed so the failure is reported as ours, with the entry name attached,
                // rather than as a bare PHP warning from whichever host happens to promote them.
                $stream = @$zip->getStream($name);
                if ($stream === false) {
                    throw new RuntimeException("could not read {$name} from " . basename($zip_file));
                }
                $out = @fopen($safe_path, 'wb');
                if ($out === false) {
                    fclose($stream);
                    $why = error_get_last();
                    throw new RuntimeException(sprintf(
                        'could not open staging file for %s%s',
                        $name,
                        isset($why['message']) ? ' (' . $why['message'] . ')' : ''
                    ));
                }

                $stat     = $zip->statName($name);
                $expected = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : null;
                $bytes    = 0;

                while (!feof($stream)) {
                    $buf = fread($stream, 524288);
                    if ($buf === false) {
                        fclose($out);
                        fclose($stream);
                        @unlink($safe_path);
                        throw new RuntimeException("read failed part-way through {$name}");
                    }
                    if ($buf === '') {
                        break;
                    }
                    // An unchecked fwrite is how a full disk produced a TRUNCATED file that was then
                    // renamed over the live one, with the good copy moved into the trash — the worst
                    // outcome available, reported as a success.
                    $n = fwrite($out, $buf);
                    if ($n === false || $n !== strlen($buf)) {
                        fclose($out);
                        fclose($stream);
                        @unlink($safe_path);
                        throw new RuntimeException("short write extracting {$name} (out of disk?)");
                    }
                    $bytes += $n;
                }

                $closed = fclose($out);
                fclose($stream);

                if (!$closed) {
                    @unlink($safe_path);
                    throw new RuntimeException("could not flush {$name} to disk");
                }
                if ($expected !== null && $bytes !== $expected) {
                    @unlink($safe_path);
                    throw new RuntimeException("size mismatch for {$name}: wrote {$bytes} of {$expected} bytes");
                }

                $written[] = $name;
            }
        } finally {
            $zip->close();
        }

        return $written;
    }

    // ── maintenance / filesystem helpers ─────────────────────────────────────

    private function maintenance_on(): void {
        $file = $this->abspath . '/.maintenance';
        // WordPress ignores a .maintenance older than 10 minutes → a crash here can never
        // lock the site out permanently.
        @file_put_contents($file, "<?php \$upgrading = " . time() . ";\n");
    }

    private function maintenance_off(): void {
        $file = $this->abspath . '/.maintenance';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function has_staged(string $kind): bool {
        return !empty($this->staged_files($kind));
    }

    /**
     * Staged chunk paths for a kind, ordered by ascending seq.
     *
     * @return array<int,string>
     */
    private function staged_files(string $kind): array {
        $dir = $this->chunks_dir($kind);
        if (!is_dir($dir)) {
            return array();
        }
        $items = array();
        foreach (scandir($dir) ?: array() as $f) {
            if (preg_match('/^chunk_(\d+)\./', $f, $m) === 1) {
                $items[(int) $m[1]] = $dir . '/' . $f;
            }
        }
        ksort($items);
        return array_values($items);
    }

    /**
     * All regular files under $dir, as paths relative to $dir (sorted). Empty if missing.
     *
     * @return array<int,string>
     */
    private function relative_files(string $dir): array {
        if (!is_dir($dir)) {
            return array();
        }
        $out = array();
        $prefix_len = strlen(rtrim($dir, '/')) + 1;

        // Do not descend through a symlinked directory. RecursiveDirectoryIterator
        // follows them by default, which in MIRROR means files that live outside
        // the webroot entirely get listed as "under a mirror root" and, being
        // absent from the backup, moved to the trash and deleted at commit. The
        // link itself is still listed below — it is a normal entry to swap.
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                static function ($current) {
                    return !($current->isDir() && $current->isLink());
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile() || $file->isLink()) {
                $out[] = substr($file->getPathname(), $prefix_len);
            }
        }
        sort($out);
        return $out;
    }

    private function chunks_dir(string $kind): string {
        return $this->work_dir . '/chunks/' . $kind;
    }

    private function mkdir(string $dir): void {
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private function recursive_delete(string $dir): void {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    // ── status / plan / json ─────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function load_plan(): array {
        $plan = $this->read_json($this->work_dir . '/plan.json');
        if (!is_array($plan)) {
            throw new RuntimeException("restore session {$this->token} not prepared (no plan).");
        }
        return $plan;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load_apply_state(): ?array {
        $state = $this->read_json($this->work_dir . '/apply-state.json');
        return is_array($state) ? $state : null;
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function set_status(string $state, array $extra): void {
        $ok = $this->write_json($this->work_dir . '/status.json', array_merge(array(
            'token'      => $this->token,
            'state'      => $state,
            'updated_at' => gmdate('c'),
        ), $extra));

        // Deliberately does not throw: set_status is called from catch blocks and
        // from commit/rollback, where a throw would replace a real error with a
        // bookkeeping one. A status we could not write is still worth a log line —
        // it is what the sweep and the manager both read.
        if (!$ok) {
            $this->log('error', 'could not write restore status', array(
                'token' => $this->token,
                'state' => $state,
            ));
        }
    }

    /**
     * Write JSON the readers cannot catch half-written.
     *
     * status.json is rewritten at every transition while the manager polls it,
     * and a torn read decodes to null — which status() reports as 'unknown' and
     * rollback() treats as "nothing to undo". Temp file plus rename makes every
     * read see either the old document or the new one.
     *
     * @param mixed $data
     */
    private function write_json(string $path, $data): bool {
        $this->mkdir(dirname($path));

        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        // Same directory, so the rename stays on one filesystem and is atomic.
        $tmp = $path . '.tmp' . getmypid() . bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * For the two documents an apply cannot proceed without: plan.json, which
     * says what to restore, and apply-state.json, which is the only record of
     * how to undo it. Continuing past a failed write here means doing damage we
     * have no note of.
     *
     * @param mixed $data
     */
    private function write_json_strict(string $path, $data): void {
        if (!$this->write_json($path, $data)) {
            throw new RuntimeException('could not write ' . basename($path) . '; refusing to continue without it');
        }
    }

    /**
     * @return mixed
     */
    private function read_json(string $path) {
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string) file_get_contents($path), true);
    }

    private function fault(string $phase, int $step): void {
        if ($this->fault !== null) {
            call_user_func($this->fault, $phase, $step);
        }
    }

    private static function safe_token(string $token): string {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $token);
        return $safe === '' ? 'r' . bin2hex(random_bytes(4)) : (string) $safe;
    }
}
