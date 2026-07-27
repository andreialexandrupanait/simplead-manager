<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal, mostly read-only WP admin page for the simplead-backup engine.
 *
 * Surfaces the local diagnostics an operator needs when the manager cannot reach a
 * site: connection/manager status, plugin version, host capabilities, current
 * backup/restore + progress/heartbeat, temp usage, disk space, last operations,
 * logs, WP-Cron + loopback status, and a redacted "support package" download.
 *
 * SECRETS: nothing on this page (or in the support package) ever exposes the API
 * key/secret or any credential — see self::redact(). PHP 7.4-safe; no shell_exec.
 */
final class SAM_Backup_Admin_Page {

    const MENU_SLUG = 'simplead-backup';

    public function register(): void {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_post_sam_backup_support_package', array($this, 'download_support_package'));
    }

    public function add_menu(): void {
        add_management_page(
            __('SimpleAd Backup', SAM_BACKUP_TEXT_DOMAIN),
            __('SimpleAd Backup', SAM_BACKUP_TEXT_DOMAIN),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render')
        );
    }

    // ── diagnostics gathering ────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    public static function diagnostics(): array {
        return array(
            'plugin' => array(
                'version'           => defined('SAM_BACKUP_VERSION') ? SAM_BACKUP_VERSION : 'unknown',
                'installed_version' => (string) SAM_Backup_Options::get('installed_version', ''),
                'rest_namespace'    => defined('SAM_BACKUP_REST_NAMESPACE') ? SAM_BACKUP_REST_NAMESPACE : '',
            ),
            'connection' => self::connection_status(),
            'manager'    => self::manager_status(),
            'capabilities' => self::capabilities(),
            'operations' => self::operations(),
            'storage'    => self::storage(),
            'wp_cron'    => self::wp_cron_status(),
            'loopback'   => self::loopback_status(),
            'options'    => self::redacted_options(),
            'generated_at' => gmdate('c'),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function connection_status(): array {
        $key = SAM_Backup_Options::api_key();
        $secret = SAM_Backup_Options::api_secret();

        return array(
            'configured'   => ($key !== '' && $secret !== ''),
            'api_key'      => self::mask($key),
            'api_secret'   => $secret === '' ? '(unset)' : '(set, redacted)',
            'nonce_window' => (int) SAM_Backup_Options::get('nonce_window', 300),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function manager_status(): array {
        $last = SAM_Backup_Options::get('last_manager_request_at', null);

        return array(
            // Recorded by the auth layer on a good HMAC request (null until first call).
            'last_request_at' => $last === null ? null : (string) $last,
            'reachable'       => $last !== null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function capabilities(): array {
        return array(
            'php_version'        => PHP_VERSION,
            'wp_version'         => function_exists('get_bloginfo') ? get_bloginfo('version') : 'unknown',
            'zip'               => class_exists('ZipArchive'),
            'gzip'              => function_exists('gzopen'),
            'mysqli'            => extension_loaded('mysqli'),
            'pdo_mysql'         => extension_loaded('pdo_mysql'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'memory_limit'      => (string) ini_get('memory_limit'),
        );
    }

    /**
     * Current + recent backup/restore activity, inferred from the plugin's private
     * session working dirs and log tail (the engine is driven by the manager).
     *
     * @return array<string,mixed>
     */
    private static function operations(): array {
        $sessionsRoot = SAM_Backup_Temp::root() . '/sessions';
        $sessions = array();
        if (is_dir($sessionsRoot)) {
            $items = @scandir($sessionsRoot);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }
                    $path = $sessionsRoot . '/' . $item;
                    if (!is_dir($path)) {
                        continue;
                    }
                    $mtime = @filemtime($path);
                    $sessions[] = array(
                        'session'   => $item,
                        'heartbeat' => $mtime ? gmdate('c', $mtime) : null,
                        'size'      => self::dir_size($path),
                    );
                }
            }
        }

        return array(
            'active_sessions' => $sessions,
            'last_operations' => SAM_Backup_Logger::tail(20),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function storage(): array {
        $root = SAM_Backup_Temp::root();
        $total = @disk_total_space($root);
        $free = SAM_Backup_Temp::free_bytes();

        return array(
            'temp_dir'         => $root,
            'temp_usage_bytes' => is_dir($root) ? self::dir_size($root) : 0,
            'disk_free_bytes'  => $free,
            'disk_total_bytes' => ($total === false) ? null : (int) $total,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function wp_cron_status(): array {
        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $next = function_exists('wp_next_scheduled') ? wp_next_scheduled('wp_version_check') : false;

        return array(
            'disabled'         => (bool) $disabled,
            'next_scheduled'   => $next ? gmdate('c', (int) $next) : null,
            'healthy'          => !$disabled,
        );
    }

    /**
     * A loopback probe — the host calling itself over HTTP. A failed loopback is a
     * classic cause of silent WP-Cron/REST breakage on locked-down hosts.
     *
     * @return array<string,mixed>
     */
    private static function loopback_status(): array {
        if (!function_exists('wp_remote_get') || !function_exists('admin_url')) {
            return array('ok' => null, 'detail' => 'HTTP API unavailable');
        }

        $url = admin_url('admin-ajax.php');
        $response = wp_remote_get($url, array('timeout' => 5, 'sslverify' => false, 'blocking' => true));

        if (is_wp_error($response)) {
            return array('ok' => false, 'detail' => $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return array(
            'ok'     => ($code > 0 && $code < 500),
            'status' => $code,
        );
    }

    /**
     * All plugin options with anything credential-shaped redacted.
     *
     * @return array<string,mixed>
     */
    private static function redacted_options(): array {
        $keys = array('installed_version', 'nonce_window', 'segment_bytes', 'time_budget', 'files_chunk_bytes', 'files_compression', 'api_key', 'api_secret');
        $out = array();
        foreach ($keys as $key) {
            $value = SAM_Backup_Options::get($key, null);
            $out[$key] = self::redact($key, $value);
        }

        return $out;
    }

    // ── rendering ────────────────────────────────────────────────────────

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view this page.', SAM_BACKUP_TEXT_DOMAIN));
        }

        $d = self::diagnostics();
        $support_url = wp_nonce_url(
            admin_url('admin-post.php?action=sam_backup_support_package'),
            'sam_backup_support_package'
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('SimpleAd Backup', SAM_BACKUP_TEXT_DOMAIN); ?>
                <span style="font-size:13px;color:#666;">v<?php echo esc_html($d['plugin']['version']); ?></span>
            </h1>

            <h2><?php echo esc_html__('Status', SAM_BACKUP_TEXT_DOMAIN); ?></h2>
            <table class="widefat striped" style="max-width:820px">
                <tbody>
                    <?php
                    self::row(__('Connection configured', SAM_BACKUP_TEXT_DOMAIN), self::yesno($d['connection']['configured']));
                    self::row(__('API key', SAM_BACKUP_TEXT_DOMAIN), $d['connection']['api_key']);
                    self::row(__('Manager reachable', SAM_BACKUP_TEXT_DOMAIN), self::yesno($d['manager']['reachable']));
                    self::row(__('WP-Cron', SAM_BACKUP_TEXT_DOMAIN), $d['wp_cron']['disabled'] ? __('DISABLED', SAM_BACKUP_TEXT_DOMAIN) : __('enabled', SAM_BACKUP_TEXT_DOMAIN));
                    self::row(__('Loopback', SAM_BACKUP_TEXT_DOMAIN), $d['loopback']['ok'] === null ? 'n/a' : ($d['loopback']['ok'] ? __('ok', SAM_BACKUP_TEXT_DOMAIN) : __('failed', SAM_BACKUP_TEXT_DOMAIN)));
                    ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Capabilities', SAM_BACKUP_TEXT_DOMAIN); ?></h2>
            <table class="widefat striped" style="max-width:820px">
                <tbody>
                    <?php
                    self::row('PHP', $d['capabilities']['php_version']);
                    self::row('WordPress', $d['capabilities']['wp_version']);
                    self::row('ZipArchive', self::yesno($d['capabilities']['zip']));
                    self::row('gzip', self::yesno($d['capabilities']['gzip']));
                    self::row('mysqli', self::yesno($d['capabilities']['mysqli']));
                    self::row('memory_limit', $d['capabilities']['memory_limit']);
                    self::row('max_execution_time', (string) $d['capabilities']['max_execution_time']);
                    ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Storage', SAM_BACKUP_TEXT_DOMAIN); ?></h2>
            <table class="widefat striped" style="max-width:820px">
                <tbody>
                    <?php
                    self::row(__('Temp usage', SAM_BACKUP_TEXT_DOMAIN), size_format((int) $d['storage']['temp_usage_bytes']));
                    self::row(__('Disk free', SAM_BACKUP_TEXT_DOMAIN), $d['storage']['disk_free_bytes'] === null ? 'n/a' : size_format((int) $d['storage']['disk_free_bytes']));
                    self::row(__('Disk total', SAM_BACKUP_TEXT_DOMAIN), $d['storage']['disk_total_bytes'] === null ? 'n/a' : size_format((int) $d['storage']['disk_total_bytes']));
                    ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Current / recent activity', SAM_BACKUP_TEXT_DOMAIN); ?></h2>
            <?php if (empty($d['operations']['active_sessions'])): ?>
                <p><?php echo esc_html__('No active backup/restore sessions.', SAM_BACKUP_TEXT_DOMAIN); ?></p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:820px">
                    <thead><tr><th><?php echo esc_html__('Session', SAM_BACKUP_TEXT_DOMAIN); ?></th><th><?php echo esc_html__('Heartbeat', SAM_BACKUP_TEXT_DOMAIN); ?></th><th><?php echo esc_html__('Size', SAM_BACKUP_TEXT_DOMAIN); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($d['operations']['active_sessions'] as $s): ?>
                            <tr>
                                <td><?php echo esc_html($s['session']); ?></td>
                                <td><?php echo esc_html((string) $s['heartbeat']); ?></td>
                                <td><?php echo esc_html(size_format((int) $s['size'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php echo esc_html__('Logs (last 20 lines)', SAM_BACKUP_TEXT_DOMAIN); ?></h2>
            <textarea readonly rows="12" style="width:100%;max-width:820px;font-family:monospace;font-size:12px;"><?php
                echo esc_textarea(implode("\n", (array) $d['operations']['last_operations']));
            ?></textarea>

            <h2><?php echo esc_html__('Support', SAM_BACKUP_TEXT_DOMAIN); ?></h2>
            <p><?php echo esc_html__('Download a diagnostics package (no secrets included) to attach to a support request.', SAM_BACKUP_TEXT_DOMAIN); ?></p>
            <a class="button button-primary" href="<?php echo esc_url($support_url); ?>"><?php echo esc_html__('Download support package', SAM_BACKUP_TEXT_DOMAIN); ?></a>
        </div>
        <?php
    }

    /**
     * Streams the redacted diagnostics as a JSON download.
     */
    public function download_support_package(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', SAM_BACKUP_TEXT_DOMAIN), '', array('response' => 403));
        }
        check_admin_referer('sam_backup_support_package');

        $payload = self::diagnostics();
        $host = function_exists('wp_parse_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : 'site';
        $filename = 'simplead-backup-support-' . preg_replace('/[^a-z0-9\.\-]/i', '', $host) . '-' . gmdate('Ymd-His') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private static function row(string $k, string $v): void {
        echo '<tr><th style="width:260px;text-align:left">' . esc_html($k) . '</th><td>' . esc_html($v) . '</td></tr>';
    }

    private static function yesno($v): string {
        return $v ? __('yes', SAM_BACKUP_TEXT_DOMAIN) : __('no', SAM_BACKUP_TEXT_DOMAIN);
    }

    /**
     * Mask a credential so only its shape is visible (never the value).
     */
    private static function mask(string $value): string {
        if ($value === '') {
            return '(unset)';
        }
        $len = strlen($value);
        if ($len <= 5) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 3) . str_repeat('*', $len - 5) . substr($value, -2);
    }

    /**
     * Redact any credential-shaped option key entirely; pass others through.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function redact(string $key, $value) {
        if (preg_match('/(key|secret|token|password|pass|credential)/i', $key)) {
            if ($key === 'api_key') {
                return self::mask((string) $value);
            }

            return '(redacted)';
        }

        return $value;
    }

    private static function dir_size(string $path): int {
        $size = 0;
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return 0;
        }
        $items = @scandir($real);
        if ($items === false) {
            return 0;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $real . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $size += self::dir_size($child);
            } elseif (is_file($child)) {
                $fs = @filesize($child);
                if ($fs !== false) {
                    $size += (int) $fs;
                }
            }
        }

        return $size;
    }
}
