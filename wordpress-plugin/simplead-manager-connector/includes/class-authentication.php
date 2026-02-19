<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles HMAC-based authentication for all REST API requests.
 * Supports optional nonce-based anti-replay protection (v2.0+).
 */
class SAM_Authentication {

    private const TIMESTAMP_TOLERANCE = 300; // 5 minutes
    private const NONCE_TTL = 300; // Nonce expiry matches timestamp tolerance

    /**
     * Validate an incoming REST API request.
     *
     * @return true|WP_Error
     */
    public static function validate(WP_REST_Request $request) {
        $api_key = $request->get_header('X-SAM-Key');
        $timestamp = $request->get_header('X-SAM-Timestamp');
        $signature = $request->get_header('X-SAM-Signature');

        if (empty($api_key) || empty($timestamp) || empty($signature)) {
            return new WP_Error(
                'MISSING_AUTH_HEADERS',
                'Missing required authentication headers.',
                ['status' => 401]
            );
        }

        // Validate API key
        $stored_key = get_option('sam_api_key');
        if (!hash_equals($stored_key, $api_key)) {
            return new WP_Error(
                'INVALID_API_KEY',
                'Invalid API key.',
                ['status' => 401]
            );
        }

        // Validate timestamp (prevent replay attacks)
        $current_time = time();
        $request_time = (int) $timestamp;
        if (abs($current_time - $request_time) > self::TIMESTAMP_TOLERANCE) {
            return new WP_Error(
                'EXPIRED_REQUEST',
                'Request timestamp is too old or too far in the future.',
                ['status' => 401]
            );
        }

        // Check for nonce (v2.0+ anti-replay)
        $nonce = $request->get_header('X-SAM-Nonce');
        $has_nonce = !empty($nonce);

        // If nonce is present, check for reuse BEFORE HMAC validation
        if ($has_nonce) {
            $nonce_key = 'sam_nonce_' . hash('sha256', $nonce);
            if (get_transient($nonce_key)) {
                return new WP_Error(
                    'NONCE_REUSED',
                    'Request nonce has already been used.',
                    ['status' => 401]
                );
            }
        }

        // Validate HMAC signature
        $stored_secret = get_option('sam_api_secret');
        $method = strtoupper($request->get_method());
        $path = $request->get_route();
        $body = $request->get_body();

        if ($has_nonce) {
            // v2.0 format: METHOD|PATH|TIMESTAMP|NONCE|BODY
            $string_to_sign = implode('|', [
                $method,
                $path,
                $timestamp,
                $nonce,
                $body,
            ]);
        } else {
            // Legacy format: METHOD|PATH|TIMESTAMP|BODY
            $string_to_sign = implode('|', [
                $method,
                $path,
                $timestamp,
                $body,
            ]);
        }

        $expected_signature = hash_hmac('sha256', $string_to_sign, $stored_secret);

        if (!hash_equals($expected_signature, $signature)) {
            return new WP_Error(
                'INVALID_SIGNATURE',
                'Request signature validation failed.',
                ['status' => 401]
            );
        }

        // Mark nonce as used AFTER successful HMAC validation
        if ($has_nonce) {
            set_transient($nonce_key, 1, self::NONCE_TTL);
        }

        return true;
    }
}
