<?php
/**
 * CAPTCHA enforcement on WordPress forms.
 *
 * Reads settings from sam_security_captcha option and renders/verifies
 * CAPTCHA widgets on login, registration, password reset, and comment forms.
 *
 * Supported providers: reCAPTCHA v2, reCAPTCHA v3, hCaptcha, Cloudflare Turnstile.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Security_Captcha {

    /** @var array */
    private $settings;

    /** @var string */
    private $provider;

    /** @var string */
    private $site_key;

    /** @var string */
    private $secret_key;

    /** @var array Provider configuration */
    private static $providers = [
        'recaptcha_v2' => [
            'script' => 'https://www.google.com/recaptcha/api.js',
            'verify' => 'https://www.google.com/recaptcha/api/siteverify',
            'field'  => 'g-recaptcha-response',
        ],
        'recaptcha_v3' => [
            'script' => 'https://www.google.com/recaptcha/api.js?render=',
            'verify' => 'https://www.google.com/recaptcha/api/siteverify',
            'field'  => 'g-recaptcha-response',
        ],
        'hcaptcha' => [
            'script' => 'https://js.hcaptcha.com/1/api.js',
            'verify' => 'https://hcaptcha.com/siteverify',
            'field'  => 'h-captcha-response',
        ],
        'turnstile' => [
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'field'  => 'cf-turnstile-response',
        ],
    ];

    public function __construct() {
        $this->settings = get_option('sam_security_captcha', []);

        if (empty($this->settings['enabled'])) {
            return;
        }

        $this->provider   = $this->settings['provider'] ?? '';
        $this->site_key   = $this->settings['site_key'] ?? '';
        $this->secret_key = $this->settings['secret_key'] ?? '';

        if (empty($this->provider) || empty($this->site_key) || empty($this->secret_key)) {
            return;
        }

        if (!isset(self::$providers[$this->provider])) {
            return;
        }

        $this->enforce();
    }

    private function enforce(): void {
        $forms = $this->settings['forms'] ?? [];

        if (!empty($forms['login'])) {
            add_action('login_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('login_form', [$this, 'render_widget']);
            add_filter('authenticate', [$this, 'verify_login'], 25, 3);
        }

        if (!empty($forms['register'])) {
            add_action('login_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('register_form', [$this, 'render_widget']);
            add_filter('registration_errors', [$this, 'verify_registration'], 10, 3);
        }

        if (!empty($forms['reset_password'])) {
            add_action('login_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('lostpassword_form', [$this, 'render_widget']);
            add_action('lostpassword_post', [$this, 'verify_lost_password']);
        }

        if (!empty($forms['comments'])) {
            add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
            add_action('comment_form_after_fields', [$this, 'render_widget']);
            add_action('comment_form_logged_in_after', [$this, 'render_widget']);
            add_action('pre_comment_on_post', [$this, 'verify_comment']);
        }
    }

    /**
     * Enqueue the CAPTCHA provider's script.
     */
    public function enqueue_scripts(): void {
        $config = self::$providers[$this->provider];

        if ($this->provider === 'recaptcha_v3') {
            $script_url = $config['script'] . esc_attr($this->site_key);
        } else {
            $script_url = $config['script'];
        }

        wp_enqueue_script('sam-captcha', $script_url, [], null, true);
    }

    /**
     * Render the CAPTCHA widget markup.
     */
    public function render_widget(): void {
        echo '<div style="margin: 10px 0;">';

        switch ($this->provider) {
            case 'recaptcha_v2':
                printf('<div class="g-recaptcha" data-sitekey="%s"></div>', esc_attr($this->site_key));
                break;

            case 'recaptcha_v3':
                printf(
                    '<input type="hidden" name="g-recaptcha-response" id="sam-recaptcha-token">' .
                    '<script>grecaptcha.ready(function(){grecaptcha.execute("%s",{action:"login"}).then(function(t){document.getElementById("sam-recaptcha-token").value=t;});});</script>',
                    esc_attr($this->site_key)
                );
                break;

            case 'hcaptcha':
                printf('<div class="h-captcha" data-sitekey="%s"></div>', esc_attr($this->site_key));
                break;

            case 'turnstile':
                printf('<div class="cf-turnstile" data-sitekey="%s"></div>', esc_attr($this->site_key));
                break;
        }

        echo '</div>';
    }

    /**
     * Verify CAPTCHA on login.
     */
    public function verify_login($user, string $username, string $password) {
        if (empty($username)) {
            return $user;
        }

        if (is_wp_error($user)) {
            return $user;
        }

        if (!$this->verify_token()) {
            return new WP_Error('sam_captcha_failed', 'CAPTCHA verification failed. Please try again.');
        }

        return $user;
    }

    /**
     * Verify CAPTCHA on registration.
     */
    public function verify_registration(WP_Error $errors, string $sanitized_user_login, string $user_email): WP_Error {
        if (!$this->verify_token()) {
            $errors->add('sam_captcha_failed', 'CAPTCHA verification failed. Please try again.');
        }
        return $errors;
    }

    /**
     * Verify CAPTCHA on lost password.
     */
    public function verify_lost_password(WP_Error $errors): void {
        if (!$this->verify_token()) {
            $errors->add('sam_captcha_failed', 'CAPTCHA verification failed. Please try again.');
        }
    }

    /**
     * Verify CAPTCHA on comment submission.
     */
    public function verify_comment(int $comment_post_id): void {
        if (is_user_logged_in() && current_user_can('moderate_comments')) {
            return;
        }

        if (!$this->verify_token()) {
            wp_die(
                'CAPTCHA verification failed. Please go back and try again.',
                'CAPTCHA Error',
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    /**
     * Server-side token verification against the provider's API.
     */
    private function verify_token(): bool {
        $config = self::$providers[$this->provider];
        $field = $config['field'];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $token = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';

        if (empty($token)) {
            return false;
        }

        $response = wp_remote_post($config['verify'], [
            'body' => [
                'secret'   => $this->secret_key,
                'response' => $token,
                'remoteip' => $this->get_client_ip(),
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            // Fail open if the verification service is unreachable
            return true;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body)) {
            return true; // Fail open
        }

        // All providers use 'success' => true/false
        if (empty($body['success'])) {
            return false;
        }

        // reCAPTCHA v3 has a score — check threshold
        if ($this->provider === 'recaptcha_v3') {
            $threshold = (float) ($this->settings['score_threshold'] ?? 0.5);
            if (isset($body['score']) && (float) $body['score'] < $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get client IP for verification.
     */
    private function get_client_ip(): string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    }

    /**
     * Update CAPTCHA settings from external push.
     */
    public static function update_settings(array $settings): void {
        update_option('sam_security_captcha', $settings);
    }

    /**
     * Get current CAPTCHA settings.
     */
    public static function get_settings(): array {
        return get_option('sam_security_captcha', []);
    }
}
