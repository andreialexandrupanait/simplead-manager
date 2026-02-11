<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rollback endpoint - revert plugins, themes, or core to a previous version.
 */
class SAM_Rollback_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/rollback/(?P<type>plugin|theme|core)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rollback'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'type' => [
                    'required'          => true,
                    'validate_callback' => function ($param) {
                        return in_array($param, ['plugin', 'theme', 'core'], true);
                    },
                ],
            ],
        ]);
    }

    public function rollback(WP_REST_Request $request): WP_REST_Response {
        $type = $request->get_param('type');
        $params = $request->get_json_params();
        $slug = sanitize_text_field($params['slug'] ?? '');
        $version = sanitize_text_field($params['version'] ?? '');

        if (empty($version)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'MISSING_VERSION', 'message' => 'Target version is required.'],
            ], 400);
        }

        @set_time_limit(300);

        return match ($type) {
            'plugin' => $this->rollback_plugin($slug, $version),
            'theme'  => $this->rollback_theme($slug, $version),
            'core'   => $this->rollback_core($version),
            default  => new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'INVALID_TYPE', 'message' => 'Invalid rollback type.'],
            ], 400),
        };
    }

    private function rollback_plugin(string $slug, string $version): WP_REST_Response {
        if (empty($slug)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'MISSING_SLUG', 'message' => 'Plugin slug is required.'],
            ], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Download specific version from WordPress.org
        $download_url = "https://downloads.wordpress.org/plugin/{$slug}.{$version}.zip";

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        // Find the plugin file for this slug
        $plugin_file = $this->find_plugin_file($slug);
        if (!$plugin_file) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'PLUGIN_NOT_FOUND', 'message' => "Plugin '{$slug}' not found."],
            ], 404);
        }

        $was_active = is_plugin_active($plugin_file);

        // Use upgrader with a custom package URL
        add_filter('upgrader_package_options', function ($options) use ($download_url) {
            $options['package'] = $download_url;
            return $options;
        });

        $result = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'ROLLBACK_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }

        // Re-activate if was active
        if ($was_active) {
            activate_plugin($plugin_file);
        }

        SAM_Audit_Logger::log('plugin_rollback', 'plugin', $slug, "Rolled back to version {$version} via SimpleAd Manager");

        return $this->success([
            'type'    => 'plugin',
            'slug'    => $slug,
            'version' => $version,
            'message' => "Plugin rolled back to version {$version}.",
        ]);
    }

    private function rollback_theme(string $slug, string $version): WP_REST_Response {
        if (empty($slug)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'MISSING_SLUG', 'message' => 'Theme slug is required.'],
            ], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $download_url = "https://downloads.wordpress.org/theme/{$slug}.{$version}.zip";

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);

        add_filter('upgrader_package_options', function ($options) use ($download_url) {
            $options['package'] = $download_url;
            return $options;
        });

        $result = $upgrader->upgrade($slug);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'ROLLBACK_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }

        SAM_Audit_Logger::log('theme_rollback', 'theme', $slug, "Rolled back to version {$version} via SimpleAd Manager");

        return $this->success([
            'type'    => 'theme',
            'slug'    => $slug,
            'version' => $version,
            'message' => "Theme rolled back to version {$version}.",
        ]);
    }

    private function rollback_core(string $version): WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $locale = get_locale();
        $download_url = "https://downloads.wordpress.org/release/wordpress-{$version}.zip";

        $old_version = get_bloginfo('version');

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);

        // Create a fake update object for the target version
        $update = (object) [
            'response'   => 'reinstall',
            'download'   => $download_url,
            'current'    => $version,
            'locale'     => $locale,
            'packages'   => (object) [
                'full' => $download_url,
            ],
            'version'    => $version,
        ];

        $result = $upgrader->upgrade($update);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'CORE_ROLLBACK_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }

        SAM_Audit_Logger::log('core_rollback', 'core', $version, "Rolled back from {$old_version} to {$version} via SimpleAd Manager");

        return $this->success([
            'type'        => 'core',
            'version'     => $version,
            'old_version' => $old_version,
            'message'     => "WordPress core rolled back to version {$version}.",
        ]);
    }

    private function find_plugin_file(string $slug): ?string {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach (get_plugins() as $file => $data) {
            $plugin_slug = strpos($file, '/') !== false ? dirname($file) : basename($file, '.php');
            if ($plugin_slug === $slug) {
                return $file;
            }
        }

        return null;
    }
}
