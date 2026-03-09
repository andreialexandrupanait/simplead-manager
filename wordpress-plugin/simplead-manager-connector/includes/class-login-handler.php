<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles one-time login token processing.
 * Tokens are stored as SHA-256 hashes and compared timing-safely.
 */
class SAM_Login_Handler {

    private const TOKEN_EXPIRY = 120; // 2 minutes

    /**
     * Check for a login token in the URL and auto-login if valid.
     */
    public function maybe_handle_login(): void {
        if (empty($_GET['sam_login_token'])) {
            return;
        }

        $token = sanitize_text_field($_GET['sam_login_token']);
        $token_hash = hash('sha256', $token);

        $stored = get_option('sam_login_tokens', []);
        // Clean expired tokens
        $stored = array_filter($stored, function ($data) {
            return $data['expires'] > time();
        });

        // Timing-safe lookup: check all hashes to avoid timing leaks
        $matched_hash = null;
        foreach ($stored as $stored_hash => $data) {
            if (hash_equals($stored_hash, $token_hash)) {
                $matched_hash = $stored_hash;
                break;
            }
        }

        if ($matched_hash === null) {
            update_option('sam_login_tokens', $stored);
            wp_die('Invalid or expired login token.', 'Login Failed', ['response' => 403]);
            return;
        }

        $token_data = $stored[$matched_hash];
        unset($stored[$matched_hash]); // Single-use: remove immediately
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
     * Stores the SHA-256 hash, returns the plaintext token in the URL.
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
        $token_hash = hash('sha256', $token);
        $expires = time() + self::TOKEN_EXPIRY;

        $stored = get_option('sam_login_tokens', []);
        // Clean expired tokens
        $stored = array_filter($stored, function ($data) {
            return $data['expires'] > time();
        });

        // Store hash, not plaintext
        $stored[$token_hash] = [
            'user'    => $user_login,
            'expires' => $expires,
        ];

        update_option('sam_login_tokens', $stored);

        // Return plaintext token in URL (only sent to authenticated Laravel app)
        $login_url = add_query_arg('sam_login_token', $token, home_url('/'));

        return [
            'success'   => true,
            'login_url' => $login_url,
            'expires'   => $expires,
            'user'      => $user_login,
        ];
    }
}
