<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstrap: registers the simplead-backup/v1 REST routes and owns activation/deactivation.
 * Deliberately minimal — the connector is never referenced or modified.
 */
final class SAM_Backup_Plugin {

    private static ?SAM_Backup_Plugin $instance = null;

    /** @var array<int,SAM_Backup_REST_Controller> */
    private array $endpoints = array();

    public static function instance(): SAM_Backup_Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Hourly sweep of restore leftovers older than this. */
    private const LEFTOVER_MAX_AGE = 3600;

    public function boot(): void {
        add_action('rest_api_init', array($this, 'register_routes'));

        // The background half of an async apply, when the host has no loopback and falls back to
        // wp-cron. Runs the restore in a detached request rather than under the manager's socket.
        add_action('sam_backup_restore_apply', array($this, 'run_detached_apply'), 10, 1);

        // Sweep restore leftovers. Until now this plugin registered no scheduled work at all, and
        // `sam-restore-trash-*` / `sam-restore-staging-*` were only ever removed by a successful
        // commit or rollback. A restore whose manager gave up half way therefore left a full copy
        // of the old site inside ABSPATH — 462 MB of it, on the first real attempt — where nothing
        // would ever collect it and the next backup would dutifully upload it again.
        add_action('sam_backup_cleanup_leftovers', array($this, 'sweep_restore_leftovers'));
        if (!wp_next_scheduled('sam_backup_cleanup_leftovers')) {
            wp_schedule_event(time() + 300, 'hourly', 'sam_backup_cleanup_leftovers');
        }

        // Minimal local admin page (read-only diagnostics + redacted support package).
        // A broken admin page must never take down the REST engine, so guard it.
        if (is_admin() && class_exists('SAM_Backup_Admin_Page')) {
            try {
                (new SAM_Backup_Admin_Page())->register();
            } catch (\Throwable $e) {
                SAM_Backup_Logger::error('admin page registration failed', array('error' => $e->getMessage()));
            }
        }
    }

    public function register_routes(): void {
        $classes = array(
            'SAM_Backup_Capabilities_Endpoint',
            'SAM_Backup_Database_Endpoint',
            'SAM_Backup_Files_Endpoint',
            'SAM_Backup_Restore_Endpoint',
        );
        foreach ($classes as $class) {
            try {
                if (class_exists($class)) {
                    $endpoint = new $class();
                    $endpoint->register_routes();
                    $this->endpoints[] = $endpoint;
                }
            } catch (\Throwable $e) {
                // A broken endpoint must not take down the others.
                SAM_Backup_Logger::error('endpoint registration failed', array('class' => $class, 'error' => $e->getMessage()));
            }
        }
    }

    /**
     * wp-cron fallback for an async apply: run it here, detached from any client.
     */
    public function run_detached_apply(string $token): void {
        ignore_user_abort(true);
        @set_time_limit(0);

        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $token);
        if ($safe === '' || $safe === null) {
            return;
        }

        try {
            $engine = new SAM_Backup_Restore_Engine(
                $safe,
                rtrim(ABSPATH, '/'),
                SAM_Backup_Temp::session_dir('restore_' . $safe),
                null
            );
            $engine->apply(true); // the cron fallback IS the detached request
        } catch (\Throwable $e) {
            // apply() has already written `failed` with the reason into the status file, which is
            // what the manager reads. Nothing is waiting on this call.
            SAM_Backup_Logger::error('detached apply failed', array('token' => $safe, 'error' => $e->getMessage()));
        }
    }

    /**
     * Delete restore staging, trash and session directories left behind by an interrupted restore.
     *
     * What may be deleted is decided by SAM_Backup_Sweep_Policy from the session's own state, not
     * from a directory timestamp. The old version compared the age of the session directory against
     * a flat hour — but that timestamp freezes when the directory is created, because everything a
     * running restore writes goes one level down. A restore past the hour mark therefore looked
     * abandoned to the sweep, and the sweep deleted its trash: the only copy of every live file the
     * restore had displaced. This runs from an hourly cron, from every database dump, and from
     * every restore/prepare, so it had three chances an hour to do that.
     *
     * A session directory governs the staging and trash directories carrying its token: they are
     * one restore, and the state file is the only thing that knows how it is going.
     */
    public function sweep_restore_leftovers(): void {
        $root       = rtrim(ABSPATH, '/');
        $temp_root  = SAM_Backup_Temp::root();
        $removed    = 0;
        $live_tokens = array();

        foreach ((array) glob($temp_root . '/sessions/restore_*', GLOB_ONLYDIR) as $dir) {
            if (!is_string($dir) || $dir === '') {
                continue;
            }

            $token  = substr(basename($dir), strlen('restore_'));
            $status = $this->read_session_status($dir);

            // The status file is rewritten at every transition and touched at every chunk boundary,
            // so its mtime is how recently the restore was alive. Fall back to the directory only
            // when there is no status file at all.
            $stamp = @filemtime($dir . '/status.json');
            if ($stamp === false) {
                $stamp = @filemtime($dir);
            }
            $age = time() - (int) $stamp;

            if (!SAM_Backup_Sweep_Policy::should_sweep($status, $age)) {
                if ($token !== '') {
                    $live_tokens[] = $token;
                }
                continue;
            }

            if (SAM_Backup_Sweep_Policy::holds_rollback_data($status)) {
                SAM_Backup_Logger::error('sweeping a restore session that still held rollback data', array(
                    'token' => $token,
                    'state' => is_array($status) ? ($status['state'] ?? null) : null,
                    'age'   => $age,
                ));
            }

            SAM_Backup_Temp::remove_dir_under($dir, $temp_root);
            $removed++;

            if ($token !== '') {
                foreach (array($root . '/sam-restore-trash-' . $token, $root . '/sam-restore-staging-' . $token) as $side) {
                    if (is_dir($side)) {
                        SAM_Backup_Temp::remove_dir_under($side, $root);
                        $removed++;
                    }
                }
            }
        }

        $removed += $this->sweep_orphan_side_dirs($root, $live_tokens);
        $this->sweep_dead_restore_tables($live_tokens);

        if ($removed > 0) {
            SAM_Backup_Logger::info('swept restore leftovers', array('directories' => $removed));
        }
    }

    /**
     * Staging/trash directories whose session directory is gone.
     *
     * These are the only ones still judged by a timestamp, because there is no state left to ask.
     * The trash carries the apply journal, which is appended to throughout the swap, so it is a
     * truer clock than the directory itself.
     *
     * @param array<int,string> $live_tokens tokens whose session survived this sweep
     */
    private function sweep_orphan_side_dirs(string $root, array $live_tokens): int {
        $live = array_flip($live_tokens);
        $removed = 0;

        $prefixes = array(
            SAM_Backup_Restore_Engine::TRASH_PREFIX,
            SAM_Backup_Restore_Engine::STAGE_PREFIX,
        );

        foreach ($prefixes as $prefix) {
            foreach ((array) glob($root . '/' . $prefix . '*', GLOB_ONLYDIR) as $dir) {
                if (!is_string($dir) || $dir === '') {
                    continue;
                }

                $token = substr(basename($dir), strlen($prefix));
                if (isset($live[$token]) || is_dir(SAM_Backup_Temp::root() . '/sessions/restore_' . $token)) {
                    continue; // its session decides, and it decided to keep it
                }

                $stamp = @filemtime($dir . '/journal.jsonl');
                if ($stamp === false) {
                    $stamp = @filemtime($dir);
                }

                if ((time() - (int) $stamp) < self::LEFTOVER_MAX_AGE) {
                    continue;
                }

                SAM_Backup_Temp::remove_dir_under($dir, $root);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Drop the staging and retained-original tables of sessions that no longer exist.
     *
     * Best-effort and never fatal: the sweep runs inside unrelated requests, and a database that
     * cannot be reached is not a reason to fail a dump.
     *
     * @param array<int,string> $live_tokens
     */
    private function sweep_dead_restore_tables(array $live_tokens): void {
        try {
            $digests = array();
            foreach ($live_tokens as $token) {
                $digests[] = substr(md5($token), 0, 8);
            }

            // Only the database side of the engine is used here, so the paths are irrelevant.
            $engine  = new SAM_Backup_Restore_Engine('sweep', ABSPATH, SAM_Backup_Temp::root());
            $dropped = $engine->drop_tables_of_dead_sessions($digests);

            if ($dropped > 0) {
                SAM_Backup_Logger::info('swept dead restore tables', array('tables' => $dropped));
            }
        } catch (\Throwable $e) {
            SAM_Backup_Logger::warn('restore table sweep failed', array('error' => $e->getMessage()));
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function read_session_status(string $session_dir): ?array {
        $file = $session_dir . '/status.json';
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Activation: create the plugin's private temp dir and default options. Never touches
     * the connector's data or WordPress content.
     */
    public static function on_activate(): void {
        SAM_Backup_Temp::ensure();
        SAM_Backup_Options::install_defaults();
        SAM_Backup_Logger::info('activated', array('version' => SAM_BACKUP_VERSION));
    }

    /**
     * Deactivation: leave options intact (so re-activation is seamless); only clear the
     * transient working area. The connector is untouched.
     */
    public static function on_deactivate(): void {
        SAM_Backup_Temp::remove_dir(SAM_Backup_Temp::ensure('sessions'));
        wp_clear_scheduled_hook('sam_backup_cleanup_leftovers');
        SAM_Backup_Logger::info('deactivated', array('version' => SAM_BACKUP_VERSION));
    }
}
