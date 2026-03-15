<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Self-update endpoint — allows the manager app to push plugin updates.
 * Uses WordPress Plugin_Upgrader for maximum hosting compatibility.
 */
class SAM_Self_Update_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/self-update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'self_update'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function self_update(WP_REST_Request $request): WP_REST_Response {
        @set_time_limit(300);

        $params = $request->get_json_params();
        $download_url = $params['download_url'] ?? '';

        if (empty($download_url) || !filter_var($download_url, FILTER_VALIDATE_URL)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'INVALID_URL', 'message' => 'A valid download_url is required.'],
            ], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        WP_Filesystem();

        $plugin_file = 'simplead-manager-connector/simplead-manager-connector.php';

        // Get current version before update
        $all_plugins = get_plugins();
        $old_version = $all_plugins[$plugin_file]['Version'] ?? 'unknown';

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        // Override the package URL to our custom download URL
        add_filter('upgrader_package_options', function ($options) use ($download_url) {
            $options['package'] = $download_url;
            return $options;
        });

        // Force the upgrader to think there's an update available
        $update_transient = get_site_transient('update_plugins');
        if (!is_object($update_transient)) {
            $update_transient = new \stdClass();
        }
        if (!isset($update_transient->response)) {
            $update_transient->response = [];
        }

        $update_transient->response[$plugin_file] = (object) [
            'slug'        => 'simplead-manager-connector',
            'plugin'      => $plugin_file,
            'new_version' => '99.0.0', // Force update
            'package'     => $download_url,
        ];
        set_site_transient('update_plugins', $update_transient);

        $result = $upgrader->upgrade($plugin_file);

        // Clean up the fake transient entry
        delete_site_transient('update_plugins');
        wp_update_plugins();

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'UPDATE_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }

        if ($result === false) {
            $feedback = $skin->get_upgrade_messages();
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'UPDATE_FAILED', 'message' => 'Update failed. ' . implode(' ', $feedback)],
            ], 500);
        }

        // Ensure plugin is still active
        if (!is_plugin_active($plugin_file)) {
            activate_plugin($plugin_file);
        }

        // Get new version
        wp_cache_delete('plugins', 'plugins');
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $all_plugins = get_plugins();
        $new_version = $all_plugins[$plugin_file]['Version'] ?? 'unknown';

        SAM_Audit_Logger::log('self_update', 'plugin', 'simplead-manager-connector', "Updated from {$old_version} to {$new_version} via SimpleAd Manager");

        return $this->success([
            'old_version' => $old_version,
            'new_version' => $new_version,
            'message'     => "Plugin updated from {$old_version} to {$new_version}.",
        ]);
    }
}
