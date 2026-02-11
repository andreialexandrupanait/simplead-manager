<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * IP firewall management endpoints.
 */
class SAM_Firewall_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/ip-rules/sync', [
            'methods'             => 'POST',
            'callback'            => [$this, 'sync_ip_rules'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/blocked-requests', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_blocked_requests'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $rules_table = $wpdb->prefix . 'sam_ip_rules';
        $blocked_table = $wpdb->prefix . 'sam_blocked_requests';

        $sql = "CREATE TABLE IF NOT EXISTS {$rules_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            action ENUM('allow','block') NOT NULL DEFAULT 'block',
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ip (ip),
            KEY idx_action (action)
        ) {$charset};

        CREATE TABLE IF NOT EXISTS {$blocked_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            uri VARCHAR(500) DEFAULT NULL,
            method VARCHAR(10) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            rule_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_ip (ip)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function sync_ip_rules(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $params = $request->get_json_params();
        $rules = $params['rules'] ?? [];

        $table = $wpdb->prefix . 'sam_ip_rules';

        // Replace all rules with the synced set
        $wpdb->query("TRUNCATE TABLE {$table}");

        $synced = 0;
        foreach ($rules as $rule) {
            $ip = sanitize_text_field($rule['ip'] ?? '');
            $action = in_array($rule['action'] ?? '', ['allow', 'block'], true) ? $rule['action'] : 'block';
            $note = sanitize_text_field($rule['note'] ?? '');

            if (empty($ip)) {
                continue;
            }

            $wpdb->insert($table, [
                'ip'         => $ip,
                'action'     => $action,
                'note'       => $note,
                'created_at' => current_time('mysql', true),
            ], ['%s', '%s', '%s', '%s']);

            $synced++;
        }

        // Update .htaccess with IP rules
        $this->update_htaccess_rules();

        SAM_Audit_Logger::log('ip_rules_synced', 'firewall', null, "Synced {$synced} IP rules via SimpleAd Manager");

        return $this->success([
            'synced' => $synced,
            'total'  => count($rules),
        ]);
    }

    public function get_blocked_requests(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $since = $request->get_param('since');
        $table = $wpdb->prefix . 'sam_blocked_requests';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$table_exists) {
            return $this->success(['requests' => [], 'count' => 0]);
        }

        if ($since) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE created_at > %s ORDER BY created_at DESC LIMIT 500",
                    sanitize_text_field($since)
                ),
                ARRAY_A
            );
        } else {
            $results = $wpdb->get_results(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 500",
                ARRAY_A
            );
        }

        return $this->success([
            'requests' => $results ?: [],
            'count'    => count($results ?: []),
            'since'    => $since,
        ]);
    }

    /**
     * Write IP block/allow rules into .htaccess.
     */
    private function update_htaccess_rules(): void {
        global $wpdb;

        $htaccess = ABSPATH . '.htaccess';
        if (!is_writable($htaccess)) {
            return;
        }

        $table = $wpdb->prefix . 'sam_ip_rules';
        $rules = $wpdb->get_results("SELECT * FROM {$table} ORDER BY action ASC, id ASC", ARRAY_A);

        $contents = file_get_contents($htaccess);

        // Remove existing SAM rules
        $contents = preg_replace(
            '/# BEGIN SimpleAd Manager IP Rules.*?# END SimpleAd Manager IP Rules\n?/s',
            '',
            $contents
        );

        if (empty($rules)) {
            file_put_contents($htaccess, $contents);
            return;
        }

        // Build new rules
        $block = "# BEGIN SimpleAd Manager IP Rules\n";
        $block .= "<IfModule mod_rewrite.c>\n";
        $block .= "RewriteEngine On\n";

        foreach ($rules as $rule) {
            $ip = $rule['ip'];
            if ($rule['action'] === 'block') {
                // Convert CIDR to regex-friendly if needed
                if (strpos($ip, '/') !== false) {
                    // For CIDR ranges, use Apache's Require directive instead
                    continue; // Handled below in Require block
                }
                $block .= "RewriteCond %{REMOTE_ADDR} ^" . preg_quote($ip, '/') . "$\n";
                $block .= "RewriteRule ^ - [F,L]\n";
            }
        }

        $block .= "</IfModule>\n";

        // Also add Require directives for Apache 2.4+
        $block .= "<IfModule mod_authz_core.c>\n";
        foreach ($rules as $rule) {
            $ip = $rule['ip'];
            if ($rule['action'] === 'block') {
                $block .= "  # Block: " . esc_html($rule['note'] ?: $ip) . "\n";
                $block .= "  <RequireAll>\n";
                $block .= "    Require all granted\n";
                $block .= "    Require not ip {$ip}\n";
                $block .= "  </RequireAll>\n";
            }
        }
        $block .= "</IfModule>\n";
        $block .= "# END SimpleAd Manager IP Rules\n";

        file_put_contents($htaccess, $block . $contents);
    }
}
