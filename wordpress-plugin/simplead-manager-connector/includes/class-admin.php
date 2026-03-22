<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin interface — connection credentials + IP whitelist management.
 */
class SAM_Admin {

    public function register_menu(): void {
        add_menu_page(
            'SAD Mentenanta',
            'SAD Mentenanta',
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
    }

    /* ─── Page Render ─── */

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle regenerate keys
        if (isset($_POST['sam_regenerate_keys']) && check_admin_referer('sam_regenerate_keys_action')) {
            update_option('sam_api_key', wp_generate_password(32, false));
            update_option('sam_api_secret', wp_generate_password(64, false));
            echo '<div class="notice notice-success"><p>API credentials regenerated successfully.</p></div>';
        }

        // Handle IP whitelist add
        if (isset($_POST['sam_add_ip']) && check_admin_referer('sam_ip_whitelist_action')) {
            $ip = sanitize_text_field($_POST['sam_ip_address'] ?? '');
            if ($ip !== '') {
                $result = SAM_IP_Whitelist::add_ip($ip);
                if ($result === true) {
                    echo '<div class="notice notice-success"><p>IP address added to whitelist.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html($result) . '</p></div>';
                }
            }
        }

        // Handle IP whitelist remove
        if (isset($_POST['sam_remove_ip']) && check_admin_referer('sam_ip_whitelist_action')) {
            $ip = sanitize_text_field($_POST['sam_remove_ip_address'] ?? '');
            if ($ip !== '') {
                SAM_IP_Whitelist::remove_ip($ip);
                echo '<div class="notice notice-success"><p>IP address removed from whitelist.</p></div>';
            }
        }

        $this->render_inline_styles();

        ?>
        <div class="wrap" id="sam-admin-wrap">
            <h1>SAD Mentenanta</h1>

            <?php $this->render_cloudflare_banner(); ?>

            <div class="sam-grid">
                <?php $this->render_connection_card(); ?>
                <?php $this->render_ip_whitelist_card(); ?>
            </div>

            <?php $this->render_request_log_card(); ?>
        </div>
        <?php
    }

    /* ─── Inline Styles ─── */

    private function render_inline_styles(): void {
        ?>
        <style>
            /* Grid */
            #sam-admin-wrap .sam-grid {
                display: grid !important;
                grid-template-columns: 3fr 2fr !important;
                gap: 20px !important;
                margin-top: 20px !important;
            }
            @media screen and (max-width: 1200px) {
                #sam-admin-wrap .sam-grid {
                    grid-template-columns: 1fr !important;
                }
            }

            /* Card */
            #sam-admin-wrap .sam-card {
                background: #fff !important;
                border: 1px solid #c3c4c7 !important;
                border-radius: 4px !important;
                padding: 24px !important;
                box-shadow: 0 1px 1px rgba(0,0,0,.04) !important;
                max-width: none !important;
                margin: 0 !important;
            }
            #sam-admin-wrap .sam-card h2 {
                margin: 0 0 8px 0 !important;
                padding: 0 0 12px 0 !important;
                font-size: 15px !important;
                font-weight: 600 !important;
                color: #1d2327 !important;
                border-bottom: 1px solid #f0f0f1 !important;
            }
            #sam-admin-wrap .sam-card p { color: #50575e; font-size: 13px; }
            #sam-admin-wrap .sam-card-full { margin-top: 20px !important; }

            /* Meta Row */
            #sam-admin-wrap .sam-meta-row {
                display: flex !important;
                gap: 24px; flex-wrap: wrap;
                margin-bottom: 16px; padding: 10px 14px;
                background: #f6f7f7 !important;
                border-radius: 3px; font-size: 13px;
            }
            #sam-admin-wrap .sam-meta-row span { color: #50575e; }
            #sam-admin-wrap .sam-meta-row code { font-size: 12px; }

            /* Credentials */
            #sam-admin-wrap .sam-credentials {
                display: flex !important;
                flex-direction: column !important;
                gap: 14px; margin: 16px 0;
            }
            #sam-admin-wrap .sam-credential-label {
                display: block; font-size: 11px; font-weight: 600;
                text-transform: uppercase; letter-spacing: .5px;
                color: #6b7280; margin-bottom: 4px;
            }
            #sam-admin-wrap .sam-credential-row {
                display: flex !important;
                align-items: center; gap: 6px;
            }
            #sam-admin-wrap .sam-credential-field { flex: 1; min-width: 0; }
            #sam-admin-wrap .sam-credential-field input[type="text"],
            #sam-admin-wrap .sam-credential-field input[type="password"] {
                width: 100% !important;
                font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
                font-size: 13px;
                background: #f6f7f7 !important;
                border: 1px solid #dcdcde !important;
                border-radius: 3px; padding: 6px 10px;
                color: #2c3338; box-sizing: border-box;
            }
            #sam-admin-wrap .sam-credential-row .button { flex-shrink: 0; }

            @media screen and (max-width: 782px) {
                #sam-admin-wrap .sam-credential-row { flex-wrap: wrap; }
                #sam-admin-wrap .sam-credential-field { width: 100% !important; }
                #sam-admin-wrap .sam-meta-row { flex-direction: column; gap: 8px; }
            }

            /* Info Banner */
            #sam-admin-wrap .sam-info-banner {
                display: flex !important;
                align-items: flex-start; gap: 10px;
                margin-top: 20px; padding: 12px 16px;
                background: #f0f6fc !important;
                border: 1px solid #c5d9ed !important;
                border-left: 4px solid #72aee6 !important;
                border-radius: 3px; font-size: 13px; color: #2c3338;
            }
            #sam-admin-wrap .sam-info-banner .dashicons {
                color: #72aee6; margin-top: 2px; flex-shrink: 0;
            }
            #sam-admin-wrap .sam-info-banner p { margin: 0 !important; color: #2c3338; }

            /* Count Badge */
            #sam-admin-wrap .sam-count-badge {
                display: inline-block; background: #dcdcde;
                color: #50575e; font-size: 11px; font-weight: 600;
                padding: 1px 7px; border-radius: 10px;
                margin-left: 6px; vertical-align: middle;
            }

            /* Empty State */
            #sam-admin-wrap .sam-empty-state {
                padding: 20px; text-align: center;
                background: #f6f7f7 !important;
                border-radius: 3px; color: #6b7280;
                font-style: italic; font-size: 13px; margin-bottom: 16px;
            }

            /* Table */
            #sam-admin-wrap .sam-table-wrap { overflow-x: auto; }
            #sam-admin-wrap .sam-table { border-collapse: collapse; width: 100% !important; }
            #sam-admin-wrap .sam-table th,
            #sam-admin-wrap .sam-table td {
                padding: 8px 12px; text-align: left;
                border-bottom: 1px solid #e5e7eb;
            }
            #sam-admin-wrap .sam-table th {
                background: #f9fafb; font-size: 12px; font-weight: 600;
                text-transform: uppercase; color: #6b7280;
            }
            #sam-admin-wrap .sam-table tr:hover td { background: #f9fafb; }
            #sam-admin-wrap .sam-card .sam-table { margin-bottom: 16px; }

            /* Badges */
            #sam-admin-wrap .sam-badge {
                display: inline-block; padding: 2px 8px;
                border-radius: 3px; font-size: 12px;
                font-weight: 500; line-height: 1.6;
            }
            #sam-admin-wrap .sam-badge-pass { background: #d1fae5 !important; color: #065f46; }
            #sam-admin-wrap .sam-badge-fail { background: #fee2e2 !important; color: #991b1b; }

            /* IP Form */
            #sam-admin-wrap .sam-ip-add-form {
                display: flex !important; gap: 8px; align-items: flex-end;
            }
            #sam-admin-wrap .sam-ip-add-form label {
                display: block; font-size: 12px; font-weight: 500; margin-bottom: 4px;
            }
            #sam-admin-wrap .sam-ip-remove-form { display: inline; }
        </style>
        <?php
    }

    /* ─── Card Renders ─── */

    private function render_connection_card(): void {
        $api_key    = get_option('sam_api_key', '');
        $api_secret = get_option('sam_api_secret', '');
        $rest_url   = rest_url(SAM_REST_NAMESPACE);
        ?>
        <div class="sam-card">
            <h2>Connection</h2>

            <div class="sam-meta-row">
                <span><strong>REST Endpoint:</strong> <code><?php echo esc_html($rest_url); ?></code></span>
                <span><strong>Version:</strong> <code><?php echo esc_html(SAM_VERSION); ?></code></span>
            </div>

            <div class="sam-credentials">
                <div>
                    <label class="sam-credential-label" for="sam-api-key">API Key</label>
                    <div class="sam-credential-row">
                        <div class="sam-credential-field">
                            <input type="text" readonly value="<?php echo esc_attr($api_key); ?>" id="sam-api-key" />
                        </div>
                        <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('sam-api-key').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">Copy</button>
                    </div>
                </div>
                <div>
                    <label class="sam-credential-label" for="sam-api-secret">API Secret</label>
                    <div class="sam-credential-row">
                        <div class="sam-credential-field">
                            <input type="password" readonly value="<?php echo esc_attr($api_secret); ?>" id="sam-api-secret" />
                        </div>
                        <button type="button" class="button button-small" onclick="var el=document.getElementById('sam-api-secret'); el.type=el.type==='password'?'text':'password'; this.textContent=el.type==='password'?'Show':'Hide';">Show</button>
                        <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('sam-api-secret').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">Copy</button>
                    </div>
                </div>
                <div>
                    <label class="sam-credential-label" for="sam-api-endpoint">API Endpoint</label>
                    <div class="sam-credential-row">
                        <div class="sam-credential-field">
                            <input type="text" readonly value="<?php echo esc_attr($rest_url); ?>" id="sam-api-endpoint" />
                        </div>
                        <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('sam-api-endpoint').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">Copy</button>
                    </div>
                </div>
            </div>

            <form method="post">
                <?php wp_nonce_field('sam_regenerate_keys_action'); ?>
                <p>
                    <input type="submit" name="sam_regenerate_keys" class="button button-link-delete"
                           value="Regenerate API Credentials"
                           onclick="return confirm('Are you sure? This will invalidate the current credentials and disconnect SAD Mentenanta until updated.');" />
                </p>
            </form>
        </div>
        <?php
    }

    private function render_ip_whitelist_card(): void {
        $whitelist = SAM_IP_Whitelist::get_whitelist();
        ?>
        <div class="sam-card">
            <h2>IP Whitelist <span class="sam-count-badge"><?php echo count($whitelist); ?></span></h2>
            <p>Only allow API requests from these IPs. Leave empty to allow all (not recommended).</p>

            <?php if (!empty($whitelist)) : ?>
                <div class="sam-table-wrap">
                    <table class="sam-table">
                        <thead>
                            <tr><th>IP Address</th><th style="width: 100px;">Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($whitelist as $ip) : ?>
                                <tr>
                                    <td><code><?php echo esc_html($ip); ?></code></td>
                                    <td>
                                        <form method="post" class="sam-ip-remove-form">
                                            <?php wp_nonce_field('sam_ip_whitelist_action'); ?>
                                            <input type="hidden" name="sam_remove_ip_address" value="<?php echo esc_attr($ip); ?>" />
                                            <input type="submit" name="sam_remove_ip" class="button button-small"
                                                   value="Remove"
                                                   onclick="return confirm('Remove this IP from the whitelist?');" />
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="sam-empty-state">No IPs whitelisted &mdash; all IPs are currently allowed.</div>
            <?php endif; ?>

            <form method="post" class="sam-ip-add-form">
                <?php wp_nonce_field('sam_ip_whitelist_action'); ?>
                <div>
                    <label for="sam-ip-address">IP Address (CIDR supported)</label>
                    <input type="text" name="sam_ip_address" id="sam-ip-address" placeholder="203.0.113.50 or 10.0.0.0/24" class="regular-text" />
                </div>
                <input type="submit" name="sam_add_ip" class="button button-primary" value="Add IP" />
            </form>
        </div>
        <?php
    }

    private function render_request_log_card(): void {
        $logs = SAM_Request_Logger::get_recent(20);
        ?>
        <div class="sam-card sam-card-full">
            <h2>Recent API Requests</h2>
            <p>Last 20 API requests to this site. Logs are retained for 30 days.</p>

            <?php if (!empty($logs)) : ?>
                <div class="sam-table-wrap">
                    <table class="sam-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>IP</th>
                                <th>Endpoint</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log) : ?>
                                <tr>
                                    <td title="<?php echo esc_attr($log->created_at); ?>">
                                        <?php echo esc_html(human_time_diff(strtotime($log->created_at), current_time('timestamp', true)) . ' ago'); ?>
                                    </td>
                                    <td><code><?php echo esc_html($log->ip); ?></code></td>
                                    <td><code><?php echo esc_html($log->method . ' ' . $log->endpoint); ?></code></td>
                                    <td>
                                        <?php
                                        $code = (int) $log->status_code;
                                        $class = $code >= 400 ? 'sam-badge-fail' : 'sam-badge-pass';
                                        ?>
                                        <span class="sam-badge <?php echo $class; ?>"><?php echo esc_html($code); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <div class="sam-empty-state">No API requests logged yet.</div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_cloudflare_banner(): void {
        ?>
        <div class="sam-info-banner">
            <span class="dashicons dashicons-cloud"></span>
            <p><strong>Cloudflare:</strong> If this site uses Cloudflare, add a WAF exception rule for <code>/wp-json/simplead/v1/*</code> to prevent blocked API requests.</p>
        </div>
        <?php
    }
}
