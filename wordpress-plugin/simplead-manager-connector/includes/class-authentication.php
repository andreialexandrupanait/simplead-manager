<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles HMAC-based authentication for all REST API requests.
 */
class SAM_Authentication {

    private const TIMESTAMP_TOLERANCE = 300; // 5 minutes

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

        // Validate HMAC signature
        $stored_secret = get_option('sam_api_secret');
        $method = strtoupper($request->get_method());
        $path = $request->get_route();
        $body = $request->get_body();

        $string_to_sign = implode('|', [
            $method,
            $path,
            $timestamp,
            $body,
        ]);

        $expected_signature = hash_hmac('sha256', $string_to_sign, $stored_secret);

        if (!hash_equals($expected_signature, $signature)) {
            return new WP_Error(
                'INVALID_SIGNATURE',
                'Request signature validation failed.',
                ['status' => 401]
            );
        }

        return true;
    }
}
