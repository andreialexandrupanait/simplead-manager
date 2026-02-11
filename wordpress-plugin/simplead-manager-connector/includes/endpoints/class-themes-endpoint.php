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

    public function list_themes(WP_REST_Request $request): WP_REST_Response {
        $all_themes = wp_get_themes();
        $active_theme = get_stylesheet();
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

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);

        $results = [];
        foreach ($theme_slugs as $slug) {
            $slug = sanitize_text_field($slug);
            $result = $upgrader->upgrade($slug);

            $results[$slug] = [
                'success' => ($result === true || $result === null),
                'error'   => is_wp_error($result) ? $result->get_error_message() : null,
            ];

            if ($result === true || $result === null) {
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
