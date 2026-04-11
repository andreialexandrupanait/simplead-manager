<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /rotate-keys endpoint — rotates the API key and secret.
 */
class SAM_Key_Rotation_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/rotate-keys', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rotate_keys'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function rotate_keys(WP_REST_Request $request): WP_REST_Response {
        $new_key = bin2hex(random_bytes(16));
        $new_secret = bin2hex(random_bytes(32));

        $old_key = get_option('sam_api_key', '');

        update_option('sam_api_key', $new_key);
        update_option('sam_api_secret', $new_secret);

        SAM_Audit_Logger::log(
            'key_rotation',
            'api_credentials',
            'sam_api_key',
            'API credentials rotated via manager'
        );

        return $this->success([
            'new_key' => $new_key,
            'new_secret' => $new_secret,
            'message' => 'API credentials rotated successfully.',
        ]);
    }
}
