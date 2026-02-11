<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logs WordPress actions for audit trail.
 */
class SAM_Audit_Logger {

    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sam_audit_logs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(100) NOT NULL,
            object_type VARCHAR(50) DEFAULT NULL,
            object_name VARCHAR(255) DEFAULT NULL,
            user_login VARCHAR(60) DEFAULT NULL,
            user_ip VARCHAR(45) DEFAULT NULL,
            details TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_action (action)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function log(string $action, ?string $object_type = null, ?string $object_name = null, ?string $details = null): void {
        global $wpdb;

        $user = wp_get_current_user();
        $user_login = $user->ID ? $user->user_login : 'system';

        $wpdb->insert(
            $wpdb->prefix . 'sam_audit_logs',
            [
                'action'      => $action,
                'object_type' => $object_type,
                'object_name' => $object_name,
                'user_login'  => $user_login,
                'user_ip'     => self::get_client_ip(),
                'details'     => $details,
                'created_at'  => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public static function get_logs(?string $since = null, int $limit = 500): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sam_audit_logs';

        if ($since) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE created_at > %s ORDER BY created_at DESC LIMIT %d",
                    $since,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
        }

        return $results ?: [];
    }

    /**
     * Register WordPress hooks for automatic audit logging.
     */
    public static function register_hooks(): void {
        // User login/logout
        add_action('wp_login', function ($user_login) {
            self::log('user_login', 'user', $user_login);
        });
        add_action('wp_logout', function () {
            $user = wp_get_current_user();
            if ($user->ID) {
                self::log('user_logout', 'user', $user->user_login);
            }
        });

        // Post changes
        add_action('transition_post_status', function ($new, $old, $post) {
            if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
                return;
            }
            if ($new !== $old) {
                self::log(
                    'post_status_change',
                    'post',
                    $post->post_title,
                    "Status changed from {$old} to {$new} (ID: {$post->ID})"
                );
            }
        }, 10, 3);

        // Plugin activation/deactivation
        add_action('activated_plugin', function ($plugin) {
            self::log('plugin_activated', 'plugin', $plugin);
        });
        add_action('deactivated_plugin', function ($plugin) {
            self::log('plugin_deactivated', 'plugin', $plugin);
        });

        // Theme switch
        add_action('switch_theme', function ($new_name) {
            self::log('theme_switched', 'theme', $new_name);
        });

        // User created/deleted
        add_action('user_register', function ($user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                self::log('user_created', 'user', $user->user_login);
            }
        });
        add_action('delete_user', function ($user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                self::log('user_deleted', 'user', $user->user_login);
            }
        });

        // Option changes (settings)
        add_action('updated_option', function ($option) {
            $tracked = ['blogname', 'blogdescription', 'siteurl', 'home', 'permalink_structure', 'default_role'];
            if (in_array($option, $tracked, true)) {
                self::log('option_updated', 'option', $option);
            }
        });

        // Core updates
        add_action('_core_updated_successfully', function ($version) {
            self::log('core_updated', 'core', $version);
        });
    }

    private static function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For may contain multiple IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
