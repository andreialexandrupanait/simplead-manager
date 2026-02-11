<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /users endpoint - List WordPress users.
 */
class SAM_Users_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/users', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_users'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function list_users(WP_REST_Request $request): WP_REST_Response {
        $wp_users = get_users([
            'orderby' => 'registered',
            'order'   => 'ASC',
        ]);

        $users = [];
        foreach ($wp_users as $user) {
            $users[] = [
                'id'           => $user->ID,
                'login'        => $user->user_login,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'roles'        => array_values($user->roles),
                'registered'   => $user->user_registered,
                'last_login'   => get_user_meta($user->ID, 'last_login', true) ?: null,
            ];
        }

        return $this->success(['users' => $users]);
    }
}
