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

        switch ($type) {
            case 'plugin':
                return $this->rollback_plugin($slug, $version);
            case 'theme':
                return $this->rollback_theme($slug, $version);
            case 'core':
                return $this->rollback_core($version);
            default:
                return new WP_REST_Response([
                    'success' => false,
                    'error'   => ['code' => 'INVALID_TYPE', 'message' => 'Invalid rollback type.'],
                ], 400);
        }
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
        require_once dirname(__DIR__) . '/support/class-rollback-version.php';

        // Find the plugin file for this slug.
        $plugin_file = $this->find_plugin_file($slug);
        if (!$plugin_file) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'PLUGIN_NOT_FOUND', 'message' => "Plugin '{$slug}' not found."],
            ], 404);
        }

        // The EXACT pinned version from wp.org — never "whatever is latest".
        $download_url = SAM_Rollback_Version::plugin_download_url($slug, $version);

        // E2E Bug 2: if the pinned version is no longer hosted on wp.org, FAIL
        // EXPLICITLY. Previously the upgrader silently fell back to the latest
        // release, so a "rollback" left the plugin on the version we tried to undo.
        if (!SAM_Rollback_Version::remote_version_available($download_url)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => [
                    'code'    => 'VERSION_UNAVAILABLE',
                    'message' => "Version {$version} of '{$slug}' is no longer downloadable from wordpress.org; "
                        . 'refusing to install a different version. Restore from a full backup instead.',
                ],
            ], 409);
        }

        $was_active = is_plugin_active($plugin_file);

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        // Install the EXACT package directly (clear_destination overwrites the
        // current, wrong version). We do NOT use Plugin_Upgrader::upgrade(): it
        // keys off the update transient and, when the plugin is already at the
        // latest, either no-ops or reinstalls latest — the root of Bug 2.
        $result = $upgrader->run([
            'package'                     => $download_url,
            'destination'                 => WP_PLUGIN_DIR,
            'clear_destination'           => true,
            'clear_working'               => true,
            'abort_if_destination_exists' => false,
            'hook_extra'                  => [
                'plugin' => $plugin_file,
                'type'   => 'plugin',
                'action' => 'update',
            ],
        ]);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'ROLLBACK_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }
        if ($result === false || $result === null) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'ROLLBACK_FAILED', 'message' => "Rollback of '{$slug}' to {$version} did not complete."],
            ], 500);
        }

        // Verify the version actually on disk now is the one we asked for — never
        // report success while the plugin sits on a different version (Bug 2).
        wp_clean_plugins_cache(false);
        $installed = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
        $installed_version = isset($installed['Version']) ? (string) $installed['Version'] : '';
        if ($installed_version !== (string) $version) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => [
                    'code'    => 'ROLLBACK_VERSION_MISMATCH',
                    'message' => "Rollback of '{$slug}' installed version {$installed_version}, expected {$version}.",
                ],
            ], 500);
        }

        // Re-activate if was active.
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
        require_once dirname(__DIR__) . '/support/class-rollback-version.php';

        // The theme must actually be installed before we can roll it back.
        $theme = wp_get_theme($slug);
        if (!$theme->exists()) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'THEME_NOT_FOUND', 'message' => "Theme '{$slug}' not found."],
            ], 404);
        }

        // The EXACT pinned version from wp.org — never "whatever is latest".
        $download_url = SAM_Rollback_Version::theme_download_url($slug, $version);

        // E2E Bug 2: if the pinned version is no longer hosted on wp.org, FAIL
        // EXPLICITLY instead of letting the upgrader fall back to the latest
        // release — a broken theme rollback is worse than a plugin one.
        if (!SAM_Rollback_Version::remote_version_available($download_url)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => [
                    'code'    => 'VERSION_UNAVAILABLE',
                    'message' => "Version {$version} of theme '{$slug}' is no longer downloadable from wordpress.org; "
                        . 'refusing to install a different version. Restore from a full backup instead.',
                ],
            ], 409);
        }

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);

        // Install the EXACT package directly (clear_destination overwrites the
        // current, wrong version). We do NOT use Theme_Upgrader::upgrade(): like
        // the plugin path it keys off the update transient and reinstalls latest
        // when the theme is already current — the root of Bug 2.
        $result = $upgrader->run([
            'package'                     => $download_url,
            'destination'                 => get_theme_root($slug),
            'clear_destination'           => true,
            'clear_working'               => true,
            'abort_if_destination_exists' => false,
            'hook_extra'                  => [
                'theme'  => $slug,
                'type'   => 'theme',
                'action' => 'update',
            ],
        ]);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'ROLLBACK_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }
        if ($result === false || $result === null) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'ROLLBACK_FAILED', 'message' => "Rollback of theme '{$slug}' to {$version} did not complete."],
            ], 500);
        }

        // Verify the version actually on disk now is the one we asked for — never
        // report success while the theme sits on a different version (Bug 2).
        wp_clean_themes_cache();
        $installed_version = (string) wp_get_theme($slug)->get('Version');
        if ($installed_version !== (string) $version) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => [
                    'code'    => 'ROLLBACK_VERSION_MISMATCH',
                    'message' => "Rollback of theme '{$slug}' installed version {$installed_version}, expected {$version}.",
                ],
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
