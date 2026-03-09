<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * IP Whitelist for REST API requests.
 * Empty whitelist = allow all (backward compatible).
 * Supports individual IPs and CIDR notation.
 */
class SAM_IP_Whitelist {

    private const OPTION_KEY = 'sam_ip_whitelist';

    /**
     * Check if the current request IP is whitelisted.
     * Returns true if allowed, WP_Error if blocked.
     *
     * @return true|WP_Error
     */
    public static function check() {
        $whitelist = self::get_whitelist();

        // Empty whitelist = allow all
        if (empty($whitelist)) {
            return true;
        }

        $client_ip = self::get_client_ip();

        foreach ($whitelist as $entry) {
            if (self::ip_matches($client_ip, $entry)) {
                return true;
            }
        }

        return new WP_Error(
            'IP_NOT_WHITELISTED',
            'Your IP address is not authorized to access this API.',
            ['status' => 403]
        );
    }

    /**
     * Get the whitelist array.
     */
    public static function get_whitelist(): array {
        $whitelist = get_option(self::OPTION_KEY, []);
        return is_array($whitelist) ? $whitelist : [];
    }

    /**
     * Replace the entire whitelist.
     */
    public static function set_whitelist(array $ips): void {
        $clean = array_values(array_unique(array_filter(array_map('trim', $ips))));
        update_option(self::OPTION_KEY, $clean);
    }

    /**
     * Add an IP or CIDR to the whitelist.
     * Returns true on success, or an error message string.
     *
     * @return true|string
     */
    public static function add_ip(string $ip) {
        $ip = trim($ip);

        // Validate
        if (!self::is_valid_ip_or_cidr($ip)) {
            return 'Invalid IP address or CIDR notation.';
        }

        $whitelist = self::get_whitelist();

        if (in_array($ip, $whitelist, true)) {
            return 'IP address is already whitelisted.';
        }

        $whitelist[] = $ip;
        self::set_whitelist($whitelist);

        return true;
    }

    /**
     * Remove an IP or CIDR from the whitelist.
     */
    public static function remove_ip(string $ip): void {
        $whitelist = self::get_whitelist();
        $whitelist = array_values(array_diff($whitelist, [trim($ip)]));
        update_option(self::OPTION_KEY, $whitelist);
    }

    /**
     * Check if an IP matches a whitelist entry (plain IP or CIDR).
     */
    private static function ip_matches(string $ip, string $entry): bool {
        // Exact match
        if ($ip === $entry) {
            return true;
        }

        // CIDR match
        if (strpos($entry, '/') !== false) {
            return self::ip_in_cidr($ip, $entry);
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     */
    private static function ip_in_cidr(string $ip, string $cidr): bool {
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

            // Build bitmask
            $full_bytes = intdiv($mask, 8);
            $partial_bits = $mask % 8;
            $mask_bin = str_repeat("\xff", $full_bytes);
            if ($partial_bits > 0) {
                $mask_bin .= chr(256 - (1 << (8 - $partial_bits)));
            }
            $mask_bin = str_pad($mask_bin, 16, "\x00");

            return ($ip_bin & $mask_bin) === ($subnet_bin & $mask_bin);
        }

        return false;
    }

    /**
     * Validate that a string is a valid IP address or CIDR notation.
     */
    private static function is_valid_ip_or_cidr(string $value): bool {
        // Plain IP
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }

        // CIDR
        if (strpos($value, '/') !== false) {
            [$ip, $mask] = explode('/', $value, 2);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }
            $mask = (int) $mask;
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $mask >= 0 && $mask <= 32;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $mask >= 0 && $mask <= 128;
            }
        }

        return false;
    }

    /**
     * Get the client IP from proxy headers.
     */
    private static function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
