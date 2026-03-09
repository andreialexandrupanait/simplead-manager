<?php
/**
 * IP Management enforcement.
 *
 * Reads settings from sam_security_ip_management option and blocks/allows
 * IPs based on whitelist and blocklist rules. Supports CIDR notation.
 *
 * Runs on plugins_loaded (early) to block denied IPs before WordPress loads.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Security_IP_Manager {

    /** @var array */
    private $settings;

    public function __construct() {
        $this->settings = get_option('sam_security_ip_management', []);

        if (empty($this->settings['enabled'])) {
            return;
        }

        $this->enforce();
    }

    private function enforce(): void {
        $ip = $this->get_client_ip();

        // Always allow REST API requests to our endpoints (plugin needs communication)
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        if (strpos($request_uri, '/wp-json/simplead/v1/') !== false || strpos($request_uri, '/?rest_route=/simplead/v1/') !== false) {
            return;
        }

        $whitelist = $this->settings['whitelist'] ?? [];
        $blocklist = $this->settings['blocklist'] ?? [];

        // If whitelist is not empty, only whitelisted IPs are allowed
        if (!empty($whitelist)) {
            if (!$this->ip_matches_list($ip, $whitelist)) {
                $this->block_request($ip, 'IP not in whitelist');
            }
            return;
        }

        // Check blocklist
        if (!empty($blocklist) && $this->ip_matches_list($ip, $blocklist)) {
            $this->block_request($ip, 'IP is blocklisted');
        }

        // Also check brute-force banned IPs
        $banned_ips = get_option('sam_banned_ips', []);
        if (isset($banned_ips[$ip])) {
            $ban = $banned_ips[$ip];
            if (!empty($ban['expires_at']) && time() < (int) $ban['expires_at']) {
                $this->block_request($ip, 'IP banned: ' . ($ban['reason'] ?? 'brute force'));
            }
        }
    }

    /**
     * Check if an IP matches any entry in a list (supports CIDR notation).
     */
    private function ip_matches_list(string $ip, array $list): bool {
        foreach ($list as $entry) {
            $entry = trim($entry);
            if (empty($entry)) {
                continue;
            }

            // Exact match
            if ($entry === $ip) {
                return true;
            }

            // CIDR match
            if (strpos($entry, '/') !== false && $this->ip_in_cidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     */
    private function ip_in_cidr(string $ip, string $cidr): bool {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$subnet, $mask] = $parts;
        $mask = (int) $mask;

        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
            filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($mask < 0 || $mask > 32) {
                return false;
            }
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            $mask_long = -1 << (32 - $mask);
            return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
        }

        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
            filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($mask < 0 || $mask > 128) {
                return false;
            }
            $ip_bin = inet_pton($ip);
            $subnet_bin = inet_pton($subnet);
            if ($ip_bin === false || $subnet_bin === false) {
                return false;
            }

            $full_bytes = intdiv($mask, 8);
            $partial_bits = $mask % 8;

            // Compare full bytes
            for ($i = 0; $i < $full_bytes; $i++) {
                if ($ip_bin[$i] !== $subnet_bin[$i]) {
                    return false;
                }
            }

            // Compare partial byte
            if ($partial_bits > 0 && $full_bytes < 16) {
                $mask_byte = 0xFF << (8 - $partial_bits) & 0xFF;
                if ((ord($ip_bin[$full_bytes]) & $mask_byte) !== (ord($subnet_bin[$full_bytes]) & $mask_byte)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Block the request with a 403 response.
     */
    private function block_request(string $ip, string $reason): void {
        status_header(403);
        // Use wp_die if available, otherwise plain die
        if (function_exists('wp_die')) {
            wp_die(
                'Access denied.',
                'Forbidden',
                ['response' => 403]
            );
        } else {
            die('Access denied.');
        }
    }

    /**
     * Get the client's real IP address.
     */
    private function get_client_ip(): string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = isset($_SERVER[$header]) ? sanitize_text_field(wp_unslash($_SERVER[$header])) : '';
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '127.0.0.1';
    }

    /**
     * Update IP management settings from external push.
     */
    public static function update_settings(array $settings): void {
        update_option('sam_security_ip_management', $settings);
    }

    /**
     * Get current settings.
     */
    public static function get_settings(): array {
        return get_option('sam_security_ip_management', []);
    }
}
