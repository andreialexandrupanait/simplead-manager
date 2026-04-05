<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database health and cleanup endpoints.
 */
class SAM_Database_Endpoint extends SAM_Endpoint_Base {

    /**
     * Known WP core tables (without prefix).
     */
    private const WP_CORE_TABLES = [
        'posts', 'postmeta', 'comments', 'commentmeta',
        'terms', 'termmeta', 'term_taxonomy', 'term_relationships',
        'options', 'users', 'usermeta', 'links',
        // Multisite tables
        'blogs', 'blog_versions', 'registration_log', 'signups', 'site', 'sitemeta',
    ];

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

        register_rest_route(SAM_REST_NAMESPACE, '/db-table-optimize', [
            'methods'             => 'POST',
            'callback'            => [$this, 'table_optimize'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/db-table-convert-engine', [
            'methods'             => 'POST',
            'callback'            => [$this, 'table_convert_engine'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/db-table-delete', [
            'methods'             => 'POST',
            'callback'            => [$this, 'table_delete'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Validate that a table name exists in the database.
     */
    private function validate_table_name(string $table): bool {
        global $wpdb;
        $tables = $wpdb->get_col("SHOW TABLES");
        return in_array($table, $tables, true);
    }

    /**
     * Check if a table is a WordPress core table.
     */
    private function is_core_table(string $table): bool {
        global $wpdb;
        $unprefixed = str_replace($wpdb->prefix, '', $table);
        return in_array($unprefixed, self::WP_CORE_TABLES, true);
    }

    /**
     * Detect which plugin owns a non-core table.
     *
     * Returns: ['name' => string, 'slug' => string, 'status' => 'active'|'inactive'|'not-installed'] or null.
     */
    private function detect_table_plugin(string $table): ?array {
        global $wpdb;

        if ($this->is_core_table($table)) {
            return null;
        }

        $unprefixed = str_replace($wpdb->prefix, '', $table);

        // Known plugin table patterns (prefix => [slug, name])
        $known = [
            'woocommerce'          => ['woocommerce/woocommerce.php', 'WooCommerce'],
            'wc_'                  => ['woocommerce/woocommerce.php', 'WooCommerce'],
            'actionscheduler'      => ['woocommerce/woocommerce.php', 'WooCommerce'],
            'yoast'                => ['wordpress-seo/wp-seo.php', 'Yoast SEO'],
            'aioseo'               => ['all-in-one-seo-pack/all_in_one_seo_pack.php', 'All in One SEO'],
            'rank_math'            => ['seo-by-rank-math/rank-math.php', 'Rank Math'],
            'rankmath'             => ['seo-by-rank-math/rank-math.php', 'Rank Math'],
            'elementor'            => ['elementor/elementor.php', 'Elementor'],
            'wpforms'              => ['wpforms-lite/wpforms.php', 'WPForms'],
            'gf_'                  => ['gravityforms/gravityforms.php', 'Gravity Forms'],
            'frm_'                 => ['formidable/formidable.php', 'Formidable Forms'],
            'cf7'                  => ['contact-form-7/wp-contact-form-7.php', 'Contact Form 7'],
            'redirection'          => ['redirection/redirection.php', 'Redirection'],
            'jetpack'              => ['jetpack/jetpack.php', 'Jetpack'],
            'bp_'                  => ['buddypress/bp-loader.php', 'BuddyPress'],
            'bbp_'                 => ['bbpress/bbpress.php', 'bbPress'],
            'icl_'                 => ['sitepress-multilingual-cms/sitepress.php', 'WPML'],
            'mailchimp'            => ['mailchimp-for-wp/mailchimp-for-wp.php', 'MC4WP'],
            'newsletter'           => ['developer_newsletter/developer_newsletter.php', 'Developer Newsletter'],
            'statistics'           => ['developer_statistics/developer_statistics.php', 'Developer Statistics'],
            'wfhits'               => ['wordfence/wordfence.php', 'Wordfence'],
            'wflogins'             => ['wordfence/wordfence.php', 'Wordfence'],
            'wfknownfilelist'      => ['wordfence/wordfence.php', 'Wordfence'],
            'wffilechanges'        => ['wordfence/wordfence.php', 'Wordfence'],
            'wfblockediplog'       => ['wordfence/wordfence.php', 'Wordfence'],
            'itsec'                => ['developer_ithemes_security/developer_ithemes_security.php', 'iThemes Security'],
            'litespeed'            => ['litespeed-cache/litespeed-cache.php', 'LiteSpeed Cache'],
            'ewwwio'               => ['ewww-image-optimizer/ewww-image-optimizer.php', 'EWWW Image Optimizer'],
            'smush'                => ['developer_smush/developer_smush.php', 'Smush'],
            'duplicator'           => ['duplicator/duplicator.php', 'Duplicator'],
            'nf3_'                 => ['ninja-forms/ninja-forms.php', 'Ninja Forms'],
            'slim_stats'           => ['developer_slim_stats/developer_slim_stats.php', 'Slimstat Analytics'],
            'matomo'               => ['matomo/matomo.php', 'Matomo Analytics'],
            'e_'                   => ['developer_events_manager/developer_events_manager.php', 'Events Manager'],
            'as3cf'                => ['amazon-s3-and-cloudfront/wordpress-s3.php', 'WP Offload Media'],
            'sam_'                 => ['simplead-manager-connector/simplead-manager-connector.php', 'SAD Mentenanta'],
        ];

        // Load installed plugins (cached for this request)
        static $plugins_cache = null;
        if ($plugins_cache === null) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_plugins = get_plugins();
            $active = get_option('active_plugins', []);
            $plugins_cache = [];
            foreach ($all_plugins as $file => $data) {
                $plugins_cache[$file] = [
                    'name' => $data['Name'] ?? dirname($file),
                    'active' => in_array($file, $active, true),
                ];
            }
        }

        // Tier 1: Check known patterns
        foreach ($known as $prefix => $info) {
            if (strpos($unprefixed, $prefix) === 0 || strpos($unprefixed, $prefix) !== false) {
                $status = 'not-installed';
                if (isset($plugins_cache[$info[0]])) {
                    $status = $plugins_cache[$info[0]]['active'] ? 'active' : 'inactive';
                }
                return ['name' => $info[1], 'slug' => $info[0], 'status' => $status];
            }
        }

        // Tier 2: Match against installed plugin folder names
        foreach ($plugins_cache as $file => $data) {
            $folder = dirname($file);
            if ($folder === '.') continue;
            // Normalize: my-plugin → my_plugin for matching
            $folder_normalized = str_replace('-', '_', $folder);
            if (strpos($unprefixed, $folder_normalized) === 0) {
                return [
                    'name' => $data['name'],
                    'slug' => $file,
                    'status' => $data['active'] ? 'active' : 'inactive',
                ];
            }
        }

        return null;
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
                'collation'  => $row['Collation'] ?? 'unknown',
                'rows'       => $rows,
                'data_size'  => $data_size,
                'index_size' => $index_size,
                'overhead'   => $overhead,
                'total_size' => $table_size,
                'is_core'    => $this->is_core_table($row['Name']),
                'plugin'     => $this->detect_table_plugin($row['Name']),
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

        // Optimize tables after cleanup — validate each table name
        if ($total > 0) {
            $db_tables = $wpdb->get_col("SHOW TABLES");
            foreach ($db_tables as $table) {
                if (in_array($table, $db_tables, true)) {
                    $wpdb->query("OPTIMIZE TABLE `" . esc_sql($table) . "`");
                }
            }
        }

        return $this->success([
            'cleaned' => $cleaned,
            'total'   => $total,
        ]);
    }

    /**
     * Optimize a single table.
     */
    public function table_optimize(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $params = $request->get_json_params();
        $table = sanitize_text_field($params['table'] ?? '');

        if (empty($table) || !$this->validate_table_name($table)) {
            return $this->error('INVALID_TABLE', 'Table does not exist.', 400);
        }

        $wpdb->query("OPTIMIZE TABLE `" . esc_sql($table) . "`");

        SAM_Audit_Logger::log('table_optimized', 'database', $table, "Optimized table via SimpleAd Manager");

        return $this->success(['table' => $table, 'optimized' => true]);
    }

    /**
     * Convert a table's storage engine to InnoDB.
     */
    public function table_convert_engine(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $params = $request->get_json_params();
        $table = sanitize_text_field($params['table'] ?? '');

        if (empty($table) || !$this->validate_table_name($table)) {
            return $this->error('INVALID_TABLE', 'Table does not exist.', 400);
        }

        // Check current engine
        $status = $wpdb->get_row($wpdb->prepare(
            "SHOW TABLE STATUS WHERE Name = %s", $table
        ), ARRAY_A);

        if (!$status) {
            return $this->error('TABLE_STATUS_FAILED', 'Could not read table status.', 500);
        }

        if (strtolower($status['Engine'] ?? '') === 'innodb') {
            return $this->error('ALREADY_INNODB', 'Table is already using InnoDB engine.', 400);
        }

        $wpdb->query("ALTER TABLE `" . esc_sql($table) . "` ENGINE=InnoDB");

        SAM_Audit_Logger::log('table_engine_converted', 'database', $table, "Converted to InnoDB via SimpleAd Manager");

        return $this->success(['table' => $table, 'engine' => 'InnoDB']);
    }

    /**
     * Delete a non-core table.
     */
    public function table_delete(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $params = $request->get_json_params();
        $table = sanitize_text_field($params['table'] ?? '');

        if (empty($table) || !$this->validate_table_name($table)) {
            return $this->error('INVALID_TABLE', 'Table does not exist.', 400);
        }

        if ($this->is_core_table($table)) {
            return $this->error('CORE_TABLE', 'Cannot delete WordPress core tables.', 403);
        }

        $wpdb->query("DROP TABLE `" . esc_sql($table) . "`");

        SAM_Audit_Logger::log('table_deleted', 'database', $table, "Deleted table via SimpleAd Manager");

        return $this->success(['table' => $table, 'deleted' => true]);
    }
}
