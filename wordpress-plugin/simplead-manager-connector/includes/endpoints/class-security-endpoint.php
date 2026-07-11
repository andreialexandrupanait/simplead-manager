<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Security check and fix endpoints.
 */
class SAM_Security_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/security-check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'security_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/security-fix', [
            'methods'             => 'POST',
            'callback'            => [$this, 'security_fix'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/core-integrity-check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'core_integrity_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/theme-integrity-check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'theme_integrity_check'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'slug' => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public function security_check(WP_REST_Request $request): WP_REST_Response {
        $checks = [];

        // 1. File permissions
        $checks['wp_config_permissions'] = $this->check_file_permissions(ABSPATH . 'wp-config.php', 0644);
        $checks['htaccess_permissions'] = $this->check_file_permissions(ABSPATH . '.htaccess', 0644);

        // 2. Debug mode
        $checks['debug_disabled'] = [
            'pass'    => !(defined('WP_DEBUG') && WP_DEBUG),
            'label'   => 'Debug mode disabled',
            'message' => (defined('WP_DEBUG') && WP_DEBUG) ? 'WP_DEBUG is enabled. Disable in production.' : 'Debug mode is off.',
            'fixable' => false,
        ];

        // 3. Debug log exposure
        $debug_log_exposed = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && file_exists(WP_CONTENT_DIR . '/debug.log');
        $checks['debug_log_not_exposed'] = [
            'pass'    => !$debug_log_exposed,
            'label'   => 'Debug log not publicly accessible',
            'message' => $debug_log_exposed ? 'debug.log file exists and could be publicly accessible.' : 'No exposed debug log.',
            'fixable' => false,
        ];

        // 4. Default admin username
        $admin_user = get_user_by('login', 'admin');
        $checks['no_default_admin'] = [
            'pass'    => !$admin_user,
            'label'   => 'No default "admin" username',
            'message' => $admin_user ? 'The default "admin" username exists. Consider renaming it.' : 'No default admin username.',
            'fixable' => false,
        ];

        // 5. WordPress version up to date
        $core_update = false;
        $update_core = get_site_transient('update_core');
        if ($update_core && !empty($update_core->updates)) {
            foreach ($update_core->updates as $update) {
                if ($update->response === 'upgrade') {
                    $core_update = true;
                    break;
                }
            }
        }
        $checks['core_up_to_date'] = [
            'pass'    => !$core_update,
            'label'   => 'WordPress core up to date',
            'message' => $core_update ? 'A WordPress core update is available.' : 'WordPress is up to date.',
            'fixable' => false,
        ];

        // 6. Vulnerable / outdated plugins
        $update_plugins = get_site_transient('update_plugins');
        $outdated_count = $update_plugins && !empty($update_plugins->response) ? count($update_plugins->response) : 0;
        $checks['plugins_up_to_date'] = [
            'pass'    => $outdated_count === 0,
            'label'   => 'All plugins up to date',
            'message' => $outdated_count > 0 ? "{$outdated_count} plugin(s) have updates available." : 'All plugins are up to date.',
            'fixable' => false,
        ];

        // 7. Directory listing
        $checks['directory_listing_disabled'] = $this->check_directory_listing();

        // 8. SSL
        $checks['ssl_active'] = [
            'pass'    => is_ssl(),
            'label'   => 'SSL certificate active',
            'message' => is_ssl() ? 'Site is served over HTTPS.' : 'Site is not using HTTPS.',
            'fixable' => false,
        ];

        // 9. File editor disabled
        $editor_disabled = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT;
        $checks['file_editor_disabled'] = [
            'pass'    => $editor_disabled,
            'label'   => 'File editor disabled',
            'message' => $editor_disabled ? 'The built-in file editor is disabled.' : 'The file editor is enabled. Consider adding DISALLOW_FILE_EDIT to wp-config.php.',
            'fixable' => true,
            'fix_key' => 'disable_file_editor',
        ];

        // 10. Database table prefix
        global $wpdb;
        $default_prefix = ($wpdb->prefix === 'wp_');
        $checks['custom_db_prefix'] = [
            'pass'    => !$default_prefix,
            'label'   => 'Custom database table prefix',
            'message' => $default_prefix ? 'Using default "wp_" table prefix.' : 'Using custom table prefix.',
            'fixable' => false,
        ];

        // 11. XML-RPC disabled
        $xmlrpc_enabled = true; // enabled by default in WP
        // Check if a filter is already disabling it
        if (has_filter('xmlrpc_enabled')) {
            $xmlrpc_enabled = apply_filters('xmlrpc_enabled', true);
        }
        $checks['xmlrpc_disabled'] = [
            'pass'    => !$xmlrpc_enabled,
            'label'   => 'XML-RPC disabled',
            'message' => $xmlrpc_enabled ? 'XML-RPC is enabled. Consider disabling if not needed.' : 'XML-RPC is disabled.',
            'fixable' => true,
            'fix_key' => 'disable_xmlrpc',
        ];

        // 12. Wp-content readable only
        $wp_content_perms = substr(sprintf('%o', fileperms(WP_CONTENT_DIR)), -4);
        $checks['wp_content_permissions'] = [
            'pass'    => in_array($wp_content_perms, ['0755', '0750', '755', '750']),
            'label'   => 'wp-content directory permissions',
            'message' => "Current permissions: {$wp_content_perms}",
            'fixable' => false,
        ];

        // 13. Hide WordPress version
        $generator_removed = has_action('wp_head', 'wp_generator') === false;
        $sam_hardening = get_option('sam_security_hardening', []);
        $hide_version = $generator_removed || (!empty($sam_hardening['hide_wp_version']));
        $checks['hide_wp_version'] = [
            'pass'    => $hide_version,
            'label'   => 'WordPress version hidden',
            'message' => $hide_version ? 'WordPress version meta tag is removed.' : 'WordPress version is exposed in page source.',
            'fixable' => true,
            'fix_key' => 'hide_wp_version',
        ];

        // 14. Prevent PHP execution in uploads
        $uploads_htaccess = WP_CONTENT_DIR . '/uploads/.htaccess';
        $php_blocked_in_uploads = false;
        if (file_exists($uploads_htaccess)) {
            $uploads_htaccess_contents = file_get_contents($uploads_htaccess);
            $php_blocked_in_uploads = (
                stripos($uploads_htaccess_contents, 'php') !== false &&
                (stripos($uploads_htaccess_contents, 'deny') !== false || stripos($uploads_htaccess_contents, 'Require all denied') !== false)
            );
        }
        $checks['prevent_php_uploads'] = [
            'pass'    => $php_blocked_in_uploads,
            'label'   => 'PHP execution blocked in uploads',
            'message' => $php_blocked_in_uploads ? 'PHP execution is blocked in the uploads directory.' : 'PHP files can be executed in the uploads directory.',
            'fixable' => true,
            'fix_key' => 'prevent_php_uploads',
        ];

        // 15. Limit login attempts
        $sam_login = get_option('sam_security_login', []);
        $brute_force_enabled = !empty($sam_login['brute_force_protection']);
        // Also check for popular limit-login plugins
        if (!$brute_force_enabled) {
            $brute_force_enabled = is_plugin_active('limit-login-attempts-reloaded/limit-login-attempts-reloaded.php')
                || is_plugin_active('loginizer/loginizer.php')
                || is_plugin_active('wordfence/wordfence.php');
        }
        $checks['limit_login_attempts'] = [
            'pass'    => $brute_force_enabled,
            'label'   => 'Login attempts limited',
            'message' => $brute_force_enabled ? 'Brute force protection is active.' : 'No login attempt limiting detected.',
            'fixable' => false,
        ];

        // 16. Disable trackbacks/pingbacks
        $pingback_flag = get_option('default_pingback_flag', '1');
        $ping_status = get_option('default_ping_status', 'open');
        $trackbacks_disabled = ($pingback_flag === '0' || $pingback_flag === 0) && $ping_status === 'closed';
        $checks['disable_trackbacks'] = [
            'pass'    => $trackbacks_disabled,
            'label'   => 'Trackbacks/pingbacks disabled',
            'message' => $trackbacks_disabled ? 'Trackbacks and pingbacks are disabled.' : 'Trackbacks or pingbacks are still enabled.',
            'fixable' => true,
            'fix_key' => 'disable_trackbacks',
        ];

        // 17. Remove unused database tables
        $all_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
        $core_tables = $wpdb->tables('all', true);
        $orphan_tables = array_diff($all_tables, $core_tables);
        // Filter out known transient/session tables
        $orphan_tables = array_filter($orphan_tables, function($table) use ($wpdb) {
            $short = str_replace($wpdb->prefix, '', $table);
            return !in_array($short, ['actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs']);
        });
        $orphan_count = count($orphan_tables);
        $checks['remove_unused_tables'] = [
            'pass'    => $orphan_count <= 2,
            'label'   => 'No excessive orphaned tables',
            'message' => $orphan_count <= 2 ? 'Database is clean.' : "{$orphan_count} potentially orphaned tables found.",
            'fixable' => false,
        ];

        // 18. Secure cookies
        $secure_cookies = defined('COOKIE_SECURE') && COOKIE_SECURE;
        if (!$secure_cookies && is_ssl()) {
            // Check if WordPress sets secure cookies over SSL by default
            $secure_cookies = true;
        }
        $checks['secure_cookies'] = [
            'pass'    => $secure_cookies,
            'label'   => 'Secure cookie flags',
            'message' => $secure_cookies ? 'Cookies are served with Secure flag over HTTPS.' : 'COOKIE_SECURE is not enabled and site is not using SSL.',
            'fixable' => false,
        ];

        // 19. Strong password policy (heuristic)
        $strong_passwords_ok = true;
        $admin_editors = get_users(['role__in' => ['administrator', 'editor'], 'fields' => ['ID', 'user_pass']]);
        $one_year_ago = strtotime('-365 days');
        foreach ($admin_editors as $user) {
            $last_set = get_user_meta($user->ID, 'sam_password_last_set', true);
            if (!$last_set) {
                // If we've never tracked it, check if there's a session or just pass (heuristic)
                continue;
            }
            if ((int) $last_set < $one_year_ago) {
                $strong_passwords_ok = false;
                break;
            }
        }
        $checks['strong_passwords'] = [
            'pass'    => $strong_passwords_ok,
            'label'   => 'Strong password policy',
            'message' => $strong_passwords_ok ? 'Admin/editor passwords appear current.' : 'Some admin/editor passwords may be outdated (>1 year).',
            'fixable' => false,
        ];

        // Score calculation
        $total = count($checks);
        $passed = count(array_filter($checks, fn($c) => $c['pass']));
        $score = $total > 0 ? round(($passed / $total) * 100) : 0;

        return $this->success([
            'checks' => $checks,
            'score'  => $score,
            'total'  => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
        ]);
    }

    public function security_fix(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $key = sanitize_text_field($params['key'] ?? '');

        switch ($key) {
            case 'disable_file_editor':
                $result = $this->fix_disable_file_editor();
                break;
            case 'disable_xmlrpc':
                $result = $this->fix_disable_xmlrpc();
                break;
            case 'hide_wp_version':
                $result = $this->fix_hide_wp_version();
                break;
            case 'prevent_php_uploads':
                $result = $this->fix_prevent_php_uploads();
                break;
            case 'disable_trackbacks':
                $result = $this->fix_disable_trackbacks();
                break;
            case 'disable_directory_listing':
                // Reuse the tagged-section htaccess writer (atomic write,
                // self-check, rollback) — its rules map already has this key.
                $htaccess = new SAM_Security_Htaccess();
                $r = $htaccess->apply_settings(['disable_directory_listing' => true]);
                $result = $r['disable_directory_listing'] ?? ['success' => false, 'message' => 'Unknown error.'];
                break;
            default:
                $result = ['success' => false, 'message' => 'Unknown fix key.'];
                break;
        }

        if ($result['success']) {
            SAM_Audit_Logger::log('security_fix_applied', 'security', $key, 'Applied via SimpleAd Manager');
        }

        return new WP_REST_Response($result, $result['success'] ? 200 : 400);
    }

    public function core_integrity_check(WP_REST_Request $request): WP_REST_Response {
        global $wp_version;

        $checksums_url = "https://api.wordpress.org/core/checksums/1.0/?version={$wp_version}&locale=" . get_locale();
        $response = wp_remote_get($checksums_url);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'CHECKSUM_FETCH_FAILED', 'message' => 'Failed to fetch official checksums.'],
            ], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['checksums'])) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'NO_CHECKSUMS', 'message' => 'No checksums available for this version.'],
            ], 404);
        }

        $modified = [];
        $missing = [];
        $total_checked = 0;

        foreach ($body['checksums'] as $file => $expected_hash) {
            $file_path = ABSPATH . $file;
            $total_checked++;

            if (!file_exists($file_path)) {
                $missing[] = $file;
                continue;
            }

            $actual_hash = md5_file($file_path);
            if ($actual_hash !== $expected_hash) {
                $modified[] = [
                    'file'          => $file,
                    'expected_hash' => $expected_hash,
                    'actual_hash'   => $actual_hash,
                ];
            }
        }

        return $this->success([
            'wp_version'    => $wp_version,
            'total_checked' => $total_checked,
            'modified'      => $modified,
            'missing'       => $missing,
            'clean'         => empty($modified) && empty($missing),
        ]);
    }

    public function theme_integrity_check(WP_REST_Request $request): WP_REST_Response {
        $slug = $request->get_param('slug');

        $theme = wp_get_theme($slug);
        if (!$theme->exists()) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => ['code' => 'THEME_NOT_FOUND', 'message' => "Theme '{$slug}' not found."],
            ], 404);
        }

        $theme_dir = $theme->get_stylesheet_directory();
        $version = $theme->get('Version');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($theme_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative_path = str_replace($theme_dir . '/', '', $file->getPathname());

            // Skip common non-essential files
            if (preg_match('/\.(log|cache|tmp)$/i', $relative_path)) {
                continue;
            }

            $files[$relative_path] = md5_file($file->getPathname());
        }

        return $this->success([
            'slug'        => $slug,
            'version'     => $version,
            'name'        => $theme->get('Name'),
            'file_count'  => count($files),
            'files'       => $files,
        ]);
    }

    private function check_file_permissions(string $file, int $recommended): array {
        if (!file_exists($file)) {
            return [
                'pass'    => true,
                'label'   => basename($file) . ' permissions',
                'message' => 'File does not exist.',
                'fixable' => false,
            ];
        }

        $perms = fileperms($file) & 0777;
        $is_world_writable = ($perms & 0002) !== 0;

        return [
            'pass'    => !$is_world_writable && $perms <= $recommended,
            'label'   => basename($file) . ' permissions',
            'message' => sprintf('Current: %04o, Recommended: %04o or stricter', $perms, $recommended),
            'fixable' => false,
        ];
    }

    private function check_directory_listing(): array {
        $htaccess_file = ABSPATH . '.htaccess';
        $listing_disabled = false;

        if (file_exists($htaccess_file)) {
            $contents = file_get_contents($htaccess_file);
            $listing_disabled = (strpos($contents, 'Options -Indexes') !== false);
        }

        return [
            'pass'    => $listing_disabled,
            'label'   => 'Directory listing disabled',
            'message' => $listing_disabled ? 'Directory listing is disabled.' : 'Directory listing may be enabled. Add "Options -Indexes" to .htaccess.',
            'fixable' => true,
            'fix_key' => 'disable_directory_listing',
        ];
    }

    /**
     * Atomic file write: write to .tmp, verify, rename(). Create .bak before modifying.
     */
    private function atomic_file_write(string $file_path, string $contents): bool {
        $tmp_path = $file_path . '.tmp';
        $bak_path = $file_path . '.bak';

        // Create backup of original
        if (file_exists($file_path)) {
            if (!copy($file_path, $bak_path)) {
                return false;
            }
        }

        // Write to temp file
        $written = file_put_contents($tmp_path, $contents);
        if ($written === false) {
            @unlink($tmp_path);
            return false;
        }

        // Verify temp file is readable and non-empty
        if (!is_readable($tmp_path) || filesize($tmp_path) === 0) {
            @unlink($tmp_path);
            return false;
        }

        // Atomic rename
        if (!rename($tmp_path, $file_path)) {
            @unlink($tmp_path);
            // Restore from backup
            if (file_exists($bak_path)) {
                rename($bak_path, $file_path);
            }
            return false;
        }

        return true;
    }

    private function fix_disable_file_editor(): array {
        $config_file = ABSPATH . 'wp-config.php';
        if (!is_writable($config_file)) {
            return ['success' => false, 'message' => 'wp-config.php is not writable.'];
        }

        $contents = file_get_contents($config_file);
        if (strpos($contents, 'DISALLOW_FILE_EDIT') !== false) {
            return ['success' => true, 'message' => 'DISALLOW_FILE_EDIT is already defined.'];
        }

        $new_contents = str_replace(
            "/* That's all, stop editing!",
            "define( 'DISALLOW_FILE_EDIT', true );\n\n/* That's all, stop editing!",
            $contents
        );

        if (!$this->atomic_file_write($config_file, $new_contents)) {
            return ['success' => false, 'message' => 'Failed to write wp-config.php atomically.'];
        }

        return ['success' => true, 'message' => 'File editor has been disabled.'];
    }

    private function fix_disable_xmlrpc(): array {
        $htaccess_file = ABSPATH . '.htaccess';
        if (!is_writable($htaccess_file)) {
            return ['success' => false, 'message' => '.htaccess is not writable.'];
        }

        $contents = file_get_contents($htaccess_file);
        if (strpos($contents, 'xmlrpc.php') !== false) {
            return ['success' => true, 'message' => 'XML-RPC is already blocked in .htaccess.'];
        }

        $rule = "\n# Block XML-RPC - Added by SimpleAd Manager\n<Files xmlrpc.php>\n  Require all denied\n</Files>\n";
        $new_contents = $contents . $rule;

        if (!$this->atomic_file_write($htaccess_file, $new_contents)) {
            return ['success' => false, 'message' => 'Failed to write .htaccess atomically.'];
        }

        return ['success' => true, 'message' => 'XML-RPC has been blocked via .htaccess.'];
    }

    private function fix_hide_wp_version(): array {
        // Add to mu-plugins for reliability
        $mu_dir = WPMU_PLUGIN_DIR;
        if (!is_dir($mu_dir)) {
            wp_mkdir_p($mu_dir);
        }

        $file = $mu_dir . '/sam-hide-wp-version.php';
        $contents = "<?php\n// Hide WP version - Added by SimpleAd Manager\nremove_action('wp_head', 'wp_generator');\nadd_filter('the_generator', '__return_empty_string');\n";

        if (!$this->atomic_file_write($file, $contents)) {
            return ['success' => false, 'message' => 'Failed to write mu-plugin.'];
        }

        return ['success' => true, 'message' => 'WordPress version meta tag is now hidden.'];
    }

    private function fix_prevent_php_uploads(): array {
        $uploads_dir = WP_CONTENT_DIR . '/uploads';
        $htaccess = $uploads_dir . '/.htaccess';

        if (file_exists($htaccess)) {
            $contents = file_get_contents($htaccess);
            if (stripos($contents, 'php') !== false) {
                return ['success' => true, 'message' => 'PHP execution is already blocked in uploads.'];
            }
        } else {
            $contents = '';
        }

        $rule = "\n# Block PHP execution - Added by SimpleAd Manager\n<Files *.php>\n  Require all denied\n</Files>\n";
        $new_contents = $contents . $rule;

        if (!$this->atomic_file_write($htaccess, $new_contents)) {
            return ['success' => false, 'message' => 'Failed to write uploads .htaccess.'];
        }

        return ['success' => true, 'message' => 'PHP execution is now blocked in uploads directory.'];
    }

    private function fix_disable_trackbacks(): array {
        update_option('default_pingback_flag', '0');
        update_option('default_ping_status', 'closed');

        return ['success' => true, 'message' => 'Trackbacks and pingbacks have been disabled.'];
    }
}
