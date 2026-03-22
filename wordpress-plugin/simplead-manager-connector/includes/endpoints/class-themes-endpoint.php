<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Theme management endpoints.
 */
class SAM_Themes_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/themes', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_themes'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/themes/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_themes'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/themes/activate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'activate_theme'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/themes/delete', [
            'methods'             => 'POST',
            'callback'            => [$this, 'delete_theme'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Read theme version directly from style.css header, bypassing all WP/PHP caches.
     */
    private function read_fresh_theme_version(string $slug): ?string {
        $path = get_theme_root() . '/' . $slug . '/style.css';
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

    public function list_themes(WP_REST_Request $request): WP_REST_Response {
        $all_themes = wp_get_themes();
        $active_theme = get_stylesheet();

        // Force refresh so transient reflects actual available updates
        delete_site_transient('update_themes');
        wp_update_themes();
        $update_themes = get_site_transient('update_themes');

        $themes = [];
        foreach ($all_themes as $slug => $theme) {
            $update_available = false;
            $new_version = null;
            if ($update_themes && isset($update_themes->response[$slug])) {
                $update_available = true;
                $new_version = $update_themes->response[$slug]['new_version'] ?? null;
            }

            $themes[] = [
                'slug'             => $slug,
                'name'             => $theme->get('Name'),
                'version'          => $theme->get('Version'),
                'status'           => ($slug === $active_theme) ? 'active' : 'inactive',
                'update_available' => $update_available,
                'new_version'      => $new_version,
                'author'           => $theme->get('Author'),
                'description'      => $theme->get('Description'),
                'template'         => $theme->get_template(),
                'parent_theme'     => $theme->parent() ? $theme->parent()->get('Name') : null,
            ];
        }

        // Verify versions from actual files — bypass PHP/WP cache (OPcache, object cache)
        foreach ($themes as &$theme) {
            $fresh = $this->read_fresh_theme_version($theme['slug']);
            if ($fresh) {
                $theme['version'] = $fresh;
                if ($theme['update_available'] && $theme['new_version']
                    && version_compare($fresh, $theme['new_version'], '>=')) {
                    $theme['update_available'] = false;
                    $theme['new_version'] = null;
                }
            }
        }
        unset($theme);

        return $this->success(['themes' => $themes]);
    }

    public function update_themes(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $theme_slugs = $params['themes'] ?? [];

        if (empty($theme_slugs)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'MISSING_THEMES', 'message' => 'No themes specified for update.']], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Refresh update transient so upgrader sees available updates
        delete_site_transient('update_themes');
        wp_update_themes();
        $update_transient = get_site_transient('update_themes');

        // Initialize filesystem (required by Theme_Upgrader)
        WP_Filesystem();

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);

        // Capture versions before upgrade (direct from file for accuracy)
        $old_versions = [];
        foreach ($theme_slugs as $s) {
            $s = sanitize_text_field($s);
            $old_versions[$s] = $this->read_fresh_theme_version($s);
        }

        $results = [];
        foreach ($theme_slugs as $slug) {
            $slug = sanitize_text_field($slug);
            $old_version = $old_versions[$slug] ?? null;

            $result = $upgrader->upgrade($slug);

            $success = ($result === true || $result === null);
            $error = null;

            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            } elseif ($result === false) {
                // Upgrade returned false — capture skin feedback for diagnostics
                $feedback = $skin->get_upgrade_messages();
                $error = !empty($feedback) ? implode(' | ', $feedback) : 'Upgrade returned false (no details available)';
            }

            // Read actual current version after upgrade attempt (direct from file)
            $current_version = $this->read_fresh_theme_version($slug);

            // Detect "already up to date": upgrade failed but theme is already at expected version
            if (!$success && $current_version !== null) {
                $expected_version = null;
                $has_pending_update = isset($update_transient->response[$slug]);
                if ($has_pending_update) {
                    $expected_version = $update_transient->response[$slug]['new_version'] ?? null;
                }

                $already_current = ($expected_version && version_compare($current_version, $expected_version, '>='))
                    || ($old_version !== null && $old_version !== $current_version)
                    || !$has_pending_update;

                if ($already_current) {
                    $success = true;
                    $error = null;
                }
            }

            $results[$slug] = [
                'success'      => $success,
                'error'        => $error,
                'from_version' => $old_version,
                'to_version'   => $current_version,
            ];

            if ($success) {
                SAM_Audit_Logger::log('theme_updated', 'theme', $slug, 'Updated via SimpleAd Manager');
            }
        }

        delete_site_transient('update_themes');
        wp_update_themes();

        return $this->success(['results' => $results]);
    }

    public function activate_theme(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $theme_slug = sanitize_text_field($params['theme'] ?? '');

        if (empty($theme_slug)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'MISSING_THEME', 'message' => 'Theme slug not specified.']], 400);
        }

        $theme = wp_get_theme($theme_slug);
        if (!$theme->exists()) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'THEME_NOT_FOUND', 'message' => 'Theme not found.']], 404);
        }

        switch_theme($theme_slug);

        SAM_Audit_Logger::log('theme_activated', 'theme', $theme_slug, 'Activated via SimpleAd Manager');

        return $this->success(['theme' => $theme_slug, 'status' => 'active']);
    }

    public function delete_theme(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $theme_slug = sanitize_text_field($params['theme'] ?? '');

        if (empty($theme_slug)) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'MISSING_THEME', 'message' => 'Theme slug not specified.']], 400);
        }

        if ($theme_slug === get_stylesheet()) {
            return new WP_REST_Response(['success' => false, 'error' => ['code' => 'CANNOT_DELETE_ACTIVE', 'message' => 'Cannot delete the active theme.']], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        $result = delete_theme($theme_slug);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'DELETE_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }

        SAM_Audit_Logger::log('theme_deleted', 'theme', $theme_slug, 'Deleted via SimpleAd Manager');

        return $this->success(['theme' => $theme_slug, 'deleted' => true]);
    }
}
