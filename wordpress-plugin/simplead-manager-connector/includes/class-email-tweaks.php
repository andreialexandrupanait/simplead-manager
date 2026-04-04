<?php
/**
 * Runtime email tweaks enforcement.
 *
 * Reads settings from the sam_email_settings option and enforces them
 * on each request via WordPress hooks and filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Email_Tweaks {

    /** @var array */
    private $settings;

    public function __construct() {
        $this->settings = get_option('sam_email_settings', []);
        if (empty($this->settings)) {
            return;
        }

        $this->enforce();
    }

    private function enforce(): void {
        // Custom email from
        if (!empty($this->settings['custom_email_from'])) {
            $this->enforce_custom_from();
        }

        // Postmark SMTP configuration
        if (!empty($this->settings['postmark_config'])) {
            $this->enforce_postmark();
        }

        // Email logging
        if (!empty($this->settings['email_logging'])) {
            $this->enforce_email_logging();
        }
    }

    // ─── Custom Email From ──────────────────────────────────────────────

    private function enforce_custom_from(): void {
        $config = is_array($this->settings['custom_email_from'])
            ? $this->settings['custom_email_from']
            : [];

        $from_name = $config['from_name'] ?? '';
        $from_email = $config['from_email'] ?? '';

        if (!empty($from_email)) {
            add_filter('wp_mail_from', function () use ($from_email) {
                return sanitize_email($from_email);
            });
        }

        if (!empty($from_name)) {
            add_filter('wp_mail_from_name', function () use ($from_name) {
                return sanitize_text_field($from_name);
            });
        }
    }

    // ─── Postmark SMTP ──────────────────────────────────────────────────

    private function enforce_postmark(): void {
        $config = is_array($this->settings['postmark_config'])
            ? $this->settings['postmark_config']
            : [];

        $server_token = $config['server_token'] ?? '';
        $message_stream = $config['message_stream'] ?? 'outbound';

        if (empty($server_token)) {
            return;
        }

        add_action('phpmailer_init', function ($phpmailer) use ($server_token, $message_stream) {
            /** @var PHPMailer\PHPMailer\PHPMailer $phpmailer */
            $phpmailer->isSMTP();
            $phpmailer->Host = 'smtp.postmarkapp.com';
            $phpmailer->Port = 587;
            $phpmailer->SMTPSecure = 'tls';
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $server_token;
            $phpmailer->Password = $server_token;

            // Set the message stream header
            $phpmailer->addCustomHeader('X-PM-Message-Stream', sanitize_text_field($message_stream));
        });
    }

    // ─── Email Logging ──────────────────────────────────────────────────

    private function enforce_email_logging(): void {
        // Log email before sending — capture full details
        add_filter('wp_mail', function ($args) {
            $to = is_array($args['to']) ? implode(', ', $args['to']) : $args['to'];
            $headers = $args['headers'] ?? '';
            if (is_array($headers)) {
                $headers = implode("\n", $headers);
            }
            $body = $args['message'] ?? '';

            $log_entry = [
                'to'        => $to,
                'subject'   => $args['subject'] ?? '',
                'body'      => mb_substr(wp_strip_all_tags($body), 0, 500),
                'headers'   => mb_substr($headers, 0, 1000),
                'timestamp' => current_time('mysql'),
                'status'    => 'sending',
            ];

            $logs = get_option('sam_email_log', []);
            array_unshift($logs, $log_entry);
            $logs = array_slice($logs, 0, 100); // Keep last 100
            update_option('sam_email_log', $logs, false);

            return $args;
        });

        // Log success
        add_action('wp_mail_succeeded', function ($mail_data) {
            $logs = get_option('sam_email_log', []);
            if (!empty($logs)) {
                $to = is_array($mail_data['to']) ? implode(', ', $mail_data['to']) : $mail_data['to'];
                // Find the most recent matching entry and mark as sent
                foreach ($logs as &$entry) {
                    if ($entry['to'] === $to && $entry['status'] === 'sending') {
                        $entry['status'] = 'sent';
                        break;
                    }
                }
                unset($entry);
                update_option('sam_email_log', $logs, false);
            }
        });

        // Log failure
        add_action('wp_mail_failed', function ($error) {
            $logs = get_option('sam_email_log', []);
            if (!empty($logs)) {
                $error_data = $error->get_error_data();
                $to = '';
                if (isset($error_data['to'])) {
                    $to = is_array($error_data['to']) ? implode(', ', $error_data['to']) : $error_data['to'];
                }
                // Find the most recent matching entry and mark as failed
                foreach ($logs as &$entry) {
                    if ($entry['status'] === 'sending' && ($to === '' || $entry['to'] === $to)) {
                        $entry['status'] = 'failed';
                        $entry['error'] = $error->get_error_message();
                        break;
                    }
                }
                unset($entry);
                update_option('sam_email_log', $logs, false);
            }
        });
    }

    /**
     * Get the actual enforced state.
     */
    public static function get_verified_state(): array {
        $settings = get_option('sam_email_settings', []);
        $state = [];

        $state['custom_email_from'] = [
            'configured' => !empty($settings['custom_email_from']),
            'active'     => has_filter('wp_mail_from'),
        ];

        $state['postmark_config'] = [
            'configured' => !empty($settings['postmark_config']),
            'active'     => has_action('phpmailer_init'),
        ];

        $state['email_logging'] = [
            'configured' => !empty($settings['email_logging']),
            'active'     => !empty($settings['email_logging']),
        ];

        return $state;
    }
}
