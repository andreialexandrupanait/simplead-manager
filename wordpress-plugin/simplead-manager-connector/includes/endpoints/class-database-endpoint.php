<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database health and cleanup endpoints.
 */
class SAM_Database_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/database-health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'database_health'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/db-cleanup-stats', [
            'methods'             => 'GET',
            'callback'            => [$this, 'cleanup_stats'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/db-cleanup-run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cleanup_run'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function database_health(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $tables = [];
        $total_size = 0;
        $total_rows = 0;

        $results = $wpdb->get_results("SHOW TABLE STATUS", ARRAY_A);

        foreach ($results as $row) {
            $data_size = (int) ($row['Data_length'] ?? 0);
            $index_size = (int) ($row['Index_length'] ?? 0);
            $overhead = (int) ($row['Data_free'] ?? 0);
            $table_size = $data_size + $index_size;
            $rows = (int) ($row['Rows'] ?? 0);

            $total_size += $table_size;
            $total_rows += $rows;

            $tables[] = [
                'name'       => $row['Name'],
                'engine'     => $row['Engine'] ?? 'unknown',
                'rows'       => $rows,
                'data_size'  => $data_size,
                'index_size' => $index_size,
                'overhead'   => $overhead,
                'total_size' => $table_size,
            ];
        }

        // Sort by size descending
        usort($tables, fn($a, $b) => $b['total_size'] <=> $a['total_size']);

        // Autoloaded options size
        $autoload_size = $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );

        return $this->success([
            'tables'          => $tables,
            'total_size'      => $total_size,
            'total_rows'      => $total_rows,
            'table_count'     => count($tables),
            'autoload_size'   => (int) $autoload_size,
            'db_version'      => $wpdb->db_version(),
            'db_name'         => DB_NAME,
            'table_prefix'    => $wpdb->prefix,
        ]);
    }

    public function cleanup_stats(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $stats = [
            'revisions' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
            ),
            'auto_drafts' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
            ),
            'trashed_posts' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
            ),
            'spam_comments' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
            ),
            'trashed_comments' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
            ),
            'expired_transients' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
            ),
            'orphaned_postmeta' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
            ),
            'orphaned_commentmeta' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL"
            ),
            'orphaned_usermeta' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL"
            ),
            'orphaned_termmeta' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id WHERE t.term_id IS NULL"
            ),
        ];

        $total = array_sum($stats);

        return $this->success([
            'stats' => $stats,
            'total' => $total,
        ]);
    }

    public function cleanup_run(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $params = $request->get_json_params();
        $cleaned = [];

        if (!empty($params['revisions'])) {
            $count = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
            $cleaned['revisions'] = (int) $count;
        }

        if (!empty($params['auto_drafts'])) {
            $count = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
            $cleaned['auto_drafts'] = (int) $count;
        }

        if (!empty($params['trashed_posts'])) {
            $count = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
            $cleaned['trashed_posts'] = (int) $count;
        }

        if (!empty($params['spam_comments'])) {
            $count = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
            $cleaned['spam_comments'] = (int) $count;
        }

        if (!empty($params['trashed_comments'])) {
            $count = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
            $cleaned['trashed_comments'] = (int) $count;
        }

        if (!empty($params['expired_transients'])) {
            // Delete transient timeouts and their matching transient values
            $expired = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()"
            );
            $count = 0;
            foreach ($expired as $timeout_key) {
                $transient_key = str_replace('_transient_timeout_', '_transient_', $timeout_key);
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $timeout_key));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $transient_key));
                $count++;
            }
            $cleaned['expired_transients'] = $count;
        }

        if (!empty($params['orphaned_postmeta'])) {
            $count = $wpdb->query(
                "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL"
            );
            $cleaned['orphaned_postmeta'] = (int) $count;
        }

        if (!empty($params['orphaned_commentmeta'])) {
            $count = $wpdb->query(
                "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL"
            );
            $cleaned['orphaned_commentmeta'] = (int) $count;
        }

        if (!empty($params['orphaned_usermeta'])) {
            $count = $wpdb->query(
                "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL"
            );
            $cleaned['orphaned_usermeta'] = (int) $count;
        }

        if (!empty($params['orphaned_termmeta'])) {
            $count = $wpdb->query(
                "DELETE tm FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id WHERE t.term_id IS NULL"
            );
            $cleaned['orphaned_termmeta'] = (int) $count;
        }

        $total = array_sum($cleaned);

        SAM_Audit_Logger::log('db_cleanup', 'database', null, "Cleaned {$total} items via SimpleAd Manager: " . json_encode($cleaned));

        // Optimize tables after cleanup
        if ($total > 0) {
            $tables = $wpdb->get_col("SHOW TABLES");
            foreach ($tables as $table) {
                $wpdb->query("OPTIMIZE TABLE `{$table}`");
            }
        }

        return $this->success([
            'cleaned' => $cleaned,
            'total'   => $total,
        ]);
    }
}
