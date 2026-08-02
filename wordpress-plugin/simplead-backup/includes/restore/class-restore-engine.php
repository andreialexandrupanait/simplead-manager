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

    /** Files that are NEVER swapped (live DB credentials + maintenance flag must survive). */
    const PROTECTED_FILES = array('wp-config.php', '.maintenance');

    private string $token;
    private string $abspath;   // live site root (no trailing slash)
    private string $work_dir;  // private per-session area (plan/status/chunks/apply-state)

    /** @var array<string,mixed>|null injectable mysqli config (null → WP DB_* constants) */
    private $db_cfg;

    /** @var callable|null test fault injector: function(string $phase, int $step): void */
    private $fault = null;

    /**
     * @param array<string,mixed>|null $db_cfg
     */
    public function __construct(string $token, string $abspath, string $work_dir, $db_cfg = null) {
        $this->token    = self::safe_token($token);
        $this->abspath  = rtrim($abspath, '/');
        $this->work_dir = rtrim($work_dir, '/');
        $this->db_cfg   = $db_cfg;
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
        $this->write_json($this->work_dir . '/plan.json', $plan);
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
    public function apply(): array {
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
        if ($current === 'applying' && $phase !== 'queued') {
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
            $this->maintenance_on();
            $maintenance = true;

            // DB first: import + atomic RENAME swap (kept as sambk_old_* for rollback).
            if ($has_db) {
                // The phase is written as it changes so a polling manager can tell a long apply
                // apart from a dead one. Without it `applying` is a constant string for however
                // many minutes the swap takes, which reads exactly like a process that has stopped.
                $this->set_status('applying', array('phase' => 'database', 'started_at' => gmdate('c')));
                $apply_state['db'] = $this->apply_database($plan);
                $this->write_json($this->work_dir . '/apply-state.json', $apply_state);
            }

            // Files: extract → prune → journaled per-path swap (trash kept for rollback).
            if ($has_files) {
                $this->set_status('applying', array('phase' => 'files', 'started_at' => gmdate('c')));
                $apply_state['files'] = $this->apply_files($plan);
                $this->write_json($this->work_dir . '/apply-state.json', $apply_state);
            }

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
            ));

            return array('ok' => true, 'applied' => true, 'db' => $apply_state['db'], 'files' => $apply_state['files']);
        } catch (\Throwable $e) {
            // A throw from apply_database/apply_files has already rolled its own phase back to
            // pre-apply. Lift maintenance so the (untouched or rolled-back) site is reachable.
            if ($maintenance) {
                $this->maintenance_off();
            }
            $this->set_status('failed', array('error' => $e->getMessage()));
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
            $this->drop_orphaned_restore_tables();
        }

        if (is_array($state) && isset($state['files']) && is_array($state['files'])) {
            $this->recursive_delete((string) ($state['files']['trash_dir'] ?? ''));
            $this->recursive_delete((string) ($state['files']['staging_dir'] ?? ''));
        }

        $this->set_status('committed', array());

        return array('ok' => true, 'committed' => true);
    }

    /**
     * Return the site to its pre-apply state (reverse the journal + rename old tables back).
     *
     * @return array<string,mixed>
     */
    public function rollback(): array {
        // Never while an apply is still moving. Reversing a journal that is still being written
        // leaves the site in a state that is neither the old one nor the new one — and since the
        // manager reaches for rollback exactly when it has stopped hearing from us, this is the
        // likeliest moment for the two to collide.
        if ((string) ($this->status()['state'] ?? '') === 'applying') {
            throw new RuntimeException(
                'an apply is still running; wait for it to finish or fail before rolling back'
            );
        }

        $state = $this->load_apply_state();
        if (!is_array($state)) {
            // Nothing was applied — the site is already at pre-apply.
            $this->set_status('rolled_back', array('noop' => true));
            return array('ok' => true, 'rolled_back' => true, 'noop' => true);
        }

        $maintenance = true;
        $this->maintenance_on();

        if (isset($state['files']) && is_array($state['files'])) {
            $this->rollback_files($state['files']);
        }
        if (isset($state['db']) && is_array($state['db'])) {
            $this->rollback_database($state['db']);
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

    // ── DB apply / rollback ──────────────────────────────────────────────────

    /**
     * Import staged DB segments into sambk_stg_* (zero error tolerance), then swap
     * atomically over the live tables (RENAME), keeping the originals as sambk_old_*.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed> {name_map, old_map, tables}
     */
    private function apply_database(array $plan): array {
        $this->drop_orphaned_restore_tables(); // clear debris from a prior crashed restore

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

    private function prefixed_table_name(string $table, string $prefix): string {
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

    private function drop_orphaned_restore_tables(): void {
        $mysqli = $this->db_connect();
        try {
            $orphans = array();
            foreach ($this->show_tables($mysqli) as $table) {
                if (strpos($table, self::DB_STG_PREFIX) === 0 || strpos($table, self::DB_OLD_PREFIX) === 0) {
                    $orphans[] = $table;
                }
            }
            $this->drop_tables_conn($mysqli, $orphans);
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
        return $rel !== '' && strpos($rel, '..') === false && strpos($rel, "\0") === false;
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

        $staging_dir = $this->abspath . '/' . self::STAGE_PREFIX . $this->token;
        $trash_dir   = $this->abspath . '/' . self::TRASH_PREFIX . $this->token;
        $this->recursive_delete($staging_dir);
        $this->recursive_delete($trash_dir);
        $this->mkdir($staging_dir);
        $this->mkdir($trash_dir);

        // 1) Extract every staged chunk zip in ascending seq (chain order → latest wins).
        foreach ($this->staged_files('files') as $zip_path) {
            $this->extract_zip_into($zip_path, $staging_dir);
        }

        // 2) Prune staging to the EXACT final state: drop anything not in keep_paths, and
        //    drop tombstoned paths. A chunk that also carried a since-deleted/overwritten
        //    file can therefore never resurrect it.
        $staged = $this->relative_files($staging_dir);
        foreach ($staged as $rel) {
            $keep_it = empty($keep) ? true : isset($keep[$rel]);
            if (!$keep_it || in_array($rel, self::PROTECTED_FILES, true)) {
                @unlink($staging_dir . '/' . $rel);
            }
        }
        foreach ($tombstones as $tomb) {
            $tomb = ltrim((string) $tomb, '/');
            if (!$this->is_safe_relative($tomb)) {
                continue; // reject traversal / NUL from a poisoned manifest
            }
            @unlink($staging_dir . '/' . $tomb);
        }
        $staged = $this->relative_files($staging_dir); // recompute after pruning
        $staged_set = array_flip($staged);

        // 3) MIRROR: live files under a mirror_root that are absent from the backup get
        //    deleted (moved to trash) so the tree EXACTLY reproduces the backup. SAFE_MERGE
        //    never deletes. Tombstones are applied in both modes only via the pruning above
        //    (they are simply absent from staging); MIRROR additionally removes live extras.
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
                    if (in_array($rel, self::PROTECTED_FILES, true)) {
                        continue;
                    }
                    if (!isset($staged_set[$rel])) {
                        $delete_units[] = $rel;
                    }
                }
            }
            // Explicit tombstones under no mirror_root are still honoured in MIRROR.
            foreach ($tombstones as $tomb) {
                $tomb = ltrim((string) $tomb, '/');
                if ($this->is_safe_relative($tomb) && !isset($staged_set[$tomb]) && is_file($this->abspath . '/' . $tomb)) {
                    $delete_units[] = $tomb;
                }
            }
            $delete_units = array_values(array_unique($delete_units));
        }

        if (empty($staged) && empty($delete_units)) {
            $this->recursive_delete($staging_dir);
            $this->recursive_delete($trash_dir);
            throw new RuntimeException('file restore: nothing to apply (no staged files, no mirror deletions).');
        }

        // 4) Journaled per-path swap with rollback. Swap-in first, then mirror deletions.
        $journal = array();
        $journal_file = $trash_dir . '/journal.json';
        $failure = null;
        $step_no = 0;

        try {
            foreach ($staged as $rel) {
                $this->swap_in_unit($rel, $staging_dir, $trash_dir, $journal, $journal_file);
                $this->fault('files_swap', ++$step_no);
            }
            foreach ($delete_units as $rel) {
                $this->delete_unit($rel, $trash_dir, $journal, $journal_file);
                $this->fault('files_swap', ++$step_no);
            }
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
        }

        if ($failure !== null) {
            $this->reverse_journal($journal, $staging_dir, $trash_dir);
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
        $this->write_json($journal_file, $journal);

        $this->mkdir(dirname($live));
        if (!@rename($staged, $live)) {
            throw new RuntimeException("could not move staged '{$rel}' into place");
        }
        $journal[$idx]['staged_live'] = true;
        $this->write_json($journal_file, $journal);
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
        $this->write_json($journal_file, $journal);
    }

    /**
     * Reverse the journal (used both for mid-swap failure AND post-apply rollback).
     *
     * @param array<int,array<string,mixed>> $journal
     */
    private function reverse_journal(array $journal, string $staging_dir, string $trash_dir): void {
        for ($i = count($journal) - 1; $i >= 0; $i--) {
            $step = $journal[$i];
            $rel  = (string) $step['unit'];
            $live = $this->abspath . '/' . $rel;

            if (!empty($step['staged_live'])) {
                // The staged file is currently live — put it back into staging.
                $this->mkdir(dirname($staging_dir . '/' . $rel));
                @rename($live, $staging_dir . '/' . $rel);
            }
            if (!empty($step['live_in_trash'])) {
                // The original live file is in trash — restore it.
                $this->mkdir(dirname($live));
                @rename($trash_dir . '/' . $rel, $live);
            }
        }
    }

    /**
     * @param array<string,mixed> $files_state
     */
    private function rollback_files(array $files_state): void {
        $journal     = (array) ($files_state['journal'] ?? array());
        $staging_dir = (string) ($files_state['staging_dir'] ?? '');
        $trash_dir   = (string) ($files_state['trash_dir'] ?? '');
        if (empty($journal)) {
            return;
        }
        $this->reverse_journal($journal, $staging_dir, $trash_dir);
        $this->recursive_delete($trash_dir);
        $this->recursive_delete($staging_dir);
    }

    // ── zip extraction (path-traversal safe) ─────────────────────────────────

    private function extract_zip_into(string $zip_file, string $dest): void {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension not available.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CHECKCONS) !== true) {
            // CHECKCONS may reject archives with benign extra bytes; retry lenient.
            if ($zip->open($zip_file) !== true) {
                throw new RuntimeException("cannot open staged zip {$zip_file}");
            }
        }
        $real_dest = realpath($dest);
        if ($real_dest === false) {
            $zip->close();
            throw new RuntimeException("staging dir missing: {$dest}");
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || substr($name, -1) === '/') {
                continue;
            }
            if (strpos($name, '..') !== false || strpos($name, "\0") !== false) {
                continue; // reject traversal / null byte
            }
            $target_dir = $real_dest . '/' . dirname($name);
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
            }
            $resolved = realpath($target_dir);
            if ($resolved === false || strpos($resolved . '/', $real_dest . '/') !== 0) {
                continue; // escaped the staging root
            }
            $safe_path = $resolved . '/' . basename($name);
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $out = fopen($safe_path, 'wb');
            if ($out === false) {
                fclose($stream);
                continue;
            }
            while (!feof($stream)) {
                $buf = fread($stream, 524288);
                if ($buf === false) {
                    break;
                }
                fwrite($out, $buf);
            }
            fclose($out);
            fclose($stream);
        }
        $zip->close();
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
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
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
        $this->write_json($this->work_dir . '/status.json', array_merge(array(
            'token'      => $this->token,
            'state'      => $state,
            'updated_at' => gmdate('c'),
        ), $extra));
    }

    /**
     * @param mixed $data
     */
    private function write_json(string $path, $data): void {
        $this->mkdir(dirname($path));
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
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
