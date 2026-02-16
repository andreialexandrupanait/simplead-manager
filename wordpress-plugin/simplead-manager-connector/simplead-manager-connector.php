<?php
/**
 * Plugin Name: SimpleAd Manager Connector
 * Plugin URI: https://simplead.io
 * Description: Connects this WordPress site to SimpleAd Manager for remote management, monitoring, and security.
 * Version: 1.4.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: SimpleAd
 * Author URI: https://simplead.io
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simplead-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SAM_VERSION', '1.4.0');
define('SAM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SAM_PLUGIN_FILE', __FILE__);
define('SAM_REST_NAMESPACE', 'simplead/v1');
define('SAM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Always-needed core classes
require_once SAM_PLUGIN_DIR . 'includes/class-authentication.php';
require_once SAM_PLUGIN_DIR . 'includes/class-audit-logger.php';
require_once SAM_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once SAM_PLUGIN_DIR . 'includes/class-login-handler.php';

// Autoloader for endpoint + admin classes (loaded on demand)
spl_autoload_register(function ($class) {
    static $map = [
        // REST API base + endpoints
        'SAM_REST_API'              => 'class-rest-api.php',
        'SAM_Endpoint_Base'         => 'class-rest-api.php',
        'SAM_Info_Endpoint'         => 'endpoints/class-info-endpoint.php',
        'SAM_Plugins_Endpoint'      => 'endpoints/class-plugins-endpoint.php',
        'SAM_Themes_Endpoint'       => 'endpoints/class-themes-endpoint.php',
        'SAM_Users_Endpoint'        => 'endpoints/class-users-endpoint.php',
        'SAM_Core_Endpoint'         => 'endpoints/class-core-endpoint.php',
        'SAM_Health_Endpoint'       => 'endpoints/class-health-endpoint.php',
        'SAM_Security_Endpoint'     => 'endpoints/class-security-endpoint.php',
        'SAM_Backup_Endpoint'       => 'endpoints/class-backup-endpoint.php',
        'SAM_Rollback_Endpoint'     => 'endpoints/class-rollback-endpoint.php',
        'SAM_Database_Endpoint'     => 'endpoints/class-database-endpoint.php',
        'SAM_Cron_Endpoint'         => 'endpoints/class-cron-endpoint.php',
        'SAM_Monitoring_Endpoint'   => 'endpoints/class-monitoring-endpoint.php',
        'SAM_Audit_Endpoint'        => 'endpoints/class-audit-endpoint.php',
        'SAM_Login_Endpoint'        => 'endpoints/class-login-endpoint.php',
        'SAM_Self_Update_Endpoint'  => 'endpoints/class-self-update-endpoint.php',
        'SAM_Cache_Endpoint'        => 'endpoints/class-cache-endpoint.php',
        // Admin
        'SAM_Admin'                 => 'class-admin.php',
    ];

    if (isset($map[$class])) {
        require_once SAM_PLUGIN_DIR . 'includes/' . $map[$class];
    }
});

/**
 * Main plugin class.
 */
final class SimpleAd_Manager_Connector {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        register_activation_hook(SAM_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(SAM_PLUGIN_FILE, [$this, 'deactivate']);
        register_uninstall_hook(SAM_PLUGIN_FILE, [__CLASS__, 'uninstall']);

        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('init', [$this, 'handle_login_token']);

        // Unified admin init — classes loaded via autoloader only when needed
        if (is_admin()) {
            $admin = new SAM_Admin();
            add_action('admin_menu', [$admin, 'register_menu']);
            add_action('admin_init', [$admin, 'register_settings']);
            add_action('admin_enqueue_scripts', [$admin, 'enqueue_assets']);
            $admin->register_ajax_handlers();
        }

        // Hook into various WP actions for audit logging
        SAM_Audit_Logger::register_hooks();
    }

    public function activate(): void {
        SAM_Audit_Logger::create_table();

        // Generate API credentials if not set
        if (!get_option('sam_api_key')) {
            update_option('sam_api_key', wp_generate_password(32, false));
            update_option('sam_api_secret', wp_generate_password(64, false));
        }

        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }

    public static function uninstall(): void {
        global $wpdb;

        delete_option('sam_api_key');
        delete_option('sam_api_secret');
        delete_option('sam_disabled_crons');
        delete_option('sam_settings');

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sam_audit_logs");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sam_login_tokens");
    }

    public function register_rest_routes(): void {
        $api = new SAM_REST_API();
        $api->register_routes();
    }

    public function handle_login_token(): void {
        $handler = new SAM_Login_Handler();
        $handler->maybe_handle_login();
    }
}

// Boot the plugin
SimpleAd_Manager_Connector::instance();
