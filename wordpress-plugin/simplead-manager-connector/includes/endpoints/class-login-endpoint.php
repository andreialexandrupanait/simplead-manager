<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One-time login URL generation endpoint.
 */
class SAM_Login_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/login-url', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generate_login_url'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function generate_login_url(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $user_login = sanitize_text_field($params['user'] ?? '');

        $result = SAM_Login_Handler::generate_token($user_login ?: null);

        if (!$result['success']) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'LOGIN_URL_FAILED', 'message' => $result['error']],
            ], 400);
        }

        SAM_Audit_Logger::log('login_url_generated', 'user', $result['user'], 'One-time login URL generated via SimpleAd Manager');

        return $this->success([
            'login_url' => $result['login_url'],
            'expires'   => $result['expires'],
            'user'      => $result['user'],
        ]);
    }
}
