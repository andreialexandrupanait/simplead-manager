<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin management endpoints.
 */
class SAM_Plugins_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/plugins', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_plugins'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/plugins/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_plugins'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/plugins/activate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'activate_plugin'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/plugins/deactivate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'deactivate_plugin'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/plugins/delete', [
            'methods'             => 'POST',
            'callback'            => [$this, 'delete_plugin'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function list_plugins(WP_REST_Request $request): WP_REST_Response {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $update_plugins = get_site_transient('update_plugins');

        $plugins = [];
        foreach ($all_plugins as $file => $data) {
            $slug = strpos($file, '/') !== false ? dirname($file) : basename($file, '.php');

            $update_available = false;
            $new_version = null;
            if ($update_plugins && isset($update_plugins->response[$file])) {
                $update_available = true;
                $new_version = $update_plugins->response[$file]->new_version ?? null;
            }

            $plugins[] = [
                'file'             => $file,
                'name'             => $data['Name'] ?? '',
                'version'          => $data['Version'] ?? '',
                'status'           => in_array($file, $active_plugins, true) ? 'active' : 'inactive',
                'update_available' => $update_available,
                'new_version'      => $new_version,
                'slug'             => $slug,
                'author'           => wp_strip_all_tags($data['Author'] ?? ''),
                'description'      => $data['Description'] ?? '',
                'plugin_uri'       => $data['PluginURI'] ?? '',
                'requires_wp'      => $data['RequiresWP'] ?? '',
                'requires_php'     => $data['RequiresPHP'] ?? '',
            ];
        }

        return $this->success(['plugins' => $plugins]);
    }

    public function update_plugins(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $plugin_files = $params['plugins'] ?? [];

        if (empty($plugin_files)) {
            return $this->error('MISSING_PLUGINS', 'No plugins specified for update.')->get_error_data()
                ? new WP_REST_Response(['success' => false, 'error' => ['code' => 'MISSING_PLUGINS', 'message' => 'No plugins specified for update.']], 400)
                : new WP_REST_Response(['success' => false], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Use silent skin to suppress output
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        $results = [];
        foreach ($plugin_files as $plugin_file) {
            $plugin_file = sanitize_text_field($plugin_file);
            $result = $upgrader->upgrade($plugin_file);

            $results[$plugin_file] = [
                'success' => ($result === true || $result === null),
                'error'   => is_wp_error($result) ? $result->get_error_message() : null,
            ];

            if ($result === true || $result === null) {
                SAM_Audit_Logger::log('plugin_updated', 'plugin', $plugin_file, 'Updated via SimpleAd Manager');
            }
        }

        // Clear update transient so next check is fresh
        delete_site_transient('update_plugins');
        wp_update_plugins();

        return $this->success(['results' => $results]);
    }

    public function activate_plugin(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $plugin_file = sanitize_text_field($params['plugin'] ?? '');

        if (empty($plugin_file)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'MISSING_PLUGIN', 'message' => 'Plugin file not specified.']], 400);
        }

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'ACTIVATION_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }

        SAM_Audit_Logger::log('plugin_activated', 'plugin', $plugin_file, 'Activated via SimpleAd Manager');

        return $this->success(['plugin' => $plugin_file, 'status' => 'active']);
    }

    public function deactivate_plugin(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $plugin_file = sanitize_text_field($params['plugin'] ?? '');

        if (empty($plugin_file)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'MISSING_PLUGIN', 'message' => 'Plugin file not specified.']], 400);
        }

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins($plugin_file);

        SAM_Audit_Logger::log('plugin_deactivated', 'plugin', $plugin_file, 'Deactivated via SimpleAd Manager');

        return $this->success(['plugin' => $plugin_file, 'status' => 'inactive']);
    }

    public function delete_plugin(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $plugin_file = sanitize_text_field($params['plugin'] ?? '');

        if (empty($plugin_file)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'MISSING_PLUGIN', 'message' => 'Plugin file not specified.']], 400);
        }

        if (!function_exists('delete_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Deactivate first if active
        if (is_plugin_active($plugin_file)) {
            deactivate_plugins($plugin_file);
        }

        $result = delete_plugins([$plugin_file]);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'DELETE_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }

        SAM_Audit_Logger::log('plugin_deleted', 'plugin', $plugin_file, 'Deleted via SimpleAd Manager');

        return $this->success(['plugin' => $plugin_file, 'deleted' => true]);
    }
}
