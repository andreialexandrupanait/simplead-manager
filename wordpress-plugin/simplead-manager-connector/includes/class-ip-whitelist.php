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
     * Outcomes of allow_authenticated_ip().
     */
    public const RESULT_ADDED           = 'added';
    public const RESULT_ALREADY_ALLOWED = 'already_allowed';
    public const RESULT_INVALID_IP      = 'invalid_ip';

    /**
     * Record the IP of an ALREADY-AUTHENTICATED request in the whitelist.
     *
     * Callers MUST have validated the HMAC signature (SAM_Authentication::validate())
     * before calling this. Nothing here authenticates anything: it is the write side
     * of the whitelist, and an unsigned or badly signed request must never reach it.
     *
     * Additive and idempotent:
     *   - existing entries are never removed, including operator-managed CIDR ranges;
     *   - an address already covered by an exact entry, an equivalent textual form of
     *     the same address (IPv6), or a CIDR range is not added a second time;
     *   - update_option() runs only when the stored list actually changes.
     *
     * This is what makes a legitimate manager IP change self-healing: the first
     * correctly signed request from the new address adds it, and the old address
     * stays in the list so a rollback keeps working.
     *
     * @return string One of the RESULT_* constants.
     */
    public static function allow_authenticated_ip(string $ip): string {
        $ip = trim($ip);

        // Accepts IPv4 and IPv6; rejects '0.0.0.0' sentinels, CIDR and garbage.
        if (!filter_var($ip, FILTER_VALIDATE_IP) || $ip === '0.0.0.0') {
            return self::RESULT_INVALID_IP;
        }

        $stored     = self::get_whitelist();
        $normalised = self::normalize_entries($stored);

        foreach ($normalised as $entry) {
            // ip_matches() covers exact string equality and CIDR ranges;
            // same_address() covers a different textual form of the same address.
            if (self::ip_matches($ip, $entry) || self::same_address($ip, $entry)) {
                // Only persist if normalisation itself changed the stored value.
                if ($normalised !== $stored) {
                    update_option(self::OPTION_KEY, $normalised);
                }

                return self::RESULT_ALREADY_ALLOWED;
            }
        }

        $normalised[] = $ip;
        update_option(self::OPTION_KEY, $normalised);

        // Log the fact only. Never the API key, the secret or the signature.
        if (class_exists('SAM_Audit_Logger')) {
            SAM_Audit_Logger::log(
                'ip_whitelist_auto_add',
                'security',
                $ip,
                'Authenticated manager IP added to the connector REST API whitelist.'
            );
        }

        return self::RESULT_ADDED;
    }

    /**
     * Check if the current request IP is whitelisted.
     * Returns true if allowed, WP_Error if blocked.
     *
     * Since 2.19.0 this is NO LONGER the pre-authentication gate for the REST API
     * (see SAM_Endpoint_Base::check_permission) — evaluating it before the HMAC made
     * a manager IP change unrecoverable. It is retained as the pure IP verdict for
     * the admin UI and for any caller that wants the raw check.
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
     * Trim, drop empty/non-scalar values and collapse exact duplicates, without
     * reordering or rewriting the entries that survive — an operator-managed CIDR
     * range must come out of here byte-identical to how it went in.
     *
     * @param  array<int|string, mixed> $entries
     * @return list<string>
     */
    private static function normalize_entries(array $entries): array {
        $clean = [];

        foreach ($entries as $entry) {
            if (!is_string($entry) && !is_numeric($entry)) {
                continue;
            }

            $entry = trim((string) $entry);

            if ($entry === '' || in_array($entry, $clean, true)) {
                continue;
            }

            $clean[] = $entry;
        }

        return $clean;
    }

    /**
     * Whether two plain-IP strings denote the same address. Compares the packed
     * binary form, so '2001:db8::1' and '2001:0DB8:0:0:0:0:0:1' are recognised as
     * one address and never produce a duplicate entry. CIDR entries are handled by
     * ip_matches(), not here.
     */
    private static function same_address(string $ip, string $entry): bool {
        if (strpos($entry, '/') !== false || !filter_var($entry, FILTER_VALIDATE_IP)) {
            return false;
        }

        $a = @inet_pton($ip);
        $b = @inet_pton($entry);

        return $a !== false && $b !== false && $a === $b;
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
