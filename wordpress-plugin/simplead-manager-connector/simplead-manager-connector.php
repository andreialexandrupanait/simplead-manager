<?php
/**
 * Plugin Name: SAD Mentenanta
 * Plugin URI: https://simplead.io
 * Description: Connects this WordPress site to SimpleAd Manager for remote management, monitoring, and security.
 * Version: 2.17.1
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

define('SAM_VERSION', '2.17.1');
define('SAM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SAM_PLUGIN_FILE', __FILE__);
define('SAM_REST_NAMESPACE', 'simplead/v1');
define('SAM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Always-needed core classes (need hooks registered at boot)
require_once SAM_PLUGIN_DIR . 'includes/class-audit-logger.php';
require_once SAM_PLUGIN_DIR . 'includes/class-login-handler.php';
require_once SAM_PLUGIN_DIR . 'includes/class-security-hardening.php';
require_once SAM_PLUGIN_DIR . 'includes/class-security-login.php';
require_once SAM_PLUGIN_DIR . 'includes/class-security-two-factor.php';
require_once SAM_PLUGIN_DIR . 'includes/class-security-captcha.php';
require_once SAM_PLUGIN_DIR . 'includes/class-security-ip-manager.php';
require_once SAM_PLUGIN_DIR . 'includes/class-performance-tweaks.php';
require_once SAM_PLUGIN_DIR . 'includes/class-site-control.php';
require_once SAM_PLUGIN_DIR . 'includes/class-admin-ux-tweaks.php';
require_once SAM_PLUGIN_DIR . 'includes/class-content-media-tweaks.php';

// Autoloader for all other classes (loaded on demand)
spl_autoload_register(function ($class) {
    static $map = [
        // Auth + rate limiter (only used during REST API requests)
        'SAM_Authentication'        => 'class-authentication.php',
        'SAM_Rate_Limiter'          => 'class-rate-limiter.php',
        'SAM_IP_Whitelist'          => 'class-ip-whitelist.php',
        'SAM_Request_Logger'        => 'class-request-logger.php',
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
        'SAM_Security_Settings_Endpoint' => 'endpoints/class-security-settings-endpoint.php',
        'SAM_Security_Htaccess'     => 'class-security-htaccess.php',
        'SAM_MU_Plugin_Manager'     => 'class-mu-plugin-manager.php',
        'SAM_Backup_Endpoint'       => 'endpoints/class-backup-endpoint.php',
        'SAM_Rollback_Endpoint'     => 'endpoints/class-rollback-endpoint.php',
        'SAM_Database_Endpoint'     => 'endpoints/class-database-endpoint.php',
        'SAM_Cron_Endpoint'         => 'endpoints/class-cron-endpoint.php',
        'SAM_Monitoring_Endpoint'   => 'endpoints/class-monitoring-endpoint.php',
        'SAM_Audit_Endpoint'        => 'endpoints/class-audit-endpoint.php',
        'SAM_Login_Endpoint'        => 'endpoints/class-login-endpoint.php',
        'SAM_Self_Update_Endpoint'  => 'endpoints/class-self-update-endpoint.php',
        'SAM_Cache_Endpoint'        => 'endpoints/class-cache-endpoint.php',
        'SAM_Diagnostic_Endpoint'   => 'endpoints/class-diagnostic-endpoint.php',
        'SAM_Site_Tweaks_Endpoint'  => 'endpoints/class-site-tweaks-endpoint.php',
        'SAM_SEO_Endpoint'          => 'endpoints/class-seo-endpoint.php',
        'SAM_Redirects_Endpoint'    => 'endpoints/class-redirects-endpoint.php',
        'SAM_Posts_Endpoint'        => 'endpoints/class-posts-endpoint.php',
        'SAM_Error_Logs_Endpoint'    => 'endpoints/class-error-logs-endpoint.php',
        'SAM_Key_Rotation_Endpoint'  => 'endpoints/class-key-rotation-endpoint.php',
        // Direct upload helper
        'SAM_Direct_Uploader'       => 'class-direct-uploader.php',
        // Admin
        'SAM_Admin'                 => 'class-admin.php',
    ];

    if (isset($map[$class])) {
        $file = SAM_PLUGIN_DIR . 'includes/' . $map[$class];
        if (file_exists($file)) {
            require_once $file;
        }
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

        // Security enforcement — must run early
        add_action('plugins_loaded', [$this, 'init_security_hardening'], 1);
        add_action('plugins_loaded', [$this, 'init_security_ip_manager'], 2);
        add_action('init', [$this, 'init_security_login'], 1);
        add_action('init', [$this, 'init_security_two_factor'], 1);
        add_action('init', [$this, 'init_security_captcha'], 2);

        // Site tweaks enforcement
        add_action('plugins_loaded', [$this, 'init_performance_tweaks'], 5);
        add_action('plugins_loaded', [$this, 'init_site_control'], 5);
        add_action('plugins_loaded', [$this, 'init_admin_ux_tweaks'], 5);
        add_action('plugins_loaded', [$this, 'init_content_media_tweaks'], 5);

        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('init', [$this, 'handle_login_token']);

        // Front-end redirects managed from SimpleAd (broken-link fixes, etc.)
        add_action('template_redirect', [$this, 'handle_redirects'], 1);

        // Unified admin init — classes loaded via autoloader only when needed
        if (is_admin()) {
            $admin = new SAM_Admin();
            add_action('admin_menu', [$admin, 'register_menu']);
            add_action('admin_enqueue_scripts', [$admin, 'enqueue_assets']);

            // Run upgrade check in admin_init (dbDelta needs admin context)
            add_action('admin_init', [$this, 'maybe_upgrade']);
            add_action('admin_init', [$admin, 'register_settings']);
        }

        // Async backup preparation via WP-Cron fallback
        add_action('sam_async_backup_prepare', [$this, 'run_async_backup_prepare'], 10, 2);

        // Single-shot cleanup of restore staging/trash directories that the
        // staged file restore could not delete within its time budget
        add_action('sam_cleanup_restore_trash', [$this, 'cleanup_restore_trash']);

        // Daily cleanup of stale backup temp files
        add_action('sam_cleanup_backup_temp', [$this, 'cleanup_backup_temp']);
        add_action('init', function () {
            if (!wp_next_scheduled('sam_cleanup_backup_temp')) {
                wp_schedule_event(time(), 'daily', 'sam_cleanup_backup_temp');
            }
        });

        // Hook into various WP actions for audit logging
        SAM_Audit_Logger::register_hooks();
    }

    /**
     * Perform a managed front-end redirect if the current request path matches
     * a stored rule. Exact-path match only, with a loop guard, so this can never
     * catch a request it was not explicitly configured for.
     */
    public function handle_redirects(): void {
        if (is_admin() || (defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $redirects = get_option('sam_redirects', []);
        if (empty($redirects) || ! is_array($redirects)) {
            return;
        }

        $request_path = SAM_Redirects_Endpoint::normalize_path($_SERVER['REQUEST_URI'] ?? '/');

        if (! isset($redirects[$request_path])) {
            return;
        }

        $rule = $redirects[$request_path];
        $target = isset($rule['target']) ? (string) $rule['target'] : '';
        $code = isset($rule['code']) ? (int) $rule['code'] : 301;

        if ($target === '') {
            return;
        }

        // Loop guard: never redirect a request onto the same URL.
        $current = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        if (untrailingslashit($target) === untrailingslashit($current)) {
            return;
        }

        wp_redirect($target, in_array($code, [301, 302], true) ? $code : 301);
        exit;
    }

    /**
     * Check for version changes and run upgrade routines.
     */
    public function maybe_upgrade(): void {
        $stored_version = get_option('sam_version', '0');
        if (version_compare($stored_version, SAM_VERSION, '<')) {
            $this->upgrade($stored_version);
            update_option('sam_version', SAM_VERSION);
        }
    }

    public function activate(): void {
        SAM_Audit_Logger::create_table();
        SAM_Request_Logger::create_table();

        // Generate API credentials if not set
        if (!get_option('sam_api_key')) {
            update_option('sam_api_key', wp_generate_password(32, false));
            update_option('sam_api_secret', wp_generate_password(64, false));
        }

        // Install/update the MU-plugin for persistent security enforcement
        SAM_MU_Plugin_Manager::install();

        update_option('sam_version', SAM_VERSION);
        flush_rewrite_rules();
    }

    /**
     * Run upgrade routines for existing installations that update without deactivate/reactivate.
     */
    private function upgrade(string $from_version): void {
        // v2.0.0: Create request log table
        if (version_compare($from_version, '2.0.0', '<')) {
            SAM_Request_Logger::create_table();
        }

        // v2.9.0: Install MU-plugin for persistent security enforcement
        if (version_compare($from_version, '2.9.0', '<')) {
            SAM_MU_Plugin_Manager::install();
        }
    }

    public function init_security_hardening(): void {
        new SAM_Security_Hardening();
    }

    public function init_security_login(): void {
        new SAM_Security_Login();
    }

    public function init_security_two_factor(): void {
        new SAM_Security_Two_Factor();
    }

    public function init_security_captcha(): void {
        new SAM_Security_Captcha();
    }

    public function init_security_ip_manager(): void {
        new SAM_Security_IP_Manager();
    }

    public function init_performance_tweaks(): void {
        new SAM_Performance_Tweaks();
    }

    public function init_site_control(): void {
        new SAM_Site_Control();
    }

    public function init_admin_ux_tweaks(): void {
        new SAM_Admin_UX_Tweaks();
    }

    public function init_content_media_tweaks(): void {
        new SAM_Content_Media_Tweaks();
    }

    public function deactivate(): void {
        // Remove scheduled events
        $timestamp = wp_next_scheduled('sam_cleanup_backup_temp');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sam_cleanup_backup_temp');
        }

        // Clean up htaccess rules on deactivation
        $htaccess = new SAM_Security_Htaccess();
        $htaccess->cleanup();

        // Remove CAPTCHA and IP management enforcement
        delete_option('sam_security_captcha');
        delete_option('sam_security_ip_management');

        // Clean up brute force transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sam_bf_%' OR option_name LIKE '_transient_timeout_sam_bf_%'");

        flush_rewrite_rules();
    }

    public static function uninstall(): void {
        global $wpdb;

        delete_option('sam_api_key');
        delete_option('sam_api_secret');
        delete_option('sam_disabled_crons');
        delete_option('sam_settings');
        delete_option('sam_ip_whitelist');
        delete_option('sam_version');
        delete_option('sam_security_settings');
        delete_option('sam_security_login');
        delete_option('sam_security_captcha');
        delete_option('sam_security_ip_management');
        delete_option('sam_security_htaccess');
        delete_option('sam_banned_ips');
        delete_option('sam_performance_settings');
        delete_option('sam_site_control_settings');
        delete_option('sam_admin_ux_settings');
        delete_option('sam_content_media_settings');
        delete_option('sam_email_settings');
        delete_option('sam_email_log');

        // Remove the MU-plugin on full uninstall
        SAM_MU_Plugin_Manager::uninstall();

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sam_audit_logs");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sam_login_tokens");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}sam_api_request_log");
    }

    public function register_rest_routes(): void {
        $api = new SAM_REST_API();
        $api->register_routes();
    }

    public function handle_login_token(): void {
        $handler = new SAM_Login_Handler();
        $handler->maybe_handle_login();
    }

    /**
     * WP-Cron fallback for async backup preparation.
     */
    public function run_async_backup_prepare(string $token, string $type): void {
        ignore_user_abort(true);
        @set_time_limit(3600);

        $endpoint = new SAM_Backup_Endpoint();
        $request = new WP_REST_Request('POST');
        $request->set_param('token', $token);
        $request->set_param('type', $type);
        $endpoint->prepare_execute($request);
    }

    /**
     * Remove restore staging/trash directories older than 1 hour.
     *
     * Scheduled as a single-shot event when the post-restore cleanup could
     * not finish within its time budget. Recent directories are kept: a
     * fresh sam-trash-* dir may hold the only copy of the pre-restore files
     * after a crashed swap.
     */
    public function cleanup_restore_trash(): void {
        $abspath = rtrim(ABSPATH, '/');
        $cutoff = time() - 3600;

        $dirs = array_merge(
            glob($abspath . '/sam-trash-*', GLOB_ONLYDIR) ?: [],
            glob($abspath . '/sam-staging-*', GLOB_ONLYDIR) ?: []
        );

        foreach ($dirs as $dir) {
            $mtime = @filemtime($dir);
            if ($mtime !== false && $mtime >= $cutoff) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($dir);
        }
    }

    /**
     * Clean up stale backup temp files older than 4 hours.
     */
    public function cleanup_backup_temp(): void {
        $token_dir = sys_get_temp_dir() . '/sam_prepared';
        if (!is_dir($token_dir)) {
            return;
        }

        $cutoff = time() - 14400; // 4 hours
        $items = new \DirectoryIterator($token_dir);
        foreach ($items as $item) {
            if ($item->isDot()) continue;
            if ($item->getMTime() < $cutoff) {
                $path = $item->getPathname();
                if ($item->isDir()) {
                    // Work directories
                    $files = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $file) {
                        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
                    }
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }
        }
    }
}

// Boot the plugin
SimpleAd_Manager_Connector::instance();
