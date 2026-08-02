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
            $engine->apply();
        } catch (\Throwable $e) {
            // apply() has already written `failed` with the reason into the status file, which is
            // what the manager reads. Nothing is waiting on this call.
            SAM_Backup_Logger::error('detached apply failed', array('token' => $safe, 'error' => $e->getMessage()));
        }
    }

    /**
     * Delete restore staging and trash directories left behind by an interrupted restore.
     *
     * Only ones older than an hour, so a restore in progress is never touched — the manager's whole
     * restore, staging included, is minutes not hours.
     */
    public function sweep_restore_leftovers(): void {
        $root = rtrim(ABSPATH, '/');
        $removed = 0;

        foreach (array('sam-restore-trash-*', 'sam-restore-staging-*') as $pattern) {
            foreach ((array) glob($root . '/' . $pattern, GLOB_ONLYDIR) as $dir) {
                if (!is_string($dir) || $dir === '') {
                    continue;
                }
                $age = time() - (int) @filemtime($dir);
                if ($age < self::LEFTOVER_MAX_AGE) {
                    continue;
                }

                SAM_Backup_Temp::remove_dir($dir);
                $removed++;
            }
        }

        if ($removed > 0) {
            SAM_Backup_Logger::info('swept restore leftovers', array('directories' => $removed));
        }
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
