<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GET/POST simplead-backup/v1/capabilities
 *
 * Reports what this host can do so the manager can pick the right backup strategy per site
 * (consistent-snapshot vs fallback, multipart sizing, compression, etc.). Read-only probes.
 */
final class SAM_Backup_Capabilities_Endpoint extends SAM_Backup_REST_Controller {

    public function register_routes(): void {
        register_rest_route($this->namespace(), '/capabilities', array(
            array(
                'methods'             => array('GET', 'POST'),
                'callback'            => array($this, 'handle'),
                'permission_callback' => array($this, 'check_permission'),
            ),
        ));
    }

    public function handle(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response($this->collect(), 200);
    }

    /**
     * @return array<string,mixed>
     */
    private function collect(): array {
        global $wpdb;

        $db = $this->probe_database();

        $probe_request = SAM_Backup_Loopback::is_probe_request();
        $loopback = $probe_request ? true : SAM_Backup_Loopback::available();

        return array(
            'plugin' => array(
                'name'          => 'simplead-backup',
                'version'       => SAM_BACKUP_VERSION,
                'rest_namespace'=> SAM_BACKUP_REST_NAMESPACE,
            ),
            'php' => array(
                'version'              => PHP_VERSION,
                'sapi'                 => PHP_SAPI,
                'max_execution_time'   => (int) ini_get('max_execution_time'),
                'memory_limit'         => ini_get('memory_limit'),
                'memory_limit_bytes'   => $this->bytes_from_ini(ini_get('memory_limit')),
                'post_max_size'        => ini_get('post_max_size'),
                'upload_max_filesize'  => ini_get('upload_max_filesize'),
                'open_basedir'         => ini_get('open_basedir') ?: null,
            ),
            'wordpress' => array(
                'version'     => get_bloginfo('version'),
                'is_multisite'=> is_multisite(),
                'abspath'     => defined('ABSPATH') ? ABSPATH : null,
                'table_prefix'=> $wpdb->prefix,
            ),
            'database' => $db,
            // What the restore side of this plugin can do. Without it the manager has no way to
            // tell an old plugin from a new one, and would have to guess whether asking for an
            // async apply is safe — on an old plugin the flag is simply absent and it stays
            // synchronous, which is still correct for a small site.
            // Both `async` and `loopback` used to be the literal `true`, which told the manager to
            // ask for a detached apply on hosts that cannot detach — and the failure landed as a
            // restore stuck in `queued` rather than as a slower, working, synchronous one. They
            // are measured now. The probe answering this very request must not start another, so
            // it says yes and lets the caller judge by the HTTP status.
            'restore' => array(
                'async'    => $probe_request ? true : ($loopback || SAM_Backup_Loopback::cron_runs()),
                'loopback' => $loopback,
                'cron'     => SAM_Backup_Loopback::cron_runs(),
            ),
            'extensions' => array(
                'zip'    => class_exists('ZipArchive'),
                'gzip'   => function_exists('gzopen'),
                'zlib'   => extension_loaded('zlib'),
                'pdo'    => extension_loaded('pdo_mysql'),
                'mysqli' => extension_loaded('mysqli'),
                'openssl'=> extension_loaded('openssl'),
                'curl'   => extension_loaded('curl'),
            ),
            'shell' => array(
                // Reported for diagnostics ONLY. This plugin never calls these.
                'shell_exec_available' => $this->function_available('shell_exec'),
                'exec_available'       => $this->function_available('exec'),
                'proc_open_available'  => $this->function_available('proc_open'),
                'note'                 => 'reported only; the backup engine never invokes shell functions',
            ),
            'disk' => array(
                'temp_dir'      => SAM_Backup_Temp::root(),
                'free_bytes'    => SAM_Backup_Temp::free_bytes(),
                // A restore needs room in two places and this reported only one of them. The chunks
                // land in the temp directory, but the trash — the rollback — and the file being
                // swapped in are inside the site, which can be a different filesystem with a
                // different quota. A preflight that checked only the temp side would happily start
                // a restore that runs the webroot out of space half way through the swap.
                'site_dir'          => rtrim(ABSPATH, '/'),
                'site_free_bytes'   => $this->free_bytes(ABSPATH),
                'same_filesystem'   => $this->same_filesystem(SAM_Backup_Temp::ensure(), ABSPATH),
                // Reported because naming the directory proved nothing. A temp
                // root owned by another user — which is what a root-run wp-cli
                // command leaves behind — makes every dump and every staged
                // restore answer 500 with nothing but a PHP warning in the
                // host's error log. The manager refuses the backup up front now
                // instead of discovering it three phases in.
                'temp_writable' => SAM_Backup_Temp::is_writable(),
            ),
            'transport' => array(
                'multipart_upload_supported' => class_exists('ZipArchive') && function_exists('curl_init'),
                'streaming_gzip'             => function_exists('gzopen'),
            ),
            'backup_strategy' => array(
                'consistent_snapshot' => $db['consistent_snapshot_supported'],
                'recommended'         => $db['consistent_snapshot_supported'] ? 'single-connection-consistent-snapshot' : 'fallback-lock-or-mysqldump',
            ),
            'generated_at' => gmdate('c'),
        );
    }

    /**
     * Probe DB engine/version and, once, whether START TRANSACTION WITH CONSISTENT SNAPSHOT works.
     *
     * @return array<string,mixed>
     */
    private function probe_database(): array {
        global $wpdb;

        $version = $wpdb->get_var('SELECT VERSION()');
        $engine  = (is_string($version) && stripos($version, 'mariadb') !== false) ? 'mariadb' : 'mysql';

        // Snapshot capability probe on a dedicated connection so we never disturb $wpdb's session.
        $snapshot_ok = false;
        $snapshot_note = '';
        if (extension_loaded('mysqli') && defined('DB_HOST')) {
            try {
                $probe = SAM_Backup_Consistent_Dumper::from_wp_constants();
                // We can't call private connect(); instead do a light inline probe.
                $snapshot_ok = $this->probe_snapshot($snapshot_note);
            } catch (\Throwable $e) {
                $snapshot_note = $e->getMessage();
            }
        }

        // Detect non-InnoDB tables (consistent snapshot does not cover MyISAM).
        $non_innodb = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES " .
                "WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = 'BASE TABLE' " .
                "AND ENGINE IS NOT NULL AND ENGINE <> 'InnoDB'",
                DB_NAME
            )
        );

        return array(
            'server_version'                 => $version,
            'engine_family'                  => $engine,
            'name'                           => defined('DB_NAME') ? DB_NAME : null,
            'consistent_snapshot_supported'  => $snapshot_ok,
            'consistent_snapshot_note'       => $snapshot_note ?: 'ok',
            'non_innodb_tables'              => array_values($non_innodb ?: array()),
            'all_innodb'                     => empty($non_innodb),
        );
    }

    private function probe_snapshot(string &$note): bool {
        list($host, $port, $socket) = $this->wp_host_parts();
        $m = @mysqli_init();
        if (!$m || !@$m->real_connect($host, DB_USER, DB_PASSWORD, DB_NAME, $port, $socket ?: null)) {
            $note = 'connect failed: ' . mysqli_connect_error();
            return false;
        }
        $ok = ($m->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ') !== false)
            && ($m->query('START TRANSACTION WITH CONSISTENT SNAPSHOT') !== false);
        if (!$ok) {
            $note = $m->error;
        } else {
            $m->query('COMMIT');
        }
        @$m->close();
        return $ok;
    }

    /**
     * @return array{0:string,1:int,2:?string}
     */
    private function wp_host_parts(): array {
        $db_host = defined('DB_HOST') ? (string) DB_HOST : 'localhost';
        $host = $db_host; $port = 3306; $socket = null;
        if (strpos($db_host, ':') !== false) {
            list($h, $tail) = explode(':', $db_host, 2);
            $host = $h ?: 'localhost';
            if (ctype_digit($tail)) { $port = (int) $tail; }
            elseif ($tail !== '') { $socket = $tail; }
        }
        return array($host, $port, $socket);
    }

    /**
     * Free space on the filesystem holding $path, or null when the host will not say.
     */
    private function free_bytes(string $path): ?int {
        $free = @disk_free_space($path);

        return ($free === false || $free === null) ? null : (int) $free;
    }

    /**
     * Do these two paths sit on the same filesystem?
     *
     * It decides whether a restore's two demands — chunks in temp, trash inside the site — compete
     * for one pool of free space or two, and the answer changes the arithmetic by a factor of two.
     * Reported as null rather than guessed when stat() will not say, so the manager can be
     * conservative instead of confidently wrong.
     */
    private function same_filesystem(string $a, string $b): ?bool {
        $sa = @stat($a);
        $sb = @stat($b);
        if (!is_array($sa) || !is_array($sb) || !isset($sa['dev'], $sb['dev'])) {
            return null;
        }

        return $sa['dev'] === $sb['dev'];
    }

    private function function_available(string $fn): bool {
        if (!function_exists($fn)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array($fn, $disabled, true);
    }

    private function bytes_from_ini($value): int {
        $value = trim((string) $value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $num  = (int) $value;
        switch ($unit) {
            case 'g': $num *= 1024; // fallthrough
            case 'm': $num *= 1024; // fallthrough
            case 'k': $num *= 1024;
        }
        return $num;
    }
}
