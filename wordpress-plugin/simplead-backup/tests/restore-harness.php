<?php

declare(strict_types=1);

/**
 * Lab-only CLI harness that proves the DB half of SAM_Backup_Restore_Engine (staged import into
 * sambk_stg_* + atomic multi-table RENAME swap, kept as sambk_old_* until commit/rollback) against
 * a real MySQL/MariaDB — WITHOUT WordPress/HTTP. Runs inside spike-wp (which has mysqli) against a
 * DEDICATED scratch database, so it never touches a real WP install.
 *
 * Scenarios (all must PASS):
 *   A) full DB round-trip : dump → modify → restore → DB back to the dumped state (per-table).
 *   B) selective table     : restore only one whitelisted table; the other keeps its modified value.
 *   C) rollback            : apply (no commit) → rollback → DB back to the MODIFIED (pre-apply) state.
 *
 * Usage:
 *   php restore-harness.php --host=H --port=P --user=U --pass=W --name=DB --work=/tmp/rrh
 * Prints a JSON result on stdout; exit 0 iff every scenario passes.
 */

require __DIR__ . '/../includes/db/class-consistent-dumper.php';
require __DIR__ . '/../includes/restore/class-restore-engine.php';

$opts = getopt('', array('host::', 'port::', 'user::', 'pass::', 'name::', 'work::'));
$cfg = array(
    'host'     => $opts['host'] ?? '127.0.0.1',
    'port'     => (int) ($opts['port'] ?? 3306),
    'user'     => $opts['user'] ?? 'root',
    'password' => $opts['pass'] ?? '',
    'database' => $opts['name'] ?? 'sam_restore_test',
    'charset'  => 'utf8mb4',
);
$work = $opts['work'] ?? (sys_get_temp_dir() . '/sam_restore_harness_' . uniqid());

mysqli_report(MYSQLI_REPORT_OFF);

function conn(array $cfg, bool $with_db = true): mysqli {
    $m = mysqli_init();
    if (!@$m->real_connect($cfg['host'], $cfg['user'], $cfg['password'], $with_db ? $cfg['database'] : '', $cfg['port'])) {
        fwrite(STDERR, 'connect failed: ' . mysqli_connect_error() . "\n");
        exit(2);
    }
    $m->set_charset('utf8mb4');
    return $m;
}

function q(mysqli $m, string $sql): void {
    if ($m->query($sql) === false) {
        throw new RuntimeException("SQL failed: {$sql} — {$m->error}");
    }
}

function scalar(mysqli $m, string $sql): ?string {
    $r = $m->query($sql);
    if ($r === false) {
        return null;
    }
    $row = $r->fetch_row();
    $r->free();
    return $row ? (string) $row[0] : null;
}

/** Reset the scratch schema to a known baseline (probe + keepme tables). */
function reset_schema(array $cfg): void {
    $root = conn($cfg, false);
    q($root, 'DROP DATABASE IF EXISTS `' . $cfg['database'] . '`');
    q($root, 'CREATE DATABASE `' . $cfg['database'] . '` CHARACTER SET utf8mb4');
    $root->close();

    $m = conn($cfg);
    q($m, 'CREATE TABLE `probe` (id INT PRIMARY KEY, val VARCHAR(64) NOT NULL, blob_col VARBINARY(16) NULL)');
    q($m, "INSERT INTO `probe` (id,val,blob_col) VALUES (1,'ORIG-1',0x0102), (2,'ORIG-2',NULL), (3,'ORIG-3',0xdeadbeef)");
    q($m, 'CREATE TABLE `keepme` (id INT PRIMARY KEY, note VARCHAR(64))');
    q($m, "INSERT INTO `keepme` (id,note) VALUES (1,'KEEP-ORIG')");
    $m->close();
}

/** Dump the scratch DB into $dir/database/chunk_N.sql.gz; return the ordered segment paths. */
function dump_segments(array $cfg, string $dir): array {
    $out = $dir . '/database';
    $dumper = new SAM_Backup_Consistent_Dumper(array_merge($cfg, array('segment_bytes' => 4096, 'time_budget' => 120)));
    $manifest = $dumper->dump($out);
    $paths = array();
    foreach ($manifest['segments'] as $seg) {
        $paths[] = $seg['path'];
    }
    return $paths;
}

function engine(array $cfg, string $work, string $token): SAM_Backup_Restore_Engine {
    return new SAM_Backup_Restore_Engine($token, sys_get_temp_dir() . '/sam_no_abspath', $work . '/' . $token, $cfg);
}

/** Signature of the current DB state for the probe+keepme tables. */
function signature(array $cfg): array {
    $m = conn($cfg);
    $probe = array();
    $r = $m->query('SELECT id,val,IFNULL(HEX(blob_col),\'\') FROM `probe` ORDER BY id');
    while ($r && ($row = $r->fetch_row()) !== null) {
        $probe[] = implode(':', $row);
    }
    $keep = scalar($m, "SELECT note FROM `keepme` WHERE id=1");
    $m->close();
    return array('probe' => $probe, 'keep' => $keep);
}

@mkdir($work, 0755, true);
$results = array();

try {
    // ── Scenario A: full DB round-trip ─────────────────────────────────────
    reset_schema($cfg);
    $orig = signature($cfg);
    $segsA = dump_segments($cfg, $work . '/A');

    $m = conn($cfg);
    q($m, "UPDATE `probe` SET val='MODIFIED-1' WHERE id=1");
    q($m, "DELETE FROM `probe` WHERE id=3");
    q($m, "INSERT INTO `probe` (id,val) VALUES (9,'ADDED-9')");
    q($m, "UPDATE `keepme` SET note='KEEP-MODIFIED' WHERE id=1");
    $m->close();

    $eA = engine($cfg, $work, 'A');
    $eA->prepare(array('mode' => 'safe_merge', 'scope' => array('database' => true, 'files' => false)));
    foreach ($segsA as $i => $seg) {
        $eA->stage_db_chunk($i, $seg, hash_file('sha256', $seg));
    }
    $eA->apply();
    $eA->commit();
    $results['A_pass'] = (signature($cfg) == $orig);

    // ── Scenario B: selective table restore (only `probe`) ────────────────
    reset_schema($cfg);
    $origB = signature($cfg);
    $segsB = dump_segments($cfg, $work . '/B');

    $m = conn($cfg);
    q($m, "UPDATE `probe` SET val='MODIFIED-1' WHERE id=1");
    q($m, "UPDATE `keepme` SET note='KEEP-MODIFIED' WHERE id=1");
    $m->close();

    $eB = engine($cfg, $work, 'B');
    $eB->prepare(array('mode' => 'safe_merge', 'db_tables' => array('probe'), 'scope' => array('database' => true, 'files' => false)));
    foreach ($segsB as $i => $seg) {
        $eB->stage_db_chunk($i, $seg, hash_file('sha256', $seg));
    }
    $eB->apply();
    $eB->commit();
    $sigB = signature($cfg);
    // probe reverted to original; keepme keeps its MODIFIED value (out of table scope).
    $results['B_pass'] = ($sigB['probe'] == $origB['probe']) && ($sigB['keep'] === 'KEEP-MODIFIED');

    // ── Scenario C: rollback returns to pre-apply (modified) state ─────────
    reset_schema($cfg);
    $segsC = dump_segments($cfg, $work . '/C');

    $m = conn($cfg);
    q($m, "UPDATE `probe` SET val='MODIFIED-1' WHERE id=1");
    q($m, "INSERT INTO `probe` (id,val) VALUES (9,'ADDED-9')");
    $m->close();
    $modified = signature($cfg);

    $eC = engine($cfg, $work, 'C');
    $eC->prepare(array('mode' => 'safe_merge', 'scope' => array('database' => true, 'files' => false)));
    foreach ($segsC as $i => $seg) {
        $eC->stage_db_chunk($i, $seg, hash_file('sha256', $seg));
    }
    $eC->apply();
    $eC->rollback();
    $results['C_pass'] = (signature($cfg) == $modified);

    // Cleanup scratch DB.
    $root = conn($cfg, false);
    q($root, 'DROP DATABASE IF EXISTS `' . $cfg['database'] . '`');
    $root->close();
} catch (\Throwable $e) {
    fwrite(STDERR, 'HARNESS ERROR: ' . $e->getMessage() . "\n");
    echo json_encode(array('ok' => false, 'error' => $e->getMessage())) . "\n";
    exit(1);
}

$all = ($results['A_pass'] ?? false) && ($results['B_pass'] ?? false) && ($results['C_pass'] ?? false);
echo json_encode(array_merge(array('ok' => $all), $results)) . "\n";
exit($all ? 0 : 1);
