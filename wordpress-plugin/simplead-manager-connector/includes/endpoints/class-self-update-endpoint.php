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
        $download_url   = $params['download_url']   ?? '';
        $expected_hash  = $params['expected_hash']  ?? '';

        if (empty($download_url) || !filter_var($download_url, FILTER_VALIDATE_URL)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'INVALID_URL', 'message' => 'A valid download_url is required.'],
            ], 400);
        }

        // If a hash was supplied, download the zip manually, verify, then pass the
        // local temp file path to the upgrader so it doesn't re-download.
        $local_package = null;
        if (!empty($expected_hash)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $tmp = download_url($download_url, 120);
            if (is_wp_error($tmp)) {
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => ['code' => 'DOWNLOAD_FAILED', 'message' => $tmp->get_error_message()],
                ], 500);
            }

            $actual_hash = hash_file('sha256', $tmp);
            if (!hash_equals($expected_hash, $actual_hash)) {
                @unlink($tmp);
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => ['code' => 'HASH_MISMATCH', 'message' => 'Package integrity check failed.'],
                ], 400);
            }

            $local_package = $tmp;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();

        $plugin_file = 'simplead-manager-connector/simplead-manager-connector.php';

        // Get current version before update
        $all_plugins = get_plugins();
        $old_version = $all_plugins[$plugin_file]['Version'] ?? 'unknown';

        // Backup current plugin folder for rollback
        $connector_dir = WP_PLUGIN_DIR . '/simplead-manager-connector';
        $backup_dir = WP_PLUGIN_DIR . '/simplead-manager-connector-rollback';
        $rollback_available = false;

        if (is_dir($connector_dir)) {
            // Remove any stale rollback from previous update
            if (is_dir($backup_dir)) {
                $this->recursive_delete($backup_dir);
            }
            $rollback_available = $this->recursive_copy($connector_dir, $backup_dir);
        }

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        // Override the package URL: use the pre-downloaded + verified local file when
        // available, otherwise fall back to streaming directly from the URL.
        $package_source = $local_package ?? $download_url;
        add_filter('upgrader_package_options', function ($options) use ($package_source) {
            $options['package'] = $package_source;
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

        // Clean up our manually downloaded temp file (if used)
        if ($local_package !== null && file_exists($local_package)) {
            @unlink($local_package);
        }

        // Clean up the fake transient entry
        delete_site_transient('update_plugins');
        wp_update_plugins();

        if (is_wp_error($result) || $result === false) {
            $error_msg = is_wp_error($result)
                ? $result->get_error_message()
                : 'Update failed. ' . implode(' ', $skin->get_upgrade_messages());

            // Attempt rollback
            if ($rollback_available && is_dir($backup_dir)) {
                $this->recursive_delete($connector_dir);
                rename($backup_dir, $connector_dir);
                if (!is_plugin_active($plugin_file)) {
                    activate_plugin($plugin_file);
                }
                $error_msg .= ' (rolled back to previous version)';
            }

            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'UPDATE_FAILED', 'message' => $error_msg],
            ], 500);
        }

        // Clean up rollback backup on success
        if (is_dir($backup_dir)) {
            $this->recursive_delete($backup_dir);
        }

        // Ensure plugin is still active
        if (!is_plugin_active($plugin_file)) {
            activate_plugin($plugin_file);
        }

        // Aggressively clear OPcache for all connector files
        $connector_dir = plugin_dir_path(dirname(__FILE__));
        $patterns = [$connector_dir . '*.php', $connector_dir . 'includes/*.php', $connector_dir . 'includes/endpoints/*.php'];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $f) {
                @touch($f);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($f, true);
                }
            }
        }
        clearstatcache(true);
        wp_cache_delete('plugins', 'plugins');
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Read new version directly from file (bypass OPcache)
        $new_version = 'unknown';
        $main_file = WP_PLUGIN_DIR . '/' . $plugin_file;
        $content = @file_get_contents($main_file, false, null, 0, 8192);
        if ($content && preg_match('/^[ \t\/*#@]*Version:\s*(.+?)$/mi', $content, $m)) {
            $new_version = trim($m[1]);
        }

        // Store version in DB option (survives OPcache issues)
        update_option('sam_connector_version', $new_version, true);

        // Create flag file for MU-plugin OPcache invalidation on next request
        @file_put_contents($connector_dir . '.opcache-invalidate', (string) time());

        // Regenerate MU-plugin so it picks up new code
        if (class_exists('SAM_MU_Plugin_Manager')) {
            SAM_MU_Plugin_Manager::install();
        }

        SAM_Audit_Logger::log('self_update', 'plugin', 'simplead-manager-connector', "Updated from {$old_version} to {$new_version} via SimpleAd Manager");

        return $this->success([
            'old_version' => $old_version,
            'new_version' => $new_version,
            'message'     => "Plugin updated from {$old_version} to {$new_version}.",
        ]);
    }

    private function recursive_copy(string $src, string $dst): bool {
        if (!@mkdir($dst, 0755, true)) {
            return false;
        }
        $dir = opendir($src);
        if (!$dir) {
            return false;
        }
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            if (is_dir($srcPath)) {
                if (!$this->recursive_copy($srcPath, $dstPath)) {
                    closedir($dir);
                    return false;
                }
            } else {
                if (!@copy($srcPath, $dstPath)) {
                    closedir($dir);
                    return false;
                }
            }
        }
        closedir($dir);
        return true;
    }

    private function recursive_delete(string $dir): void {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursive_delete($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
