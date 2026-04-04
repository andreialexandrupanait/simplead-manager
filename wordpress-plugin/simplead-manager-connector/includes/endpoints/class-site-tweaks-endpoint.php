<?php
/**
 * REST endpoint for pushing site tweaks settings from Laravel.
 *
 * POST /simplead/v1/site-tweaks       — Apply performance/site-control settings
 * GET  /simplead/v1/site-tweaks       — Get current applied state
 * GET  /simplead/v1/site-tweaks-state — Full state sync (all settings + actual state)
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Site_Tweaks_Endpoint extends SAM_Endpoint_Base {

    /**
     * Category → wp_option name mapping.
     */
    private const OPTION_MAP = [
        'performance'   => 'sam_performance_settings',
        'site_control'  => 'sam_site_control_settings',
        'admin_ux'      => 'sam_admin_ux_settings',
        'content_media' => 'sam_content_media_settings',
        'email'         => 'sam_email_settings',
    ];

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/site-tweaks', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'apply_settings'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'get_settings'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/site-tweaks-state', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_full_state'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/email-test', [
            'methods'             => 'POST',
            'callback'            => [$this, 'send_test_email'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/email-log', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_email_log'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/email-resend', [
            'methods'             => 'POST',
            'callback'            => [$this, 'resend_email'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Apply site tweaks settings pushed from Laravel.
     */
    public function apply_settings(WP_REST_Request $request): WP_REST_Response {
        $params  = $request->get_json_params();
        $results = [];

        foreach (self::OPTION_MAP as $category => $option_name) {
            if (!isset($params[$category])) {
                continue;
            }

            try {
                $settings = $this->sanitize_category_settings($category, $params[$category]);
                update_option($option_name, $settings);
                $results[$category] = [
                    'success' => true,
                    'applied' => array_keys(array_filter($settings, function ($v) {
                        return !empty($v);
                    })),
                ];
            } catch (\Throwable $e) {
                $results[$category] = [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        // Refresh the MU-plugin so it picks up the new settings on next request
        SAM_MU_Plugin_Manager::install();

        SAM_Audit_Logger::log('site_tweaks_applied', 'tweaks', 'settings', 'Site tweaks pushed from dashboard');

        return $this->success([
            'results'   => $results,
            'timestamp' => gmdate('c'),
        ]);
    }

    /**
     * Get current site tweaks settings.
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        $data = [];
        foreach (self::OPTION_MAP as $category => $option_name) {
            $data[$category] = get_option($option_name, []);
        }

        return $this->success($data);
    }

    /**
     * Full state sync — returns configured settings AND actual enforced state.
     */
    public function get_full_state(WP_REST_Request $request): WP_REST_Response {
        $state = [];

        foreach (self::OPTION_MAP as $category => $option_name) {
            $settings = get_option($option_name, []);
            $state[$category] = [
                'settings' => $settings,
                'active'   => !empty($settings),
            ];
        }

        // Add verified state from enforcement classes if available
        if (class_exists('SAM_Performance_Tweaks')) {
            $state['performance']['verified'] = SAM_Performance_Tweaks::get_verified_state();
        }
        if (class_exists('SAM_Site_Control')) {
            $state['site_control']['verified'] = SAM_Site_Control::get_verified_state();
        }
        if (class_exists('SAM_Admin_UX_Tweaks')) {
            $state['admin_ux']['verified'] = SAM_Admin_UX_Tweaks::get_verified_state();
        }
        if (class_exists('SAM_Content_Media_Tweaks')) {
            $state['content_media']['verified'] = SAM_Content_Media_Tweaks::get_verified_state();
        }
        if (class_exists('SAM_Email_Tweaks')) {
            $state['email']['verified'] = SAM_Email_Tweaks::get_verified_state();
        }

        $state['timestamp'] = gmdate('c');

        return $this->success($state);
    }

    /**
     * Send a test email from WordPress.
     */
    public function send_test_email(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $to = sanitize_email($params['to'] ?? '');

        if (empty($to) || !is_email($to)) {
            return $this->error('A valid email address is required.', 400);
        }

        $subject = sanitize_text_field($params['subject'] ?? 'Test Email from ' . get_bloginfo('name'));
        $body = wp_kses_post($params['body'] ?? $this->get_test_email_body());

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($to, $subject, $body, $headers);

        if ($sent) {
            SAM_Audit_Logger::log('email_test_sent', 'email', $to, "Test email sent to {$to}");
            return $this->success([
                'sent'    => true,
                'to'      => $to,
                'subject' => $subject,
            ]);
        }

        // Get the last PHPMailer error
        global $phpmailer;
        $error = '';
        if (isset($phpmailer) && $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer) {
            $error = $phpmailer->ErrorInfo;
        }

        return $this->success([
            'sent'  => false,
            'to'    => $to,
            'error' => $error ?: 'wp_mail() returned false.',
        ]);
    }

    /**
     * Get email log entries.
     */
    public function get_email_log(WP_REST_Request $request): WP_REST_Response {
        $logs = get_option('sam_email_log', []);

        return $this->success([
            'entries' => array_slice($logs, 0, 100),
            'total'   => count($logs),
        ]);
    }

    /**
     * Resend a failed email by index.
     */
    public function resend_email(WP_REST_Request $request): WP_REST_Response {
        $params = $request->get_json_params();
        $index = (int) ($params['index'] ?? -1);

        $logs = get_option('sam_email_log', []);

        if (!isset($logs[$index])) {
            return $this->error('Email log entry not found.', 404);
        }

        $entry = $logs[$index];
        $to = $entry['to'] ?? '';
        $subject = $entry['subject'] ?? '(no subject)';
        $body = $entry['body'] ?? '';

        if (empty($to)) {
            return $this->error('No recipient address in log entry.', 400);
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($to, $subject, $body ?: 'Resent email.', $headers);

        return $this->success([
            'sent'    => $sent,
            'to'      => $to,
            'subject' => $subject,
        ]);
    }

    /**
     * Generate HTML body for test emails.
     */
    private function get_test_email_body(): string {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        $time = current_time('mysql');

        return "
            <div style='font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #1a1a1a; margin-bottom: 16px;'>Email Test — {$site_name}</h2>
                <p style='color: #4a4a4a; line-height: 1.6;'>This is a test email sent from <strong>{$site_name}</strong> to verify that email delivery is working correctly.</p>
                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #eee; color: #666;'>Site</td><td style='padding: 8px; border-bottom: 1px solid #eee;'><a href='{$site_url}'>{$site_url}</a></td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #eee; color: #666;'>Sent at</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>{$time}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #eee; color: #666;'>PHP Version</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>" . phpversion() . "</td></tr>
                </table>
                <p style='color: #999; font-size: 12px;'>Sent by SimpleAd Manager</p>
            </div>
        ";
    }

    /**
     * Sanitize settings for a given category.
     */
    private function sanitize_category_settings(string $category, array $settings): array {
        $sanitized = [];

        foreach ($settings as $key => $value) {
            $key = sanitize_key($key);
            if (is_array($value)) {
                $sanitized[$key] = array_map(function ($v) {
                    return is_string($v) ? sanitize_text_field($v) : $v;
                }, $value);
            } elseif (is_bool($value)) {
                $sanitized[$key] = $value;
            } elseif (is_numeric($value)) {
                $sanitized[$key] = $value + 0; // preserve int/float
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }

        return $sanitized;
    }
}
