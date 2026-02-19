<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logs all incoming REST API requests to the simplead/v1 namespace.
 * Uses batch-insert-on-shutdown pattern (same as SAM_Audit_Logger).
 */
class SAM_Request_Logger {

    private static array $pending = [];
    private static bool $shutdown_registered = false;

    public static function create_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'sam_api_request_log';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            method VARCHAR(10) NOT NULL,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            api_key_hash VARCHAR(8) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_ip (ip)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Queue a request for logging. Flushed on shutdown.
     */
    public static function log(string $ip, string $endpoint, string $method, int $status_code, ?string $api_key = null, ?string $user_agent = null): void {
        self::$pending[] = [
            'ip'           => $ip,
            'endpoint'     => substr($endpoint, 0, 255),
            'method'       => strtoupper(substr($method, 0, 10)),
            'status_code'  => $status_code,
            'api_key_hash' => $api_key ? substr(hash('sha256', $api_key), 0, 8) : null,
            'user_agent'   => $user_agent ? substr($user_agent, 0, 255) : null,
            'created_at'   => current_time('mysql', true),
        ];

        if (!self::$shutdown_registered) {
            self::$shutdown_registered = true;
            add_action('shutdown', [__CLASS__, 'flush']);
        }
    }

    /**
     * Flush all pending log entries to the database in a single batch.
     */
    public static function flush(): void {
        if (empty(self::$pending)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sam_api_request_log';

        $columns = '(ip, endpoint, method, status_code, api_key_hash, user_agent, created_at)';
        $placeholders = [];
        $values = [];

        foreach (self::$pending as $entry) {
            $placeholders[] = '(%s, %s, %s, %d, %s, %s, %s)';
            $values[] = $entry['ip'];
            $values[] = $entry['endpoint'];
            $values[] = $entry['method'];
            $values[] = $entry['status_code'];
            $values[] = $entry['api_key_hash'];
            $values[] = $entry['user_agent'];
            $values[] = $entry['created_at'];
        }

        $sql = "INSERT INTO {$table} {$columns} VALUES " . implode(', ', $placeholders);
        $wpdb->query($wpdb->prepare($sql, $values));

        self::$pending = [];

        // Auto-purge entries older than 30 days (once per day via transient)
        if (!get_transient('sam_request_log_purged')) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE created_at < %s",
                    gmdate('Y-m-d H:i:s', strtotime('-30 days'))
                )
            );
            set_transient('sam_request_log_purged', 1, DAY_IN_SECONDS);
        }
    }

    /**
     * Get recent log entries for admin display.
     */
    public static function get_recent(int $limit = 20): array {
        global $wpdb;
        $table = $wpdb->prefix . 'sam_api_request_log';

        // Check if table exists before querying
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table)
        );

        if (!$table_exists) {
            return [];
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
                $limit
            )
        );

        return $results ?: [];
    }

    /**
     * Get the client IP from proxy headers.
     */
    public static function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
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
