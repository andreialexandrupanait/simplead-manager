<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress core management endpoints.
 */
class SAM_Core_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_core'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function update_core(WP_REST_Request $request): WP_REST_Response {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Get the latest core update info
        wp_version_check();
        $updates = get_site_transient('update_core');

        if (empty($updates->updates)) {
            return $this->success(['message' => 'WordPress is already up to date.', 'updated' => false]);
        }

        $update = null;
        foreach ($updates->updates as $u) {
            if ($u->response === 'upgrade') {
                $update = $u;
                break;
            }
        }

        if (!$update) {
            return $this->success(['message' => 'No core update available.', 'updated' => false]);
        }

        $old_version = get_bloginfo('version');

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);
        $result = $upgrader->upgrade($update);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'CORE_UPDATE_FAILED', 'message' => $result->get_error_message()],
            ], 500);
        }

        SAM_Audit_Logger::log('core_updated', 'core', $update->current, "Updated from {$old_version} to {$update->current} via SimpleAd Manager");

        return $this->success([
            'updated'      => true,
            'old_version'  => $old_version,
            'new_version'  => $update->current,
        ]);
    }
}
