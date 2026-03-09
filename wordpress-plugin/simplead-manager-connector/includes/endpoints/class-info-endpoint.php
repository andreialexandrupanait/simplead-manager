<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /info endpoint - Returns site information.
 */
class SAM_Info_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/info', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_info'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_info(WP_REST_Request $request): WP_REST_Response {
        global $wpdb, $wp_version;

        // Auto-whitelist the requesting IP on successful /info call
        // This enables self-bootstrapping: when the Laravel app first connects,
        // its IP gets whitelisted automatically.
        $this->auto_whitelist_ip();

        // Check for core updates
        $core_update_available = false;
        $core_new_version = null;
        $update_core = get_site_transient('update_core');
        if ($update_core && !empty($update_core->updates)) {
            foreach ($update_core->updates as $update) {
                if ($update->response === 'upgrade') {
                    $core_update_available = true;
                    $core_new_version = $update->current;
                    break;
                }
            }
        }

        $data = [
            'wp_version'            => $wp_version,
            'php_version'           => phpversion(),
            'mysql_version'         => $wpdb->db_version(),
            'home_url'              => home_url(),
            'site_url'              => site_url(),
            'admin_url'             => admin_url(),
            'is_multisite'          => is_multisite(),
            'timezone'              => wp_timezone_string(),
            'language'              => get_locale(),
            'core_update_available' => $core_update_available,
            'core_new_version'      => $core_new_version,
            'active_theme'          => get_stylesheet(),
            'site_title'            => get_bloginfo('name'),
            'plugin_version'        => SAM_VERSION,
            'server_software'       => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'max_upload_size'       => wp_max_upload_size(),
            'memory_limit'          => ini_get('memory_limit'),
            'debug_mode'            => defined('WP_DEBUG') && WP_DEBUG,
        ];

        return $this->success($data);
    }

    /**
     * Auto-whitelist the requesting IP if not already in the list.
     */
    private function auto_whitelist_ip(): void {
        $ip = SAM_Request_Logger::get_client_ip();

        if ($ip === '0.0.0.0') {
            return;
        }

        $whitelist = SAM_IP_Whitelist::get_whitelist();

        // Already whitelisted (exact match check is sufficient for auto-add)
        if (in_array($ip, $whitelist, true)) {
            return;
        }

        SAM_IP_Whitelist::add_ip($ip);
    }
}
