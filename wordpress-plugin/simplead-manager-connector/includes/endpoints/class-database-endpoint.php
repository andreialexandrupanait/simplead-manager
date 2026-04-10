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

        register_rest_route(SAM_REST_NAMESPACE, '/autoload-audit', [
            'methods'             => 'GET',
            'callback'            => [$this, 'autoload_audit'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/config-health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'config_health'],
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

        // WooCommerce-specific stats (if tables exist)
        $wc_stats = $this->get_woocommerce_cleanup_stats();
        if ($wc_stats) {
            $stats = array_merge($stats, $wc_stats);
        }

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

        // WooCommerce / Action Scheduler cleanup
        if (!empty($params['action_scheduler_completed'])) {
            $table = $wpdb->prefix . 'actionscheduler_actions';
            if ($this->validate_table_name($table)) {
                $count = $wpdb->query(
                    "DELETE FROM `{$table}` WHERE status IN ('complete', 'canceled') AND scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL 30 DAY)"
                );
                $cleaned['action_scheduler_completed'] = (int) $count;
                // Clean orphaned logs
                $log_table = $wpdb->prefix . 'actionscheduler_logs';
                if ($this->validate_table_name($log_table)) {
                    $wpdb->query(
                        "DELETE l FROM `{$log_table}` l LEFT JOIN `{$table}` a ON l.action_id = a.action_id WHERE a.action_id IS NULL"
                    );
                }
            }
        }

        if (!empty($params['wc_expired_sessions'])) {
            $table = $wpdb->prefix . 'woocommerce_sessions';
            if ($this->validate_table_name($table)) {
                $count = $wpdb->query(
                    "DELETE FROM `{$table}` WHERE session_expiry < UNIX_TIMESTAMP()"
                );
                $cleaned['wc_expired_sessions'] = (int) $count;
            }
        }

        if (!empty($params['wc_expired_webhooks'])) {
            $table = $wpdb->prefix . 'wc_webhooks';
            if ($this->validate_table_name($table)) {
                $count = $wpdb->query(
                    "DELETE FROM `{$table}` WHERE status = 'disabled'"
                );
                $cleaned['wc_expired_webhooks'] = (int) $count;
            }
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

    /**
     * Autoload audit — list top autoloaded options by size.
     */
    public function autoload_audit(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $total_size = (int) $wpdb->get_var(
            "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );

        $total_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'"
        );

        // Top 50 largest autoloaded options
        $top_options = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS size, autoload
             FROM {$wpdb->options}
             WHERE autoload = 'yes'
             ORDER BY LENGTH(option_value) DESC
             LIMIT 50",
            ARRAY_A
        );

        // Attribute options to plugins where possible
        $options = [];
        foreach ($top_options as $opt) {
            $options[] = [
                'name'   => $opt['option_name'],
                'size'   => (int) $opt['size'],
                'plugin' => $this->detect_option_plugin($opt['option_name']),
            ];
        }

        return $this->success([
            'total_size'    => $total_size,
            'total_count'   => $total_count,
            'top_options'   => $options,
        ]);
    }

    /**
     * Config health — PHP/WP configuration analysis.
     */
    public function config_health(WP_REST_Request $request): WP_REST_Response {
        $php_memory = ini_get('memory_limit');
        $wp_memory = defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : '40M';
        $wp_max_memory = defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : '256M';

        $checks = [];

        // PHP vs WP memory mismatch
        $php_bytes = wp_convert_hr_to_bytes($php_memory);
        $wp_bytes = wp_convert_hr_to_bytes($wp_memory);
        $wp_max_bytes = wp_convert_hr_to_bytes($wp_max_memory);

        if ($wp_bytes > $php_bytes) {
            $checks[] = [
                'key'      => 'memory_mismatch',
                'status'   => 'critical',
                'label'    => 'WP memory limit exceeds PHP memory limit',
                'detail'   => "WP_MEMORY_LIMIT ({$wp_memory}) > PHP memory_limit ({$php_memory})",
            ];
        } elseif ($php_bytes < wp_convert_hr_to_bytes('128M')) {
            $checks[] = [
                'key'      => 'low_php_memory',
                'status'   => 'warning',
                'label'    => 'Low PHP memory limit',
                'detail'   => "PHP memory_limit is {$php_memory} (recommended: 128M+)",
            ];
        } else {
            $checks[] = [
                'key'      => 'memory_ok',
                'status'   => 'ok',
                'label'    => 'Memory limits configured correctly',
                'detail'   => "PHP: {$php_memory}, WP: {$wp_memory}, WP Max: {$wp_max_memory}",
            ];
        }

        // Debug mode
        $debug_on = defined('WP_DEBUG') && WP_DEBUG;
        $debug_log = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        $debug_display = defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY;

        if ($debug_on && $debug_display) {
            $checks[] = [
                'key'    => 'debug_display',
                'status' => 'critical',
                'label'  => 'WP_DEBUG_DISPLAY is enabled',
                'detail' => 'Errors are visible to visitors. Disable WP_DEBUG_DISPLAY in production.',
            ];
        } elseif ($debug_on) {
            $checks[] = [
                'key'    => 'debug_on',
                'status' => 'warning',
                'label'  => 'WP_DEBUG is enabled',
                'detail' => 'Debug mode is on' . ($debug_log ? ' with logging enabled' : '') . '.',
            ];
        } else {
            $checks[] = [
                'key'    => 'debug_off',
                'status' => 'ok',
                'label'  => 'Debug mode is off',
                'detail' => 'WP_DEBUG is disabled.',
            ];
        }

        // Max execution time
        $max_exec = (int) ini_get('max_execution_time');
        if ($max_exec > 0 && $max_exec < 30) {
            $checks[] = [
                'key'    => 'low_max_execution',
                'status' => 'warning',
                'label'  => 'Low max execution time',
                'detail' => "max_execution_time is {$max_exec}s (recommended: 30s+)",
            ];
        } else {
            $checks[] = [
                'key'    => 'max_execution_ok',
                'status' => 'ok',
                'label'  => 'Max execution time is adequate',
                'detail' => "max_execution_time: {$max_exec}s",
            ];
        }

        // Upload size
        $upload_max = ini_get('upload_max_filesize');
        $post_max = ini_get('post_max_size');
        $upload_bytes = wp_convert_hr_to_bytes($upload_max);
        $post_bytes = wp_convert_hr_to_bytes($post_max);

        if ($upload_bytes > $post_bytes) {
            $checks[] = [
                'key'    => 'upload_post_mismatch',
                'status' => 'warning',
                'label'  => 'upload_max_filesize exceeds post_max_size',
                'detail' => "upload_max_filesize ({$upload_max}) > post_max_size ({$post_max})",
            ];
        } else {
            $checks[] = [
                'key'    => 'upload_ok',
                'status' => 'ok',
                'label'  => 'Upload limits configured correctly',
                'detail' => "upload_max_filesize: {$upload_max}, post_max_size: {$post_max}",
            ];
        }

        // DISALLOW_FILE_EDIT
        $file_edit = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;
        $checks[] = [
            'key'    => 'file_edit',
            'status' => $file_edit ? 'ok' : 'warning',
            'label'  => $file_edit ? 'File editor disabled' : 'File editor is enabled',
            'detail' => $file_edit
                ? 'DISALLOW_FILE_EDIT is true — the theme/plugin editor is disabled.'
                : 'DISALLOW_FILE_EDIT is not set. Consider disabling the file editor for security.',
        ];

        // SSL
        $ssl = is_ssl();
        $checks[] = [
            'key'    => 'ssl',
            'status' => $ssl ? 'ok' : 'critical',
            'label'  => $ssl ? 'SSL is active' : 'SSL is not active',
            'detail' => $ssl ? 'Site is served over HTTPS.' : 'Site is not using HTTPS. Enable SSL immediately.',
        ];

        // Cron
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $checks[] = [
            'key'    => 'wp_cron',
            'status' => 'info',
            'label'  => $cron_disabled ? 'WP-Cron is disabled' : 'WP-Cron is enabled',
            'detail' => $cron_disabled
                ? 'DISABLE_WP_CRON is true — ensure a system cron is configured.'
                : 'WP-Cron runs on page load. Consider a system cron for better reliability.',
        ];

        // Object cache
        $object_cache = wp_using_ext_object_cache();
        $checks[] = [
            'key'    => 'object_cache',
            'status' => $object_cache ? 'ok' : 'info',
            'label'  => $object_cache ? 'External object cache active' : 'No external object cache',
            'detail' => $object_cache
                ? 'An external object cache (Redis/Memcached) is in use.'
                : 'Consider adding Redis or Memcached for better performance.',
        ];

        return $this->success([
            'checks' => $checks,
            'config' => [
                'php_version'       => phpversion(),
                'wp_version'        => get_bloginfo('version'),
                'php_memory_limit'  => $php_memory,
                'wp_memory_limit'   => $wp_memory,
                'wp_max_memory'     => $wp_max_memory,
                'max_execution_time' => $max_exec,
                'upload_max_filesize' => $upload_max,
                'post_max_size'     => $post_max,
                'max_input_vars'    => (int) ini_get('max_input_vars'),
                'php_sapi'          => php_sapi_name(),
                'debug'             => $debug_on,
                'debug_log'         => $debug_log,
                'debug_display'     => $debug_display,
                'ssl'               => $ssl,
                'object_cache'      => $object_cache,
                'table_prefix'      => $GLOBALS['table_prefix'],
                'multisite'         => is_multisite(),
            ],
        ]);
    }

    /**
     * Get WooCommerce and Action Scheduler cleanup stats.
     *
     * @return array|null Null if WooCommerce tables don't exist.
     */
    private function get_woocommerce_cleanup_stats(): ?array {
        global $wpdb;

        $stats = [];
        $has_wc = false;

        // Action Scheduler — completed/canceled actions older than 30 days
        $as_table = $wpdb->prefix . 'actionscheduler_actions';
        if ($this->validate_table_name($as_table)) {
            $has_wc = true;
            $stats['action_scheduler_completed'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$as_table}` WHERE status IN ('complete', 'canceled') AND scheduled_date_gmt < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
        }

        // WooCommerce expired sessions
        $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
        if ($this->validate_table_name($sessions_table)) {
            $has_wc = true;
            $stats['wc_expired_sessions'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$sessions_table}` WHERE session_expiry < UNIX_TIMESTAMP()"
            );
        }

        // WooCommerce disabled webhooks
        $webhooks_table = $wpdb->prefix . 'wc_webhooks';
        if ($this->validate_table_name($webhooks_table)) {
            $has_wc = true;
            $stats['wc_expired_webhooks'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$webhooks_table}` WHERE status = 'disabled'"
            );
        }

        return $has_wc ? $stats : null;
    }

    /**
     * Detect which plugin an autoloaded option belongs to.
     */
    private function detect_option_plugin(string $option_name): ?string {
        $prefixes = [
            'woocommerce'     => 'WooCommerce',
            'wc_'             => 'WooCommerce',
            'yoast'           => 'Yoast SEO',
            'wpseo'           => 'Yoast SEO',
            'aioseo'          => 'All in One SEO',
            'rank_math'       => 'Rank Math',
            'rankmath'        => 'Rank Math',
            'elementor'       => 'Elementor',
            'wpforms'         => 'WPForms',
            'jetpack'         => 'Jetpack',
            'wordfence'       => 'Wordfence',
            'wf_'             => 'Wordfence',
            'litespeed'       => 'LiteSpeed Cache',
            'ewwwio'          => 'EWWW Image Optimizer',
            'smush'           => 'Smush',
            'icl_'            => 'WPML',
            'wpml_'           => 'WPML',
            'acf_'            => 'ACF',
            'updraft'         => 'UpdraftPlus',
            'duplicator'      => 'Duplicator',
            'monsterinsights' => 'MonsterInsights',
            'gravityforms'    => 'Gravity Forms',
            'gf_'             => 'Gravity Forms',
            'nf_'             => 'Ninja Forms',
            'cf7'             => 'Contact Form 7',
            'wpcf7'           => 'Contact Form 7',
            'bp-'             => 'BuddyPress',
            'bbp_'            => 'bbPress',
            'redirection'     => 'Redirection',
            'sam_'            => 'SAM Connector',
            'action_scheduler' => 'Action Scheduler',
        ];

        foreach ($prefixes as $prefix => $plugin_name) {
            if (strpos($option_name, $prefix) === 0) {
                return $plugin_name;
            }
        }

        // WordPress core options
        $wp_core = [
            'siteurl', 'home', 'blogname', 'blogdescription', 'admin_email',
            'active_plugins', 'template', 'stylesheet', 'permalink_structure',
            'rewrite_rules', 'widget_', 'theme_mods_', 'sidebars_widgets',
            'cron', 'auto_core_update', 'db_version', 'initial_db_version',
            'wp_user_roles', 'fresh_site', 'user_count',
        ];

        foreach ($wp_core as $core) {
            if (strpos($option_name, $core) === 0 || $option_name === $core) {
                return 'WordPress Core';
            }
        }

        return null;
    }
}
