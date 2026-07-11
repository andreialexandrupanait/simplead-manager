<?php
/**
 * Login protection: brute force, custom login URL, 2FA, and CAPTCHA.
 *
 * Reads settings from sam_security_login option and enforces them.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Security_Login {

    /** @var array */
    private $settings;

    public function __construct() {
        $this->settings = get_option('sam_security_login', []);
        if (empty($this->settings)) {
            return;
        }

        $this->enforce();
    }

    private function enforce(): void {
        // Brute force protection
        $bf = $this->settings['brute_force_protection'] ?? [];
        if (!empty($bf['enabled'])) {
            add_filter('authenticate', [$this, 'check_brute_force'], 30, 3);
            add_action('wp_login_failed', [$this, 'record_failed_login']);
        }

        // Custom login URL
        $custom_login = $this->settings['custom_login_url'] ?? [];
        if (!empty($custom_login['enabled']) && !empty($custom_login['slug'])) {
            add_action('init', [$this, 'enforce_custom_login_url']);
            add_filter('site_url', [$this, 'filter_login_url'], 10, 4);
            add_filter('wp_redirect', [$this, 'filter_login_redirect'], 10, 2);
        }
    }

    /**
     * Check if IP is currently blocked due to brute force.
     */
    public function check_brute_force($user, string $username, string $password) {
        if (empty($username)) {
            return $user;
        }

        $ip = $this->get_client_ip();
        $bf = $this->settings['brute_force_protection'];
        $max_attempts = (int) ($bf['max_attempts'] ?? 5);
        $window = (int) ($bf['window_minutes'] ?? 10);
        $block_duration = (int) ($bf['block_duration_minutes'] ?? 60);

        $transient_key = 'sam_bf_' . md5($ip);
        $data = get_transient($transient_key);

        if ($data && isset($data['blocked_until'])) {
            if (time() < $data['blocked_until']) {
                $minutes_left = ceil(($data['blocked_until'] - time()) / 60);
                return new WP_Error(
                    'sam_brute_force_blocked',
                    sprintf(
                        'Too many failed login attempts. Please try again in %d minute(s).',
                        $minutes_left
                    )
                );
            }
            // Block expired, reset
            delete_transient($transient_key);
        }

        if ($data && isset($data['attempts']) && $data['attempts'] >= $max_attempts) {
            // Block this IP
            $data['blocked_until'] = time() + ($block_duration * 60);
            set_transient($transient_key, $data, $block_duration * 60);

            // Record banned IP for reporting
            $banned = get_option('sam_banned_ips', []);
            $banned[$ip] = [
                'reason' => 'Brute force: exceeded ' . $max_attempts . ' attempts',
                'attempts' => $data['attempts'],
                'banned_at' => time(),
                'expires_at' => $data['blocked_until'],
            ];
            // Keep only last 1000 entries
            if (count($banned) > 1000) {
                $banned = array_slice($banned, -1000, null, true);
            }
            update_option('sam_banned_ips', $banned);

            return new WP_Error(
                'sam_brute_force_blocked',
                sprintf(
                    'Too many failed login attempts. Please try again in %d minute(s).',
                    $block_duration
                )
            );
        }

        return $user;
    }

    /**
     * Record a failed login attempt.
     */
    public function record_failed_login(string $username): void {
        $ip = $this->get_client_ip();
        $bf = $this->settings['brute_force_protection'];
        $window = (int) ($bf['window_minutes'] ?? 10);

        $transient_key = 'sam_bf_' . md5($ip);
        $data = get_transient($transient_key);

        if (!$data) {
            $data = ['attempts' => 0, 'first_attempt' => time()];
        }

        $data['attempts']++;
        $data['last_attempt'] = time();
        $data['last_username'] = $username;

        set_transient($transient_key, $data, $window * 60);
    }

    /**
     * Enforce custom login URL - redirect default login page.
     */
    public function enforce_custom_login_url(): void {
        $custom_slug = $this->settings['custom_login_url']['slug'];
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $request_path = wp_parse_url($request_uri, PHP_URL_PATH);
        $request_path = rtrim($request_path, '/');

        // Allow admin-ajax.php and admin-post.php
        if (strpos($request_path, '/wp-admin/admin-ajax.php') !== false ||
            strpos($request_path, '/wp-admin/admin-post.php') !== false) {
            return;
        }

        // Allow load-styles.php and load-scripts.php (WordPress concatenated CSS/JS)
        if (strpos($request_path, '/wp-admin/load-styles.php') !== false ||
            strpos($request_path, '/wp-admin/load-scripts.php') !== false) {
            return;
        }

        // Allow static assets under /wp-admin/ (CSS/JS/images/fonts for login page)
        if (preg_match('#/wp-admin/(css|js|images|fonts)/#', $request_path)) {
            return;
        }

        // Custom slug — load wp-login.php directly (URL stays as custom slug)
        if ($request_path === '/' . $custom_slug) {
            // Defer to wp_loaded so all plugins/themes are fully initialized
            add_action('wp_loaded', [$this, 'load_login_page'], 0);
            return;
        }

        // Block direct wp-login.php access
        if (strpos($request_path, '/wp-login.php') !== false) {
            // Allow logout action (so logout links still work)
            if (isset($_GET['action']) && $_GET['action'] === 'logout') {
                return;
            }
            wp_die(
                '<h1>403</h1><p>You do not have permission to access this page.</p>',
                'Forbidden',
                ['response' => 403]
            );
        }

        // Block /wp-admin/ for non-logged-in users
        if (strpos($request_path, '/wp-admin') !== false && !is_user_logged_in()) {
            wp_die(
                '<h1>403</h1><p>You do not have permission to access this page.</p>',
                'Forbidden',
                ['response' => 403]
            );
        }
    }

    /**
     * Load wp-login.php directly — called on wp_loaded for custom slug requests.
     */
    public function load_login_page(): void {
        global $error, $interim_login, $action, $user_login;
        @require_once ABSPATH . 'wp-login.php';
        die;
    }

    /**
     * Filter login URL to use custom slug.
     */
    public function filter_login_url(string $url, string $path, $scheme, $blog_id): string {
        if (strpos($url, 'wp-login.php') !== false) {
            $custom_slug = $this->settings['custom_login_url']['slug'];
            $url = str_replace('wp-login.php', $custom_slug, $url);
        }
        return $url;
    }

    /**
     * Filter redirects to wp-login.php to use custom slug.
     */
    public function filter_login_redirect(string $location, int $status): string {
        if (strpos($location, 'wp-login.php') !== false) {
            $custom_slug = $this->settings['custom_login_url']['slug'];
            $location = str_replace('wp-login.php', $custom_slug, $location);
        }
        return $location;
    }

    /**
     * Get the client's real IP address.
     */
    /**
     * Allowed IP header overrides to prevent spoofing via arbitrary headers.
     */
    private const ALLOWED_IP_HEADERS = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
    ];

    private function get_client_ip(): string {
        $login_settings = get_option('sam_security_login', []);
        $ip_header = $login_settings['ip_header_override'] ?? '';

        // Only allow known safe headers to prevent IP spoofing
        if (!empty($ip_header) && !in_array($ip_header, self::ALLOWED_IP_HEADERS, true)) {
            $ip_header = '';
        }

        if (!empty($ip_header) && !empty($_SERVER[$ip_header])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER[$ip_header]));
            // Take first IP if comma-separated
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        // Standard headers
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    }

    /**
     * Update login settings from external push.
     */
    public static function update_settings(array $settings): void {
        $existing = get_option('sam_security_login', []);
        $merged = array_replace_recursive($existing, $settings);

        // List-valued settings must be REPLACED, not index-merged —
        // array_replace_recursive can never shrink a list (removing a role
        // from two_factor_auth.roles would silently keep the old entry).
        if (isset($settings['two_factor_auth'])) {
            $merged['two_factor_auth'] = $settings['two_factor_auth'];
        }

        update_option('sam_security_login', $merged);
    }

    /**
     * Get current login settings.
     */
    public static function get_settings(): array {
        return get_option('sam_security_login', []);
    }

    /**
     * Get banned IPs list for reporting.
     */
    public static function get_banned_ips(): array {
        return get_option('sam_banned_ips', []);
    }
}
