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

        $result = match ($key) {
            'disable_file_editor' => $this->fix_disable_file_editor(),
            'disable_xmlrpc'     => $this->fix_disable_xmlrpc(),
            default              => ['success' => false, 'message' => 'Unknown fix key.'],
        };

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

    private function fix_disable_file_editor(): array {
        $config_file = ABSPATH . 'wp-config.php';
        if (!is_writable($config_file)) {
            return ['success' => false, 'message' => 'wp-config.php is not writable.'];
        }

        $contents = file_get_contents($config_file);
        if (strpos($contents, 'DISALLOW_FILE_EDIT') !== false) {
            return ['success' => true, 'message' => 'DISALLOW_FILE_EDIT is already defined.'];
        }

        $contents = str_replace(
            "/* That's all, stop editing!",
            "define( 'DISALLOW_FILE_EDIT', true );\n\n/* That's all, stop editing!",
            $contents
        );

        file_put_contents($config_file, $contents);

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
        file_put_contents($htaccess_file, $contents . $rule);

        return ['success' => true, 'message' => 'XML-RPC has been blocked via .htaccess.'];
    }
}
