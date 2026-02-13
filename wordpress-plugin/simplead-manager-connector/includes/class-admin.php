<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin interface — tabbed management page + AJAX handlers.
 */
class SAM_Admin {

    public function register_menu(): void {
        add_menu_page(
            'SimpleAd Manager',
            'SimpleAd Manager',
            'manage_options',
            'simplead-manager',
            [$this, 'render_page'],
            'dashicons-shield',
            65
        );
    }

    public function register_settings(): void {
        register_setting('sam_settings_group', 'sam_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('sam_settings_group', 'sam_api_secret', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_simplead-manager') {
            return;
        }

        wp_enqueue_style('sam-admin', SAM_PLUGIN_URL . 'assets/admin.css', [], SAM_VERSION);
        wp_enqueue_script('sam-admin', SAM_PLUGIN_URL . 'assets/admin.js', [], SAM_VERSION, true);
        wp_localize_script('sam-admin', 'samAdmin', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('sam_admin_nonce'),
            'wooActive' => class_exists('WooCommerce'),
        ]);
    }

    public function register_ajax_handlers(): void {
        $actions = [
            'sam_health_check',
            'sam_server_resources',
            'sam_site_info',
            'sam_security_check',
            'sam_security_fix',
            'sam_core_integrity',
            'sam_database_health',
            'sam_cleanup_stats',
            'sam_cleanup_run',
            'sam_cron_list',
            'sam_cron_run',
            'sam_cron_disable',
            'sam_cron_enable',
            'sam_error_logs',
            'sam_seo_check',
            'sam_audit_logs',
            'sam_ip_rules_list',
            'sam_ip_rules_save',
            'sam_ip_rules_delete',
            'sam_blocked_requests',
            'sam_woo_stats',
            'sam_woo_low_stock',
            'sam_woo_out_of_stock',
        ];

        foreach ($actions as $action) {
            add_action("wp_ajax_{$action}", [$this, "ajax_{$action}"]);
        }
    }

    /* ─── Request verification ─── */

    private function verify_request(): void {
        check_ajax_referer('sam_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
    }

    /* ─── Dashboard ─── */

    public function ajax_sam_health_check(): void {
        $this->verify_request();
        $endpoint = new SAM_Health_Endpoint();
        $response = $endpoint->health_check(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    public function ajax_sam_server_resources(): void {
        $this->verify_request();
        $endpoint = new SAM_Monitoring_Endpoint();
        $response = $endpoint->server_resources(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    public function ajax_sam_site_info(): void {
        $this->verify_request();
        $endpoint = new SAM_Info_Endpoint();
        $response = $endpoint->get_info(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    /* ─── Security ─── */

    public function ajax_sam_security_check(): void {
        $this->verify_request();
        $endpoint = new SAM_Security_Endpoint();
        $response = $endpoint->security_check(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    public function ajax_sam_security_fix(): void {
        $this->verify_request();
        $request = new WP_REST_Request('POST');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'key' => sanitize_text_field($_POST['key'] ?? ''),
        ]));
        $endpoint = new SAM_Security_Endpoint();
        $response = $endpoint->security_fix($request);
        wp_send_json($response->get_data());
    }

    public function ajax_sam_core_integrity(): void {
        $this->verify_request();
        $endpoint = new SAM_Security_Endpoint();
        $response = $endpoint->core_integrity_check(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    /* ─── Database ─── */

    public function ajax_sam_database_health(): void {
        $this->verify_request();
        $endpoint = new SAM_Database_Endpoint();
        $response = $endpoint->database_health(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    public function ajax_sam_cleanup_stats(): void {
        $this->verify_request();
        $endpoint = new SAM_Database_Endpoint();
        $response = $endpoint->cleanup_stats(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    public function ajax_sam_cleanup_run(): void {
        $this->verify_request();
        $fields = [
            'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments',
            'trashed_comments', 'expired_transients', 'orphaned_postmeta',
            'orphaned_commentmeta', 'orphaned_usermeta', 'orphaned_termmeta',
        ];
        $body = [];
        foreach ($fields as $f) {
            if (!empty($_POST[$f])) {
                $body[$f] = true;
            }
        }
        $request = new WP_REST_Request('POST');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        $endpoint = new SAM_Database_Endpoint();
        $response = $endpoint->cleanup_run($request);
        wp_send_json($response->get_data());
    }

    /* ─── Cron ─── */

    public function ajax_sam_cron_list(): void {
        $this->verify_request();
        $endpoint = new SAM_Cron_Endpoint();
        $response = $endpoint->list_crons(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    public function ajax_sam_cron_run(): void {
        $this->verify_request();
        $request = new WP_REST_Request('POST');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'hook' => sanitize_text_field($_POST['hook'] ?? ''),
        ]));
        $endpoint = new SAM_Cron_Endpoint();
        $response = $endpoint->run_cron($request);
        wp_send_json($response->get_data());
    }

    public function ajax_sam_cron_disable(): void {
        $this->verify_request();
        $request = new WP_REST_Request('POST');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'hook' => sanitize_text_field($_POST['hook'] ?? ''),
        ]));
        $endpoint = new SAM_Cron_Endpoint();
        $response = $endpoint->disable_cron($request);
        wp_send_json($response->get_data());
    }

    public function ajax_sam_cron_enable(): void {
        $this->verify_request();
        $request = new WP_REST_Request('POST');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'hook'     => sanitize_text_field($_POST['hook'] ?? ''),
            'schedule' => sanitize_text_field($_POST['schedule'] ?? 'daily'),
        ]));
        $endpoint = new SAM_Cron_Endpoint();
        $response = $endpoint->enable_cron($request);
        wp_send_json($response->get_data());
    }

    /* ─── Server / Error Logs ─── */

    public function ajax_sam_error_logs(): void {
        $this->verify_request();
        $endpoint = new SAM_Error_Log_Endpoint();
        $response = $endpoint->get_error_logs(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    /* ─── SEO ─── */

    public function ajax_sam_seo_check(): void {
        $this->verify_request();
        $endpoint = new SAM_SEO_Endpoint();
        $response = $endpoint->seo_check(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    /* ─── Audit ─── */

    public function ajax_sam_audit_logs(): void {
        $this->verify_request();
        $request = new WP_REST_Request('GET');
        if (!empty($_POST['since'])) {
            $request->set_param('since', sanitize_text_field($_POST['since']));
        }
        $endpoint = new SAM_Audit_Endpoint();
        $response = $endpoint->get_audit_logs($request);
        wp_send_json($response->get_data());
    }

    /* ─── Firewall ─── */

    public function ajax_sam_ip_rules_list(): void {
        $this->verify_request();
        global $wpdb;
        $table = $wpdb->prefix . 'sam_ip_rules';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$table_exists) {
            wp_send_json(['success' => true, 'rules' => []]);
            return;
        }
        $rules = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A);
        wp_send_json(['success' => true, 'rules' => $rules ?: []]);
    }

    public function ajax_sam_ip_rules_save(): void {
        $this->verify_request();
        global $wpdb;

        $ip     = sanitize_text_field($_POST['ip'] ?? '');
        $action = in_array($_POST['fw_action'] ?? '', ['allow', 'block'], true) ? $_POST['fw_action'] : 'block';
        $note   = sanitize_text_field($_POST['note'] ?? '');

        if (empty($ip)) {
            wp_send_json_error(['message' => 'IP address is required.']);
            return;
        }

        $table = $wpdb->prefix . 'sam_ip_rules';
        $wpdb->insert($table, [
            'ip'         => $ip,
            'action'     => $action,
            'note'       => $note,
            'created_at' => current_time('mysql', true),
        ], ['%s', '%s', '%s', '%s']);

        // Update .htaccess
        $firewall = new SAM_Firewall_Endpoint();
        $firewall->update_htaccess_rules();

        SAM_Audit_Logger::log('ip_rule_added', 'firewall', $ip, "{$action} rule added via admin");

        wp_send_json(['success' => true]);
    }

    public function ajax_sam_ip_rules_delete(): void {
        $this->verify_request();
        global $wpdb;

        $rule_id = absint($_POST['rule_id'] ?? 0);
        if (!$rule_id) {
            wp_send_json_error(['message' => 'Invalid rule ID.']);
            return;
        }

        $table = $wpdb->prefix . 'sam_ip_rules';
        $wpdb->delete($table, ['id' => $rule_id], ['%d']);

        // Update .htaccess
        $firewall = new SAM_Firewall_Endpoint();
        $firewall->update_htaccess_rules();

        SAM_Audit_Logger::log('ip_rule_deleted', 'firewall', (string) $rule_id, 'Rule deleted via admin');

        wp_send_json(['success' => true]);
    }

    public function ajax_sam_blocked_requests(): void {
        $this->verify_request();
        $endpoint = new SAM_Firewall_Endpoint();
        $response = $endpoint->get_blocked_requests(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    /* ─── WooCommerce ─── */

    public function ajax_sam_woo_stats(): void {
        $this->verify_request();
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce not active.']);
            return;
        }
        $request = new WP_REST_Request('GET');
        $request->set_param('period', sanitize_text_field($_POST['period'] ?? 'month'));
        $endpoint = new SAM_WooCommerce_Endpoint();
        $response = $endpoint->get_stats($request);
        wp_send_json($response->get_data());
    }

    public function ajax_sam_woo_low_stock(): void {
        $this->verify_request();
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce not active.']);
            return;
        }
        $endpoint = new SAM_WooCommerce_Endpoint();
        $response = $endpoint->get_low_stock(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    public function ajax_sam_woo_out_of_stock(): void {
        $this->verify_request();
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(['message' => 'WooCommerce not active.']);
            return;
        }
        $endpoint = new SAM_WooCommerce_Endpoint();
        $response = $endpoint->get_out_of_stock(new WP_REST_Request('GET'));
        wp_send_json($response->get_data());
    }

    /* ─── Page Render ─── */

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle regenerate keys (Connection tab)
        if (isset($_POST['sam_regenerate_keys']) && check_admin_referer('sam_regenerate_keys_action')) {
            update_option('sam_api_key', wp_generate_password(32, false));
            update_option('sam_api_secret', wp_generate_password(64, false));
            echo '<div class="notice notice-success"><p>API credentials regenerated successfully.</p></div>';
        }

        $tabs = [
            'dashboard'   => 'Dashboard',
            'security'    => 'Security',
            'database'    => 'Database',
            'cron'        => 'Cron Jobs',
            'server'      => 'Server',
            'seo'         => 'SEO',
            'audit'       => 'Audit Log',
            'firewall'    => 'Firewall',
        ];

        if (class_exists('WooCommerce')) {
            $tabs['woocommerce'] = 'WooCommerce';
        }

        $tabs['connection'] = 'Connection';
        ?>
        <div class="wrap" id="sam-admin-wrap">
            <h1>SimpleAd Manager</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label) : ?>
                    <a href="#<?php echo esc_attr($slug); ?>"
                       class="nav-tab sam-nav-tab"
                       data-tab="<?php echo esc_attr($slug); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php foreach ($tabs as $slug => $label) : ?>
                <div id="sam-pane-<?php echo esc_attr($slug); ?>" class="sam-tab-pane">
                    <?php if ($slug === 'connection') : ?>
                        <?php $this->render_connection_tab(); ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_connection_tab(): void {
        $api_key    = get_option('sam_api_key', '');
        $api_secret = get_option('sam_api_secret', '');
        $rest_url   = rest_url(SAM_REST_NAMESPACE);
        ?>
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 12px;">
            <h2>Connection Status</h2>
            <p><strong>REST API Endpoint:</strong> <code><?php echo esc_html($rest_url); ?></code></p>
            <p><strong>Plugin Version:</strong> <code><?php echo esc_html(SAM_VERSION); ?></code></p>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2>API Credentials</h2>
            <p>Use these credentials in your SimpleAd Manager dashboard to connect this site.</p>

            <table class="form-table">
                <tr>
                    <th>API Key</th>
                    <td>
                        <input type="text" readonly value="<?php echo esc_attr($api_key); ?>"
                               class="regular-text" id="sam-api-key"
                               style="font-family: monospace; background: #f0f0f0;" />
                        <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('sam-api-key').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">Copy</button>
                    </td>
                </tr>
                <tr>
                    <th>API Secret</th>
                    <td>
                        <input type="password" readonly value="<?php echo esc_attr($api_secret); ?>"
                               class="regular-text" id="sam-api-secret"
                               style="font-family: monospace; background: #f0f0f0;" />
                        <button type="button" class="button" onclick="var el=document.getElementById('sam-api-secret'); el.type=el.type==='password'?'text':'password'; this.textContent=el.type==='password'?'Show':'Hide';">Show</button>
                        <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('sam-api-secret').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">Copy</button>
                    </td>
                </tr>
                <tr>
                    <th>API Endpoint</th>
                    <td>
                        <input type="text" readonly value="<?php echo esc_attr($rest_url); ?>"
                               class="regular-text" id="sam-api-endpoint"
                               style="font-family: monospace; background: #f0f0f0;" />
                        <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('sam-api-endpoint').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">Copy</button>
                    </td>
                </tr>
            </table>

            <form method="post">
                <?php wp_nonce_field('sam_regenerate_keys_action'); ?>
                <p>
                    <input type="submit" name="sam_regenerate_keys" class="button button-secondary"
                           value="Regenerate API Credentials"
                           onclick="return confirm('Are you sure? This will invalidate the current credentials and disconnect SimpleAd Manager until updated.');" />
                </p>
            </form>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2>Cloudflare Notice</h2>
            <p>If this site uses Cloudflare, you may need to add a WAF exception rule for the path <code>/wp-json/simplead/v1/*</code> to prevent Cloudflare from blocking API requests from SimpleAd Manager.</p>
        </div>
        <?php
    }
}
