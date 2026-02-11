<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin settings page for the plugin.
 */
class SAM_Admin {

    public function register_menu(): void {
        add_options_page(
            'SimpleAd Manager',
            'SimpleAd Manager',
            'manage_options',
            'simplead-manager',
            [$this, 'render_settings_page']
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

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle regenerate action
        if (isset($_POST['sam_regenerate_keys']) && check_admin_referer('sam_regenerate_keys_action')) {
            update_option('sam_api_key', wp_generate_password(32, false));
            update_option('sam_api_secret', wp_generate_password(64, false));
            echo '<div class="notice notice-success"><p>API credentials regenerated successfully.</p></div>';
        }

        $api_key = get_option('sam_api_key', '');
        $api_secret = get_option('sam_api_secret', '');
        $rest_url = rest_url(SAM_REST_NAMESPACE);
        ?>
        <div class="wrap">
            <h1>SimpleAd Manager Connector</h1>

            <div class="card" style="max-width: 800px; padding: 20px;">
                <h2>Connection Status</h2>
                <p>
                    <strong>REST API Endpoint:</strong>
                    <code><?php echo esc_html($rest_url); ?></code>
                </p>
                <p>
                    <strong>Plugin Version:</strong>
                    <code><?php echo esc_html(SAM_VERSION); ?></code>
                </p>
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
        </div>
        <?php
    }
}
