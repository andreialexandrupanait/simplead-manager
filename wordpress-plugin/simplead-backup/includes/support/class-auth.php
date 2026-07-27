<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HMAC-SHA256 request authentication for the simplead-backup REST namespace.
 *
 * Differences from the connector's scheme (deliberate hardening):
 *   - The nonce is MANDATORY (the connector still accepts a legacy no-nonce format).
 *     A request without X-SAM-Nonce is rejected outright.
 *   - Anti-replay: nonce cached for the full timestamp window; reuse is rejected with
 *     an atomic set-if-absent (wp_cache_add) plus a durable transient fallback.
 *
 * Signed string (v2 format only): METHOD|PATH|TIMESTAMP|NONCE|BODY
 * Headers (own names first, connector names accepted as a compatibility fallback):
 *   X-SAM-Backup-Key / X-SAM-Key
 *   X-SAM-Backup-Timestamp / X-SAM-Timestamp
 *   X-SAM-Backup-Nonce / X-SAM-Nonce
 *   X-SAM-Backup-Signature / X-SAM-Signature
 */
final class SAM_Backup_Auth {

    private const TIMESTAMP_TOLERANCE = 300; // seconds
    private const NONCE_TTL           = 300; // must be >= tolerance so a replayed nonce stays blocked

    /**
     * @return true|WP_Error
     */
    public static function validate(WP_REST_Request $request) {
        $api_key   = self::header($request, array('X-SAM-Backup-Key', 'X-SAM-Key'));
        $timestamp = self::header($request, array('X-SAM-Backup-Timestamp', 'X-SAM-Timestamp'));
        $nonce     = self::header($request, array('X-SAM-Backup-Nonce', 'X-SAM-Nonce'));
        $signature = self::header($request, array('X-SAM-Backup-Signature', 'X-SAM-Signature'));

        if ($api_key === '' || $timestamp === '' || $signature === '') {
            return new WP_Error('MISSING_AUTH_HEADERS', 'Missing required authentication headers.', array('status' => 401));
        }

        // Nonce is mandatory in this plugin — no legacy path.
        if ($nonce === '') {
            return new WP_Error('MISSING_NONCE', 'A request nonce is required.', array('status' => 401));
        }

        $stored_key = SAM_Backup_Options::api_key();
        if ($stored_key === '' || !hash_equals($stored_key, $api_key)) {
            return new WP_Error('INVALID_API_KEY', 'Invalid API key.', array('status' => 401));
        }

        // Timestamp window (fast reject before crypto).
        $now = time();
        if (abs($now - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            return new WP_Error('EXPIRED_REQUEST', 'Request timestamp outside the allowed window.', array('status' => 401));
        }

        // Early replay check (before HMAC) to cheaply reject obvious replays.
        $nonce_key = 'sam_backup_nonce_' . hash('sha256', $nonce);
        if (get_transient($nonce_key)) {
            return new WP_Error('NONCE_REUSED', 'Request nonce has already been used.', array('status' => 401));
        }

        $stored_secret = SAM_Backup_Options::api_secret();
        if ($stored_secret === '') {
            return new WP_Error('SERVER_NOT_CONFIGURED', 'Backup API secret is not configured.', array('status' => 500));
        }

        $string_to_sign = implode('|', array(
            strtoupper($request->get_method()),
            $request->get_route(),
            $timestamp,
            $nonce,
            $request->get_body(),
        ));
        $expected = hash_hmac('sha256', $string_to_sign, $stored_secret);

        if (!hash_equals($expected, $signature)) {
            return new WP_Error('INVALID_SIGNATURE', 'Request signature validation failed.', array('status' => 401));
        }

        // Consume the nonce atomically only AFTER a valid signature.
        $fresh = wp_cache_add($nonce_key, 1, 'sam_backup_nonces', self::NONCE_TTL);
        if (!$fresh) {
            return new WP_Error('NONCE_REUSED', 'Request nonce has already been used.', array('status' => 401));
        }
        set_transient($nonce_key, 1, self::NONCE_TTL); // durable fallback (object cache may be non-persistent)

        return true;
    }

    /**
     * First non-empty header among the given candidate names.
     */
    private static function header(WP_REST_Request $request, array $names): string {
        foreach ($names as $name) {
            $value = $request->get_header($name);
            if (!empty($value)) {
                return (string) $value;
            }
        }
        return '';
    }
}
