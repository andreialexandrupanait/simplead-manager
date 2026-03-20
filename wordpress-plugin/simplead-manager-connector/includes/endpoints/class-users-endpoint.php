<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /users endpoint - List, create, update, and delete WordPress users.
 */
class SAM_Users_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/users', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_users'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/users/create', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_user'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/users/update', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_user'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/users/delete', [
            'methods'             => 'POST',
            'callback'            => [$this, 'delete_user'],
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

    public function create_user(WP_REST_Request $request): WP_REST_Response {
        $username     = sanitize_user($request->get_param('username') ?? '');
        $email        = sanitize_email($request->get_param('email') ?? '');
        $password     = $request->get_param('password') ?? '';
        $role         = sanitize_text_field($request->get_param('role') ?? 'subscriber');
        $display_name = sanitize_text_field($request->get_param('display_name') ?? '');

        if (empty($username) || empty($email) || empty($password)) {
            return $this->error('Username, email, and password are required.', 400);
        }

        if (!is_email($email)) {
            return $this->error('Invalid email address.', 400);
        }

        // Validate role against available roles
        $valid_roles = array_keys(wp_roles()->get_names());
        if (!in_array($role, $valid_roles, true)) {
            return $this->error('Invalid role: ' . $role, 400);
        }

        if (username_exists($username)) {
            return $this->error('Username already exists.', 409);
        }

        if (email_exists($email)) {
            return $this->error('Email already exists.', 409);
        }

        $user_data = [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'role'         => $role,
        ];

        if (!empty($display_name)) {
            $user_data['display_name'] = $display_name;
        }

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            return $this->error($user_id->get_error_message(), 500);
        }

        SAM_Audit_Logger::log('user_created', 'user', $username, "Created user {$username} with role {$role} via SimpleAd Manager");

        return $this->success([
            'user_id'  => $user_id,
            'username' => $username,
            'message'  => 'User created successfully.',
        ]);
    }

    public function update_user(WP_REST_Request $request): WP_REST_Response {
        $wp_user_id   = (int) $request->get_param('wp_user_id');
        $email        = $request->get_param('email');
        $role         = $request->get_param('role');
        $display_name = $request->get_param('display_name');

        if (!$wp_user_id) {
            return $this->error('wp_user_id is required.', 400);
        }

        $user = get_userdata($wp_user_id);
        if (!$user) {
            return $this->error('User not found.', 404);
        }

        $user_data = ['ID' => $wp_user_id];
        $changes = [];

        if ($email !== null) {
            $email = sanitize_email($email);
            if (!is_email($email)) {
                return $this->error('Invalid email address.', 400);
            }
            $existing = email_exists($email);
            if ($existing && $existing !== $wp_user_id) {
                return $this->error('Email already in use by another user.', 409);
            }
            $user_data['user_email'] = $email;
            $changes[] = 'email';
        }

        if ($display_name !== null) {
            $user_data['display_name'] = sanitize_text_field($display_name);
            $changes[] = 'display_name';
        }

        if ($role !== null) {
            $role = sanitize_text_field($role);
            $valid_roles = array_keys(wp_roles()->get_names());
            if (!in_array($role, $valid_roles, true)) {
                return $this->error('Invalid role: ' . $role, 400);
            }
            $user_data['role'] = $role;
            $changes[] = 'role';
        }

        if (empty($changes)) {
            return $this->error('No fields to update.', 400);
        }

        $result = wp_update_user($user_data);

        if (is_wp_error($result)) {
            return $this->error($result->get_error_message(), 500);
        }

        SAM_Audit_Logger::log('user_updated', 'user', $user->user_login, 'Updated ' . implode(', ', $changes) . ' via SimpleAd Manager');

        return $this->success([
            'user_id' => $wp_user_id,
            'updated' => $changes,
            'message' => 'User updated successfully.',
        ]);
    }

    public function delete_user(WP_REST_Request $request): WP_REST_Response {
        $wp_user_id  = (int) $request->get_param('wp_user_id');
        $reassign_to = $request->get_param('reassign_to');

        if (!$wp_user_id) {
            return $this->error('wp_user_id is required.', 400);
        }

        $user = get_userdata($wp_user_id);
        if (!$user) {
            return $this->error('User not found.', 404);
        }

        // Prevent deleting the last administrator
        if (in_array('administrator', $user->roles, true)) {
            $admin_count = count(get_users(['role' => 'administrator', 'fields' => 'ID']));
            if ($admin_count <= 1) {
                return $this->error('Cannot delete the last administrator.', 403);
            }
        }

        // Validate reassign target if provided
        $reassign = null;
        if ($reassign_to) {
            $reassign = (int) $reassign_to;
            if ($reassign === $wp_user_id) {
                return $this->error('Cannot reassign content to the user being deleted.', 400);
            }
            if (!get_userdata($reassign)) {
                return $this->error('Reassign target user not found.', 404);
            }
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        $username = $user->user_login;
        $result = wp_delete_user($wp_user_id, $reassign);

        if (!$result) {
            return $this->error('Failed to delete user.', 500);
        }

        SAM_Audit_Logger::log('user_deleted', 'user', $username, "Deleted user {$username} via SimpleAd Manager");

        return $this->success([
            'deleted'  => true,
            'username' => $username,
            'message'  => 'User deleted successfully.',
        ]);
    }
}
