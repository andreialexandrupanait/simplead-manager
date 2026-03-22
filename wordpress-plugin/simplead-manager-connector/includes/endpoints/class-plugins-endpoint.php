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

        register_rest_route(SAM_REST_NAMESPACE, '/flush-opcache', [
            'methods'             => 'POST',
            'callback'            => [$this, 'flush_opcache'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Read plugin version directly from the file header, bypassing all WP/PHP caches.
     */
    private function read_fresh_version(string $plugin_file): ?string {
        $path = WP_PLUGIN_DIR . '/' . $plugin_file;
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        if (!is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path, false, null, 0, 8192);
        if (!$content) {
            return null;
        }
        if (preg_match('/^[ \t\/*#@]*Version:\s*(.+?)$/mi', $content, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Validate a plugin file path — no traversal, must exist in WP_PLUGIN_DIR.
     */
    private function validate_plugin_path(string $plugin_file): bool {
        // Reject path traversal
        if (strpos($plugin_file, '..') !== false) {
            return false;
        }

        // Reject absolute paths
        if ($plugin_file[0] === '/' || $plugin_file[0] === '\\') {
            return false;
        }

        // Reject null bytes
        if (strpos($plugin_file, "\0") !== false) {
            return false;
        }

        // Clear cache and check against fresh plugin list
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        wp_cache_delete('plugins', 'plugins');
        $all_plugins = get_plugins();
        return isset($all_plugins[$plugin_file]);
    }

    public function list_plugins(WP_REST_Request $request): WP_REST_Response {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Clear plugin cache to get fresh filesystem scan
        wp_cache_delete('plugins', 'plugins');
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        // Force refresh so transient reflects actual available updates
        delete_site_transient('update_plugins');
        wp_update_plugins();
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

        // Verify versions from actual files — bypass PHP/WP cache (OPcache, object cache)
        foreach ($plugins as &$plugin) {
            $fresh = $this->read_fresh_version($plugin['file']);
            if ($fresh) {
                $plugin['version'] = $fresh;
                if ($plugin['update_available'] && $plugin['new_version']
                    && version_compare($fresh, $plugin['new_version'], '>=')) {
                    $plugin['update_available'] = false;
                    $plugin['new_version'] = null;
                }
            }
        }
        unset($plugin);

        return $this->success(['plugins' => $plugins]);
    }

    public function update_plugins(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $plugin_files = $params['plugins'] ?? [];

        if (empty($plugin_files)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'MISSING_PLUGINS', 'message' => 'No plugins specified for update.']], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Refresh update transient so upgrader sees available updates
        delete_site_transient('update_plugins');
        wp_update_plugins();
        $update_transient = get_site_transient('update_plugins');

        // Initialize filesystem (required by Plugin_Upgrader)
        WP_Filesystem();

        // Use silent skin to suppress output
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        // Remember which plugins were active before upgrading,
        // because Plugin_Upgrader temporarily deactivates them during the process.
        $active_before = get_option('active_plugins', []);

        // Capture versions before upgrade for accurate reporting
        wp_cache_delete('plugins', 'plugins');
        $all_plugins_before = get_plugins();

        $results = [];
        foreach ($plugin_files as $plugin_file) {
            $plugin_file = sanitize_text_field($plugin_file);

            // Validate path before upgrading
            if (!$this->validate_plugin_path($plugin_file)) {
                $results[$plugin_file] = [
                    'success' => false,
                    'error'   => 'Invalid plugin path.',
                ];
                continue;
            }

            $old_version = $this->read_fresh_version($plugin_file) ?? $all_plugins_before[$plugin_file]['Version'] ?? null;
            $was_active = in_array($plugin_file, $active_before, true);

            $result = $upgrader->upgrade($plugin_file);

            $success = ($result === true || $result === null);
            $error = null;

            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            } elseif ($result === false) {
                $feedback = $skin->get_upgrade_messages();
                $error = !empty($feedback) ? implode(' | ', $feedback) : 'Upgrade returned false (no details available)';
            }

            // Read actual current version after upgrade attempt (direct from file)
            $current_version = $this->read_fresh_version($plugin_file);

            // Detect "already up to date": upgrade failed but plugin is already at expected version
            if (!$success && $current_version !== null) {
                $expected_version = null;
                $has_pending_update = isset($update_transient->response[$plugin_file]);
                if ($has_pending_update) {
                    $expected_version = $update_transient->response[$plugin_file]->new_version ?? null;
                }

                $already_current = ($expected_version && version_compare($current_version, $expected_version, '>='))
                    || ($old_version !== null && $old_version !== $current_version)
                    || !$has_pending_update;

                if ($already_current) {
                    $success = true;
                    $error = null;
                }
            }

            // Re-activate the plugin if it was active before the upgrade
            if ($success && $was_active && !is_plugin_active($plugin_file)) {
                activate_plugin($plugin_file);
            }

            $results[$plugin_file] = [
                'success'      => $success,
                'error'        => $error,
                'from_version' => $old_version,
                'to_version'   => $current_version,
            ];

            if ($success) {
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

        if (!$this->validate_plugin_path($plugin_file)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'INVALID_PATH', 'message' => 'Invalid plugin path.']], 400);
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

        if (!$this->validate_plugin_path($plugin_file)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'INVALID_PATH', 'message' => 'Invalid plugin path.']], 400);
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

        if (!$this->validate_plugin_path($plugin_file)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'INVALID_PATH', 'message' => 'Invalid plugin path.']], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Initialize WP_Filesystem (required by delete_plugins)
        WP_Filesystem();

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

    public function flush_opcache(): WP_REST_Response {
        $cleared = 0;

        // Invalidate all connector PHP files individually
        $connector_dir = WP_PLUGIN_DIR . '/simplead-manager-connector/';
        $patterns = ['*.php', 'includes/*.php', 'includes/endpoints/*.php'];
        foreach ($patterns as $pattern) {
            foreach (glob($connector_dir . $pattern) ?: [] as $f) {
                @touch($f);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($f, true);
                }
                $cleared++;
            }
        }

        // Also invalidate MU-plugin
        $mu = WPMU_PLUGIN_DIR . '/simplead-security.php';
        if (file_exists($mu)) {
            @touch($mu);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($mu, true);
            }
        }

        // Global reset as fallback
        clearstatcache(true);
        wp_cache_delete('plugins', 'plugins');
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return $this->success(['cleared_files' => $cleared]);
    }
}
