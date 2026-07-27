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

    public function boot(): void {
        add_action('rest_api_init', array($this, 'register_routes'));
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
        SAM_Backup_Logger::info('deactivated', array('version' => SAM_BACKUP_VERSION));
    }
}
