<?php
/**
 * Plugin Name: SimpleAd Manager Connector
 * Plugin URI: https://simplead.io
 * Description: Connects this WordPress site to SimpleAd Manager for remote management, monitoring, and security.
 * Version: 1.0.0
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

define('SAM_VERSION', '1.0.0');
define('SAM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SAM_PLUGIN_FILE', __FILE__);
define('SAM_REST_NAMESPACE', 'simplead/v1');

// Core classes
require_once SAM_PLUGIN_DIR . 'includes/class-authentication.php';
require_once SAM_PLUGIN_DIR . 'includes/class-audit-logger.php';
require_once SAM_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once SAM_PLUGIN_DIR . 'includes/class-admin.php';
require_once SAM_PLUGIN_DIR . 'includes/class-login-handler.php';

// Endpoint classes
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-info-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-plugins-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-themes-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-users-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-core-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-health-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-security-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-backup-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-rollback-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-database-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-cron-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-monitoring-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-error-log-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-seo-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-audit-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-firewall-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-login-endpoint.php';
require_once SAM_PLUGIN_DIR . 'includes/endpoints/class-woocommerce-endpoint.php';

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
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('init', [$this, 'handle_login_token']);
        add_action('admin_init', [$this, 'register_settings']);

        // Hook into various WP actions for audit logging
        SAM_Audit_Logger::register_hooks();
    }

    public function activate(): void {
        SAM_Audit_Logger::create_table();

        // Create firewall tables
        SAM_Firewall_Endpoint::create_tables();

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
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sam_ip_rules");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sam_blocked_requests");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sam_login_tokens");
    }

    public function register_rest_routes(): void {
        $api = new SAM_REST_API();
        $api->register_routes();
    }

    public function register_admin_menu(): void {
        $admin = new SAM_Admin();
        $admin->register_menu();
    }

    public function register_settings(): void {
        $admin = new SAM_Admin();
        $admin->register_settings();
    }

    public function handle_login_token(): void {
        $handler = new SAM_Login_Handler();
        $handler->maybe_handle_login();
    }
}

// Boot the plugin
SimpleAd_Manager_Connector::instance();
