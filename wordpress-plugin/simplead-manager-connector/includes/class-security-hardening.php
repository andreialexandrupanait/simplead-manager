<?php
/**
 * Runtime security hardening enforcement.
 *
 * Reads settings from the sam_security_settings option and enforces them
 * on each request via WordPress hooks and filters.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Security_Hardening {

    /** @var array */
    private $settings;

    /** @var array Htaccess settings for PHP-level enforcement (nginx compat) */
    private $htaccess_settings;

    public function __construct() {
        $this->settings = get_option('sam_security_settings', []);
        $this->htaccess_settings = get_option('sam_security_htaccess', []);

        if (empty($this->settings) && empty($this->htaccess_settings)) {
            return;
        }

        $this->enforce();
    }

    private function enforce(): void {
        // Disable theme/plugin editor
        if (!empty($this->settings['disable_theme_editor'])) {
            if (!defined('DISALLOW_FILE_EDIT')) {
                define('DISALLOW_FILE_EDIT', true);
            }
            // Fallback: hide editor menus even if constant already defined as false
            add_action('admin_init', function () {
                remove_submenu_page('themes.php', 'theme-editor.php');
                remove_submenu_page('plugins.php', 'plugin-editor.php');
            }, 999);
        }

        // Security headers
        if (!empty($this->settings['security_headers'])) {
            add_filter('wp_headers', [$this, 'set_security_headers']);
        }

        // Disable application passwords
        if (!empty($this->settings['block_application_passwords'])) {
            add_filter('wp_is_application_passwords_available', '__return_false');
        }

        // Disable XML-RPC
        if (!empty($this->settings['restrict_xmlrpc'])) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('wp_headers', function ($headers) {
                unset($headers['X-Pingback']);
                return $headers;
            });
        }

        // Restrict REST API to authenticated users
        if (!empty($this->settings['restrict_rest_api'])) {
            add_filter('rest_authentication_errors', [$this, 'restrict_rest_api']);
        }

        // Disable user enumeration
        if (!empty($this->settings['disable_user_enumeration'])) {
            add_action('init', [$this, 'block_user_enumeration'], 1);
            add_filter('rest_endpoints', [$this, 'restrict_user_rest_endpoint']);
        }

        // Hide WP version
        if (!empty($this->settings['hide_wp_version'])) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
            add_filter('style_loader_src', [$this, 'remove_version_from_assets'], 10, 2);
            add_filter('script_loader_src', [$this, 'remove_version_from_assets'], 10, 2);
            // Strip wp_version from Elementor's inline JS config
            add_action('wp_print_scripts', [$this, 'strip_version_from_inline_scripts'], 999);
            add_action('wp_print_footer_scripts', [$this, 'strip_version_from_inline_scripts'], 1);
        }

        // PHP-level enforcement of htaccess rules (nginx compatibility / defense-in-depth)
        $this->enforce_htaccess_rules();
    }

    /**
     * Enforce htaccess security rules at the PHP level.
     *
     * These rules mirror the .htaccess directives so they work on nginx
     * (which ignores .htaccess) and provide defense-in-depth on Apache/LiteSpeed.
     */
    private function enforce_htaccess_rules(): void {
        if (empty($this->htaccess_settings)) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '';

        if ($request_uri === '') {
            return;
        }

        // Never block our own REST endpoints
        $namespace = defined('SAM_REST_NAMESPACE') ? SAM_REST_NAMESPACE : 'simplead/v1';
        if (strpos($request_uri, '/wp-json/' . $namespace . '/') !== false) {
            return;
        }

        // Parse the URI path (strip query string) and get the basename
        $uri_path = strtolower(parse_url($request_uri, PHP_URL_PATH) ?: $request_uri);
        $basename = basename($uri_path);

        // Block sensitive default files
        if (!empty($this->htaccess_settings['block_default_files'])) {
            $blocked_files = ['wp-config.php', 'install.php', 'wp-settings.php', 'wp-load.php'];
            if (in_array($basename, $blocked_files, true)) {
                $this->block_request('Blocked file');
            }
        }

        // Block readme/license files
        if (!empty($this->htaccess_settings['block_readme_access'])) {
            $blocked_files = ['readme.html', 'readme.txt', 'license.txt'];
            if (in_array($basename, $blocked_files, true)) {
                $this->block_request('Blocked file');
            }
        }

        // Block debug.log access
        if (!empty($this->htaccess_settings['block_debug_log'])) {
            if (strpos($uri_path, 'debug.log') !== false) {
                $this->block_request('Blocked file');
            }
        }

        // Firewall — block SQL injection, XSS, and file inclusion via query string
        if (!empty($this->htaccess_settings['firewall_enabled'])) {
            $query_string = isset($_SERVER['QUERY_STRING'])
                ? sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING']))
                : '';

            if ($query_string !== '' && $this->is_malicious_query($query_string)) {
                $this->block_request('Firewall');
            }
        }
    }

    /**
     * Check if a query string contains malicious patterns.
     */
    private function is_malicious_query(string $query): bool {
        $patterns = [
            // SQL injection
            '/union\s+(all\s+)?select/i',
            '/concat\s*\(/i',
            '/group_concat/i',
            '/information_schema/i',
            '/into\s+(out|dump)file/i',
            '/load_file\s*\(/i',

            // XSS
            '/<script[\s>]/i',
            '/javascript\s*:/i',
            '/on(error|load|click|mouseover)\s*=/i',

            // File inclusion / traversal
            '/\.\.\//i',
            '/(etc\/passwd|proc\/self)/i',
            '/(php|data|expect|zip):\/\//i',

            // WP-specific probes
            '/wp-config\.php/i',
            '/eval\s*\(/i',
            '/base64_(encode|decode)\s*\(/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Block the request with a 403 response.
     */
    private function block_request(string $reason): void {
        status_header(403);
        if (function_exists('wp_die')) {
            wp_die(
                'Access denied.',
                '403 Forbidden',
                ['response' => 403]
            );
        }
        die('Access denied.');
    }

    /**
     * Add security headers to responses.
     */
    public function set_security_headers(array $headers): array {
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['X-Frame-Options'] = 'SAMEORIGIN';
        $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
        $headers['X-XSS-Protection'] = '1; mode=block';
        $headers['Permissions-Policy'] = 'geolocation=(), microphone=(), camera=()';
        return $headers;
    }

    /**
     * Restrict REST API to authenticated users only.
     * Allows our own SimpleAd endpoints through (they have their own auth).
     */
    public function restrict_rest_api($result) {
        if (!empty($result)) {
            return $result;
        }

        // Allow our own endpoints (use constant to match any configured namespace)
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $namespace = defined('SAM_REST_NAMESPACE') ? SAM_REST_NAMESPACE : 'simplead/v1';
        if (strpos($request_uri, '/' . $namespace . '/') !== false) {
            return $result;
        }

        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_not_logged_in',
                'REST API access is restricted to authenticated users.',
                ['status' => 401]
            );
        }

        return $result;
    }

    /**
     * Block user enumeration via ?author=N queries.
     */
    public function block_user_enumeration(): void {
        if (!is_admin() && isset($_GET['author']) && is_numeric($_GET['author'])) {
            wp_safe_redirect(home_url(), 301);
            exit;
        }
    }

    /**
     * Remove /wp/v2/users endpoint for unauthenticated requests.
     */
    public function restrict_user_rest_endpoint(array $endpoints): array {
        if (!is_user_logged_in()) {
            unset($endpoints['/wp/v2/users']);
            unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
        }
        return $endpoints;
    }

    /**
     * Strip wp_version from inline script data (e.g. Elementor's elementorCommonConfig).
     */
    public function strip_version_from_inline_scripts(): void {
        global $wp_scripts;
        if (empty($wp_scripts->registered)) {
            return;
        }

        foreach ($wp_scripts->registered as $handle => $script) {
            if (empty($script->extra['data'])) {
                continue;
            }

            $data = $script->extra['data'];
            if (strpos($data, 'wp_version') !== false) {
                $script->extra['data'] = preg_replace(
                    '/"wp_version"\s*:\s*"[^"]*"/',
                    '"wp_version":""',
                    $data
                );
            }
        }
    }

    /**
     * Remove version query strings from asset URLs.
     */
    public function remove_version_from_assets(string $src, string $handle): string {
        if (strpos($src, 'ver=') !== false) {
            $src = remove_query_arg('ver', $src);
        }
        return $src;
    }

    /**
     * Get the current settings array.
     */
    public function get_settings(): array {
        return $this->settings;
    }

    /**
     * Update settings from an external push.
     *
     * @return array Diagnostic info about server-level changes (wp-config.php, .htaccess).
     */
    public static function update_settings(array $settings): array {
        update_option('sam_security_settings', $settings);
        $diag = [];

        // disable_theme_editor → wp-config.php
        if (!empty($settings['disable_theme_editor'])) {
            $diag['wp_config'] = self::add_file_edit_constant();
        } else {
            $diag['wp_config'] = self::remove_file_edit_constant();
        }

        // disable_user_enumeration → .htaccess
        $htaccess = new SAM_Security_Htaccess();
        if (!empty($settings['disable_user_enumeration'])) {
            $success = $htaccess->add_section('block_author_scans', $htaccess->get_rule('block_author_scans'));
            $diag['author_enum_htaccess'] = $success ? 'added' : 'failed';
        } else {
            $success = $htaccess->remove_section('block_author_scans');
            $diag['author_enum_htaccess'] = $success ? 'removed' : 'not_present';
        }

        return $diag;
    }

    /**
     * Find wp-config.php — checks ABSPATH and one level above.
     */
    private static function find_wp_config(): ?string {
        $candidates = [
            ABSPATH . 'wp-config.php',
            dirname(ABSPATH) . '/wp-config.php',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Add the DISALLOW_FILE_EDIT constant to wp-config.php.
     *
     * @return array Diagnostic info about what happened.
     */
    private static function add_file_edit_constant(): array {
        $diag = ['action' => 'add_file_edit_constant'];

        $config_file = self::find_wp_config();
        if ($config_file === null) {
            $diag['result'] = 'not_found';
            return $diag;
        }

        $diag['file'] = $config_file;
        $contents = file_get_contents($config_file);

        // Already present — nothing to do
        if (strpos($contents, 'DISALLOW_FILE_EDIT') !== false) {
            $diag['result'] = 'already_exists';
            return $diag;
        }

        if (!is_writable($config_file)) {
            $diag['result'] = 'not_writable';
            $diag['permissions'] = substr(sprintf('%o', fileperms($config_file)), -4);
            return $diag;
        }

        // Insert before the "stop editing" marker
        $marker = "/* That's all, stop editing!";
        $pos = strpos($contents, $marker);
        if ($pos === false) {
            $diag['result'] = 'marker_not_found';
            return $diag;
        }

        $new_contents = substr($contents, 0, $pos)
            . "define('DISALLOW_FILE_EDIT', true);\n"
            . substr($contents, $pos);

        // Atomic write: temp file + rename
        $tmp = $config_file . '.tmp.' . uniqid();
        if (file_put_contents($tmp, $new_contents) === false) {
            $diag['result'] = 'write_failed';
            return $diag;
        }

        if (!rename($tmp, $config_file)) {
            @unlink($tmp);
            $diag['result'] = 'rename_failed';
            return $diag;
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($config_file, true);
        }

        $diag['result'] = 'added';
        return $diag;
    }

    /**
     * Remove the DISALLOW_FILE_EDIT constant from wp-config.php.
     *
     * @return array Diagnostic info about what happened.
     */
    private static function remove_file_edit_constant(): array {
        $diag = ['action' => 'remove_file_edit_constant'];

        $config_file = self::find_wp_config();
        if ($config_file === null) {
            $diag['result'] = 'not_found';
            return $diag;
        }

        $contents = file_get_contents($config_file);
        if (strpos($contents, 'DISALLOW_FILE_EDIT') === false) {
            $diag['result'] = 'not_found';
            return $diag;
        }

        $diag['file'] = $config_file;

        if (!is_writable($config_file)) {
            $diag['result'] = 'not_writable';
            $diag['permissions'] = substr(sprintf('%o', fileperms($config_file)), -4);
            return $diag;
        }

        // Remove the define line (handles various formatting)
        $new_contents = preg_replace(
            '/[ \t]*define\s*\(\s*[\'"]DISALLOW_FILE_EDIT[\'"]\s*,\s*true\s*\)\s*;[ \t]*\r?\n?/',
            '',
            $contents
        );

        if ($new_contents === $contents || $new_contents === null) {
            $diag['result'] = 'regex_no_match';
            foreach (explode("\n", $contents) as $line) {
                if (strpos($line, 'DISALLOW_FILE_EDIT') !== false) {
                    $diag['actual_line'] = $line;
                    break;
                }
            }
            return $diag;
        }

        // Atomic write: temp file + rename
        $tmp = $config_file . '.tmp.' . uniqid();
        if (file_put_contents($tmp, $new_contents) === false) {
            $diag['result'] = 'write_failed';
            return $diag;
        }

        if (!rename($tmp, $config_file)) {
            @unlink($tmp);
            $diag['result'] = 'rename_failed';
            return $diag;
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($config_file, true);
        }

        $diag['result'] = 'removed';
        return $diag;
    }

    /**
     * Get the actual enforced state (what's really active vs what's configured).
     */
    public static function get_verified_state(): array {
        $settings = get_option('sam_security_settings', []);
        $state = [];

        $state['disable_theme_editor'] = [
            'configured' => !empty($settings['disable_theme_editor']),
            'active' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
        ];

        $state['security_headers'] = [
            'configured' => !empty($settings['security_headers']),
            'active' => !empty($settings['security_headers']),
        ];

        $state['block_application_passwords'] = [
            'configured' => !empty($settings['block_application_passwords']),
            'active' => !empty($settings['block_application_passwords']),
        ];

        $xmlrpc_disabled = has_filter('xmlrpc_enabled') && !apply_filters('xmlrpc_enabled', true);
        $state['restrict_xmlrpc'] = [
            'configured' => !empty($settings['restrict_xmlrpc']),
            'active' => $xmlrpc_disabled,
        ];

        $state['restrict_rest_api'] = [
            'configured' => !empty($settings['restrict_rest_api']),
            'active' => !empty($settings['restrict_rest_api']),
        ];

        $state['disable_user_enumeration'] = [
            'configured' => !empty($settings['disable_user_enumeration']),
            'active' => !empty($settings['disable_user_enumeration']),
        ];

        $state['hide_wp_version'] = [
            'configured' => !empty($settings['hide_wp_version']),
            'active' => !empty($settings['hide_wp_version']),
        ];

        // PHP-level htaccess enforcement state
        $htaccess = get_option('sam_security_htaccess', []);
        $state['php_htaccess_enforcement'] = [
            'configured' => !empty($htaccess),
            'active' => !empty($htaccess),
            'rules' => !empty($htaccess) ? array_keys(array_filter($htaccess)) : [],
        ];

        return $state;
    }
}
