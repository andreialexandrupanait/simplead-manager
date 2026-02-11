<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles one-time login token processing.
 */
class SAM_Login_Handler {

    /**
     * Check for a login token in the URL and auto-login if valid.
     */
    public function maybe_handle_login(): void {
        if (empty($_GET['sam_login_token'])) {
            return;
        }

        $token = sanitize_text_field($_GET['sam_login_token']);

        $stored = get_option('sam_login_tokens', []);
        // Clean expired tokens
        $stored = array_filter($stored, function ($data) {
            return $data['expires'] > time();
        });

        if (!isset($stored[$token])) {
            update_option('sam_login_tokens', $stored);
            wp_die('Invalid or expired login token.', 'Login Failed', ['response' => 403]);
            return;
        }

        $token_data = $stored[$token];
        unset($stored[$token]); // Single-use: remove immediately
        update_option('sam_login_tokens', $stored);

        $user = get_user_by('login', $token_data['user']);
        if (!$user) {
            wp_die('User not found.', 'Login Failed', ['response' => 404]);
            return;
        }

        // Ensure user has admin capabilities
        if (!user_can($user, 'manage_options')) {
            wp_die('User does not have sufficient permissions.', 'Login Failed', ['response' => 403]);
            return;
        }

        wp_set_auth_cookie($user->ID, true);
        wp_set_current_user($user->ID);

        SAM_Audit_Logger::log('auto_login', 'user', $user->user_login, 'One-time login via SimpleAd Manager');

        wp_safe_redirect(admin_url());
        exit;
    }

    /**
     * Generate a one-time login token.
     */
    public static function generate_token(?string $user_login = null): array {
        if (!$user_login) {
            // Default to first administrator
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
            if (empty($admins)) {
                return ['success' => false, 'error' => 'No administrator found'];
            }
            $user_login = $admins[0]->user_login;
        }

        $user = get_user_by('login', $user_login);
        if (!$user || !user_can($user, 'manage_options')) {
            return ['success' => false, 'error' => 'Invalid user or insufficient permissions'];
        }

        $token = bin2hex(random_bytes(32));
        $expires = time() + 300; // 5 minutes

        $stored = get_option('sam_login_tokens', []);
        // Clean expired tokens
        $stored = array_filter($stored, function ($data) {
            return $data['expires'] > time();
        });

        $stored[$token] = [
            'user'    => $user_login,
            'expires' => $expires,
        ];

        update_option('sam_login_tokens', $stored);

        $login_url = add_query_arg('sam_login_token', $token, home_url('/'));

        return [
            'success'   => true,
            'login_url' => $login_url,
            'expires'   => $expires,
            'user'      => $user_login,
        ];
    }
}
