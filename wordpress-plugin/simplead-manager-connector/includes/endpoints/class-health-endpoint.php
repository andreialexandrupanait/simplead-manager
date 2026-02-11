<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /health endpoint - Overall site health check.
 */
class SAM_Health_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'health_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function health_check(WP_REST_Request $request): WP_REST_Response {
        global $wpdb, $wp_version;

        // Database connectivity
        $db_ok = (bool) $wpdb->check_connection(false);

        // Filesystem writable
        $uploads_dir = wp_upload_dir();
        $uploads_writable = is_writable($uploads_dir['basedir']);

        // PHP info
        $php_version = phpversion();
        $php_ok = version_compare($php_version, '7.4', '>=');

        // WordPress version
        $wp_ok = version_compare($wp_version, '5.6', '>=');

        // SSL active
        $ssl_active = is_ssl();

        // Cron working
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        // Plugin update count
        $update_plugins = get_site_transient('update_plugins');
        $plugin_updates = $update_plugins && !empty($update_plugins->response) ? count($update_plugins->response) : 0;

        // Theme update count
        $update_themes = get_site_transient('update_themes');
        $theme_updates = $update_themes && !empty($update_themes->response) ? count($update_themes->response) : 0;

        // Core update
        $core_update = false;
        $update_core = get_site_transient('update_core');
        if ($update_core && !empty($update_core->updates)) {
            foreach ($update_core->updates as $update) {
                if ($update->response === 'upgrade') {
                    $core_update = true;
                    break;
                }
            }
        }

        $healthy = $db_ok && $uploads_writable && $php_ok && $wp_ok;

        return $this->success([
            'healthy'           => $healthy,
            'wp_version'        => $wp_version,
            'php_version'       => $php_version,
            'database_ok'       => $db_ok,
            'uploads_writable'  => $uploads_writable,
            'ssl_active'        => $ssl_active,
            'cron_disabled'     => $cron_disabled,
            'plugin_updates'    => $plugin_updates,
            'theme_updates'     => $theme_updates,
            'core_update'       => $core_update,
            'memory_limit'      => ini_get('memory_limit'),
            'max_execution_time'=> (int) ini_get('max_execution_time'),
            'plugin_version'    => SAM_VERSION,
            'timestamp'         => time(),
        ]);
    }
}
