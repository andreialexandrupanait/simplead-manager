<?php

declare(strict_types=1);

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    // Allow direct CLI use by the lab harness; block web access without WP.
    exit;
}

/**
 * SAM_Backup_Consistent_Dumper
 * =============================
 * Produces a *point-in-time consistent* logical dump of a MySQL/MariaDB (InnoDB)
 * database, fixing the "torn read" defect in the connector's engine.
 *
 * The correctness model (validated in docs/backup/spike/DATABASE-CONSISTENCY.md):
 *
 *   1. ONE mysqli connection for the WHOLE dump. Never $wpdb reused per chunk, never a
 *      new connection per HTTP request — that is exactly what tore the connector's dump
 *      (posts paged on one connection, postmeta paged on another → orphan FK rows).
 *   2. On that one connection:
 *          SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
 *          START TRANSACTION WITH CONSISTENT SNAPSHOT;
 *      Every table read happens inside this single transaction, so all reads observe the
 *      same MVCC snapshot. Concurrent commits are invisible → consistent dump. COMMIT at end.
 *      No mysqldump, no SUPER, no LOCK TABLES → works on locked-down shared hosting.
 *   3. Stable pagination: ORDER BY the real PRIMARY KEY (or, if none, by all columns) so
 *      LIMIT offset,N returns a deterministic slice. Within the frozen snapshot this is
 *      exact — no rows appear/disappear/reorder mid-dump.
 *   4. Binary/BLOB columns are emitted as hex literals (0x...) so bytes round-trip exactly,
 *      instead of the connector's lossy string-escaping of binary data.
 *
 * IMPORTANT separation of concerns:
 *   The read-side snapshot statements (START TRANSACTION WITH CONSISTENT SNAPSHOT) are the
 *   MECHANISM used to READ consistently. They are NOT written into the restorable SQL text.
 *   The restore text is purely DDL (SHOW CREATE TABLE) + INSERTs, wrapped only in
 *   SET NAMES / SET FOREIGN_KEY_CHECKS — so it restores cleanly on any host.
 *
 * Output: gzip SEGMENTS (database/chunk_N.sql.gz), never one monolith. Segments rotate by
 * uncompressed byte budget, always on a statement boundary.
 *
 * Resume note: a consistent snapshot only lives inside ONE connection. Resuming in a later
 * HTTP request would open a NEW connection = a NEW snapshot = inconsistent. Therefore the
 * consistent DB dump runs to completion in a single process/connection; the exposed cursor
 * is for progress/observability, and if the soft time budget is exceeded the run reports
 * done=false / consistent=false and the orchestrator RESTARTS it (a fresh snapshot). This is
 * the honest reading of the spike finding, not a limitation to be papered over.
 */
final class SAM_Backup_Consistent_Dumper {

    /** @var array<string,mixed> */
    private array $cfg;

    private int $segment_bytes;
    private float $time_budget;
    private int $batch_rows;

    /** @var array<int,string> optional include list (exact table names) */
    private array $include_tables;
    /** @var array<int,string> optional exclude list (exact table names) */
    private array $exclude_tables;

    /**
     * @param array{
     *   host?:string, port?:int, socket?:?string, user?:string, password?:string,
     *   database?:string, charset?:string, segment_bytes?:int, time_budget?:float,
     *   batch_rows?:int, include_tables?:array<int,string>, exclude_tables?:array<int,string>
     * } $cfg
     */
    public function __construct(array $cfg) {
        $this->cfg            = $cfg;
        $this->segment_bytes  = (int) ($cfg['segment_bytes'] ?? 8 * 1024 * 1024);
        $this->time_budget    = (float) ($cfg['time_budget'] ?? 90.0);
        $this->batch_rows     = max(1, (int) ($cfg['batch_rows'] ?? 1000));
        $this->include_tables = array_values($cfg['include_tables'] ?? array());
        $this->exclude_tables = array_values($cfg['exclude_tables'] ?? array());
    }

    /**
     * Build a dumper from WordPress DB_* constants (single source of truth in WP).
     */
    public static function from_wp_constants(array $overrides = array()): self {
        list($host, $port, $socket) = self::parse_host(defined('DB_HOST') ? (string) DB_HOST : 'localhost');
        $cfg = array_merge(array(
            'host'     => $host,
            'port'     => $port,
            'socket'   => $socket,
            'user'     => defined('DB_USER') ? (string) DB_USER : '',
            'password' => defined('DB_PASSWORD') ? (string) DB_PASSWORD : '',
            'database' => defined('DB_NAME') ? (string) DB_NAME : '',
            'charset'  => defined('DB_CHARSET') && DB_CHARSET ? (string) DB_CHARSET : 'utf8mb4',
        ), $overrides);
        return new self($cfg);
    }

    /**
     * Run the full consistent dump into $output_dir (segments named chunk_N.sql.gz).
     *
     * @return array<string,mixed> manifest
     */
    public function dump(string $output_dir): array {
        $started = microtime(true);
        if (!is_dir($output_dir) && !@mkdir($output_dir, 0700, true) && !is_dir($output_dir)) {
            throw new RuntimeException('Cannot create DB dump output dir: ' . $output_dir);
        }

        $mysqli = $this->connect();
        try {
            // --- establish the single consistent read snapshot ---
            $this->exec($mysqli, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->exec($mysqli, 'START TRANSACTION WITH CONSISTENT SNAPSHOT');

            $server_version = (string) $mysqli->server_info;
            $engine         = (stripos($server_version, 'mariadb') !== false) ? 'mariadb' : 'mysql';
            $database       = (string) $this->cfg['database'];

            $tables = $this->discover_tables($mysqli, $database);

            // --- segment writer state ---
            $seg = new SAM_Backup_Segment_Writer($output_dir, $this->segment_bytes);
            $seg->write($this->restore_header($database, $server_version));

            $table_reports = array();
            $total_rows    = 0;
            $done          = true;
            $cursor        = null;

            foreach ($tables as $ti => $table) {
                // Soft time budget: stop cleanly BEFORE starting a new table if over budget.
                // A budget of zero means no budget, as the endpoint's own documentation promises.
                // Read literally it meant "already over", so the first table was skipped, the dump
                // came back done=false having written nothing, and the orchestrator restarted it
                // forever.
                if ($this->time_budget > 0 && (microtime(true) - $started) > $this->time_budget) {
                    $done   = false;
                    $cursor = array('table_index' => $ti, 'table' => $table, 'offset' => 0);
                    break;
                }

                $rows = $this->dump_table($mysqli, $table, $seg, $started, $cursor, $ti);
                $table_reports[] = array('name' => $table, 'rows' => $rows);
                $total_rows     += $rows;

                if ($cursor !== null) { // budget hit mid-table
                    $done = false;
                    break;
                }
            }

            if ($done) {
                $seg->write($this->restore_footer());
            }
            $segments = $seg->finish();

            $this->exec($mysqli, 'COMMIT');

            return array(
                'ok'             => true,
                'done'           => $done,
                'consistent'     => $done, // consistent only if the single-snapshot run completed
                'database'       => $database,
                'server_version' => $server_version,
                'engine'         => $engine,
                'tables'         => $table_reports,
                'table_count'    => count($table_reports),
                'total_rows'     => $total_rows,
                'segments'       => $segments,
                'segment_count'  => count($segments),
                'elapsed'        => round(microtime(true) - $started, 3),
                'cursor'         => $cursor,
                'snapshot'       => 'REPEATABLE READ / CONSISTENT SNAPSHOT (single connection)',
            );
        } finally {
            @$mysqli->close();
        }
    }

    // ---------------------------------------------------------------------------------
    // Per-table dump
    // ---------------------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $cursor  set (by reference) if the budget is hit mid-table
     * @return int rows written
     */
    private function dump_table(mysqli $mysqli, string $table, SAM_Backup_Segment_Writer $seg, float $started, ?array &$cursor, int $ti): int {
        $q = self::qid($table);

        // DDL from SHOW CREATE TABLE — the authoritative schema.
        $create = $this->fetch_row($mysqli, "SHOW CREATE TABLE {$q}");
        if ($create === null || !isset($create[1])) {
            return 0;
        }
        $seg->write("\n-- ----- Table {$table} -----\nDROP TABLE IF EXISTS {$q};\n" . $create[1] . ";\n");

        // Column metadata: type (for binary/hex), and generated-column detection.
        $columns   = $this->table_columns($mysqli, $table); // [name => ['binary'=>bool,'generated'=>bool]]
        $insertable = array();
        $binary_of  = array();
        foreach ($columns as $name => $meta) {
            if ($meta['generated']) {
                continue; // cannot INSERT into generated columns
            }
            $insertable[]      = $name;
            $binary_of[$name]  = $meta['binary'];
        }
        if (empty($insertable)) {
            return 0; // nothing insertable (e.g. all-generated) — DDL is enough
        }

        $order_by = $this->order_by_clause($mysqli, $table, array_keys($columns));
        $select_cols = implode(', ', array_map(array(self::class, 'qid'), $insertable));
        $col_list    = '(' . $select_cols . ')';

        $offset       = 0;
        $rows_written = 0;
        $pending      = array();
        $pending_bytes = 0;
        $flush_at_bytes = 512 * 1024; // cap a single multi-row INSERT ~512 KiB

        // MySQL's own estimate of how wide a row of this table is. It does not change inside the
        // snapshot, so it is worth one query per table and nothing more.
        $avg_row = $this->avg_row_length($mysqli, $table);

        while (true) {
            // Time-budget check inside the paging loop for large tables.
            if ($this->time_budget > 0 && (microtime(true) - $started) > $this->time_budget) {
                if (!empty($pending)) {
                    $seg->write("INSERT INTO {$q} {$col_list} VALUES " . implode(",\n", $pending) . ";\n");
                    $pending = array();
                }
                $cursor = array('table_index' => $ti, 'table' => $table, 'offset' => $offset);
                return $rows_written;
            }

            // How many rows this host can hold at once, recomputed per page because the free
            // memory is what moves. A flat thousand rows is what exhausted 512M on a site whose
            // postmeta carries multi-megabyte serialised values — the connector learned that and
            // grew adaptive batching; the engine that replaced it did not inherit any of it.
            $page = $this->page_rows($avg_row);

            $sql = "SELECT {$select_cols} FROM {$q} {$order_by} LIMIT {$offset}, {$page}";
            // USE_RESULT streams the page row by row instead of buffering all of it into PHP
            // first. Safe here only because this loop issues no other query against $mysqli until
            // the result is freed below — which is also why the free() must stay unconditional.
            $res = $mysqli->query($sql, MYSQLI_USE_RESULT);
            if ($res === false) {
                throw new RuntimeException("Read failed for {$table}: " . $mysqli->error);
            }
            $batch = 0;
            while (($row = $res->fetch_assoc()) !== null) {
                $vals = array();
                foreach ($insertable as $name) {
                    $vals[] = $this->format_value($mysqli, $row[$name], $binary_of[$name]);
                }
                $tuple = '(' . implode(',', $vals) . ')';
                $pending[]     = $tuple;
                $pending_bytes += strlen($tuple) + 2;
                $rows_written++;
                $batch++;

                if (count($pending) >= 200 || $pending_bytes >= $flush_at_bytes) {
                    $seg->write("INSERT INTO {$q} {$col_list} VALUES " . implode(",\n", $pending) . ";\n");
                    $pending = array();
                    $pending_bytes = 0;
                }
            }
            $res->free();

            // Counted from rows actually consumed, not from the size we asked for: the page size
            // now varies with free memory, so the two are no longer the same number.
            if ($batch < $page) {
                break; // last page
            }
            $offset += $batch;
        }

        if (!empty($pending)) {
            $seg->write("INSERT INTO {$q} {$col_list} VALUES " . implode(",\n", $pending) . ";\n");
        }

        return $rows_written;
    }

    /**
     * MySQL's estimate of the average row width, floored so the division stays honest.
     *
     * MariaDB reports 0 for some engines and for empty tables, in which case the
     * floor simply yields the configured batch size for narrow rows.
     */
    private function avg_row_length(mysqli $mysqli, string $table): int {
        $esc = $mysqli->real_escape_string($table);
        $sql = "SELECT COALESCE(AVG_ROW_LENGTH, 0) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$esc}'";

        $res = $mysqli->query($sql, MYSQLI_STORE_RESULT);
        if ($res === false) {
            return 256;
        }

        $row = $res->fetch_row();
        $res->free();

        return max(256, (int) ($row[0] ?? 0));
    }

    /**
     * How many rows of this width fit in the memory we can still spend.
     *
     * The factor of three covers the copies of the same data that exist at the
     * peak of a page: the rows as fetched, the formatted values, and the SQL text
     * accumulating in $pending — where hex-encoding a binary column doubles it
     * again. The floor is one row, not a comfortable minimum: a table of
     * ten-megabyte rows read ten at a time is the same failure this exists to
     * prevent, only further along, and a slow dump beats a fatal one.
     */
    private function page_rows(int $avg_row): int {
        $available = max(
            16 * 1024 * 1024,
            $this->memory_limit_bytes() - memory_get_usage(true)
        );

        $budget = (int) ($available * 0.15);

        // The configured batch_rows is a ceiling now, not a fixed size.
        return max(1, min($this->batch_rows, intdiv($budget, $avg_row * 3)));
    }

    /**
     * The memory limit in bytes, or a conservative guess when the host declines to
     * say. -1 means unlimited, which on shared hosting usually means the real
     * limit lives somewhere else and you meet it without warning.
     */
    private function memory_limit_bytes(): int {
        $raw = trim((string) @ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return 256 * 1024 * 1024;
        }

        $unit  = strtolower(substr($raw, -1));
        $value = (int) $raw;

        switch ($unit) {
            case 'g': return $value * 1024 * 1024 * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'k': return $value * 1024;
            default:  return $value;
        }
    }

    // ---------------------------------------------------------------------------------
    // Metadata helpers
    // ---------------------------------------------------------------------------------

    /**
     * All BASE TABLES (views excluded) in the database — naturally covers Multisite
     * wp_N_* and WooCommerce/HPOS wp_wc_* — filtered by optional include/exclude lists.
     *
     * @return array<int,string>
     */
    private function discover_tables(mysqli $mysqli, string $database): array {
        $esc = $mysqli->real_escape_string($database);
        $res = $mysqli->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES " .
            "WHERE TABLE_SCHEMA = '{$esc}' AND TABLE_TYPE = 'BASE TABLE' " .
            "ORDER BY TABLE_NAME ASC"
        );
        if ($res === false) {
            throw new RuntimeException('Failed to list tables: ' . $mysqli->error);
        }
        $tables = array();
        while (($row = $res->fetch_row()) !== null) {
            $name = (string) $row[0];
            if (!empty($this->include_tables) && !in_array($name, $this->include_tables, true)) {
                continue;
            }
            if (in_array($name, $this->exclude_tables, true)) {
                continue;
            }
            $tables[] = $name;
        }
        $res->free();
        return $tables;
    }

    /**
     * @return array<string,array{binary:bool,generated:bool}>  in declared column order
     */
    private function table_columns(mysqli $mysqli, string $table): array {
        $q   = self::qid($table);
        $res = $mysqli->query("SHOW COLUMNS FROM {$q}");
        if ($res === false) {
            throw new RuntimeException("SHOW COLUMNS failed for {$table}: " . $mysqli->error);
        }
        $cols = array();
        while (($row = $res->fetch_assoc()) !== null) {
            $type  = strtolower((string) $row['Type']);
            $extra = strtolower((string) ($row['Extra'] ?? ''));
            $cols[(string) $row['Field']] = array(
                'binary'    => (bool) preg_match('/\b(tinyblob|mediumblob|longblob|blob|varbinary|binary|bit)\b|(geometry|point|linestring|polygon|geometrycollection|multipoint|multilinestring|multipolygon)/', $type),
                'generated' => (strpos($extra, 'generated') !== false),
            );
        }
        $res->free();
        return $cols;
    }

    /**
     * Deterministic ORDER BY: real PRIMARY KEY if present, else all columns. Combined with
     * LIMIT offset,N inside the frozen snapshot this yields a stable, tear-free slice.
     *
     * @param array<int,string> $all_columns
     */
    private function order_by_clause(mysqli $mysqli, string $table, array $all_columns): string {
        $q   = self::qid($table);
        $res = $mysqli->query("SHOW KEYS FROM {$q} WHERE Key_name = 'PRIMARY'");
        $pk  = array();
        if ($res !== false) {
            $rows = array();
            while (($row = $res->fetch_assoc()) !== null) {
                $rows[(int) $row['Seq_in_index']] = (string) $row['Column_name'];
            }
            $res->free();
            ksort($rows);
            $pk = array_values($rows);
        }
        $cols = !empty($pk) ? $pk : $all_columns;
        if (empty($cols)) {
            return '';
        }
        return 'ORDER BY ' . implode(', ', array_map(array(self::class, 'qid'), $cols));
    }

    // ---------------------------------------------------------------------------------
    // Value formatting
    // ---------------------------------------------------------------------------------

    /**
     * NULL → NULL; binary/BLOB → hex literal 0x... (empty → ''); text/number → escaped quoted.
     */
    private function format_value(mysqli $mysqli, $value, bool $is_binary): string {
        if ($value === null) {
            return 'NULL';
        }
        if ($is_binary) {
            if ($value === '') {
                return "''";
            }
            return '0x' . bin2hex((string) $value);
        }
        return "'" . $mysqli->real_escape_string((string) $value) . "'";
    }

    // ---------------------------------------------------------------------------------
    // Restore text framing (NOT the read-snapshot statements)
    // ---------------------------------------------------------------------------------

    private function restore_header(string $database, string $server_version): string {
        return "-- Simplead Backup — consistent logical dump\n"
            . '-- Generated (UTC): ' . gmdate('Y-m-d H:i:s') . "\n"
            . '-- Database: ' . $database . "\n"
            . '-- Server: ' . $server_version . "\n"
            . "-- Read isolation: REPEATABLE READ + START TRANSACTION WITH CONSISTENT SNAPSHOT (single connection)\n"
            . "-- NOTE: the snapshot is the READ mechanism; it is deliberately NOT part of this restore text.\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n"
            . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
    }

    private function restore_footer(): string {
        return "\nSET FOREIGN_KEY_CHECKS=1;\n-- End of Simplead Backup dump\n";
    }

    // ---------------------------------------------------------------------------------
    // Connection / low-level helpers
    // ---------------------------------------------------------------------------------

    private function connect(): mysqli {
        mysqli_report(MYSQLI_REPORT_OFF); // handle errors explicitly, don't throw mid-stream
        $host   = (string) ($this->cfg['host'] ?? 'localhost');
        $user   = (string) ($this->cfg['user'] ?? '');
        $pass   = (string) ($this->cfg['password'] ?? '');
        $db     = (string) ($this->cfg['database'] ?? '');
        $port   = (int) ($this->cfg['port'] ?? 3306);
        $socket = $this->cfg['socket'] ?? null;

        $mysqli = mysqli_init();
        if ($mysqli === false) {
            throw new RuntimeException('mysqli_init failed');
        }
        // Long-running single connection; avoid premature server-side timeouts on big dumps.
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
        if (!@$mysqli->real_connect($host, $user, $pass, $db, $port, $socket ?: null)) {
            throw new RuntimeException('DB connection failed: ' . mysqli_connect_error());
        }
        $charset = (string) ($this->cfg['charset'] ?? 'utf8mb4');
        @$mysqli->set_charset($charset);
        return $mysqli;
    }

    private function exec(mysqli $mysqli, string $sql): void {
        if ($mysqli->query($sql) === false) {
            throw new RuntimeException('Query failed: ' . $sql . ' — ' . $mysqli->error);
        }
    }

    /**
     * @return array<int,mixed>|null numeric-indexed row
     */
    private function fetch_row(mysqli $mysqli, string $sql): ?array {
        $res = $mysqli->query($sql);
        if ($res === false) {
            return null;
        }
        $row = $res->fetch_row();
        $res->free();
        return $row ?: null;
    }

    /**
     * Backtick-quote an identifier (escape embedded backticks).
     */
    private static function qid(string $ident): string {
        return '`' . str_replace('`', '``', $ident) . '`';
    }

    /**
     * @return array{0:string,1:int,2:?string} [host, port, socket]
     */
    private static function parse_host(string $db_host): array {
        $host   = $db_host;
        $port   = 3306;
        $socket = null;
        if (strpos($db_host, ':') !== false) {
            list($h, $tail) = explode(':', $db_host, 2);
            $host = $h !== '' ? $h : 'localhost';
            if ($tail !== '' && ctype_digit($tail)) {
                $port = (int) $tail;
            } elseif ($tail !== '') {
                $socket = $tail; // unix socket path
            }
        }
        return array($host, $port, $socket);
    }
}

/**
 * Gzip segment writer: rotates output into chunk_N.sql.gz files by uncompressed byte
 * budget. Rotation only happens on the statement boundaries the caller writes at, so no
 * SQL statement is ever split across segments. Returns per-segment file + size + sha256.
 */
final class SAM_Backup_Segment_Writer {

    private string $dir;
    private int $budget;
    private int $index = 0;
    /** @var resource|null */
    private $fh = null;
    private int $bytes_in_segment = 0;
    /** @var array<int,array<string,mixed>> */
    private array $segments = array();

    public function __construct(string $dir, int $budget_bytes) {
        $this->dir    = rtrim($dir, '/');
        $this->budget = max(64 * 1024, $budget_bytes);
        $this->open();
    }

    public function write(string $text): void {
        if ($this->fh === null) {
            $this->open();
        }
        // Rotate BEFORE writing if the current segment is already at/over budget.
        if ($this->bytes_in_segment > 0 && $this->bytes_in_segment >= $this->budget) {
            $this->rotate();
        }
        // gzwrite may write fewer bytes than requested — loop until the whole statement is
        // committed so no SQL statement is ever truncated inside a segment.
        $len = strlen($text);
        $off = 0;
        while ($off < $len) {
            $w = gzwrite($this->fh, $off === 0 ? $text : substr($text, $off));
            if ($w === false || $w === 0) {
                throw new RuntimeException('gzwrite failed while writing DB dump segment');
            }
            $off += $w;
        }
        $this->bytes_in_segment += $len;
    }

    /**
     * Close the final segment and return the segment manifest.
     *
     * @return array<int,array<string,mixed>>
     */
    public function finish(): array {
        $this->close_current();
        return $this->segments;
    }

    private function open(): void {
        $path = sprintf('%s/chunk_%d.sql.gz', $this->dir, $this->index);
        $fh = gzopen($path, 'wb6');
        if ($fh === false) {
            throw new RuntimeException('Cannot open gzip segment: ' . $path);
        }
        $this->fh = $fh;
        $this->bytes_in_segment = 0;
    }

    private function rotate(): void {
        $this->close_current();
        $this->index++;
        $this->open();
    }

    private function close_current(): void {
        if ($this->fh === null) {
            return;
        }
        gzclose($this->fh);
        $this->fh = null;
        $path = sprintf('%s/chunk_%d.sql.gz', $this->dir, $this->index);
        if (is_file($path) && $this->bytes_in_segment > 0) {
            $this->segments[] = array(
                'file'              => basename($path),
                'path'              => $path,
                'gzip_bytes'        => (int) filesize($path),
                'uncompressed_bytes'=> $this->bytes_in_segment,
                'sha256'            => hash_file('sha256', $path),
            );
        } elseif (is_file($path)) {
            @unlink($path); // drop an empty trailing segment
        }
    }
}
