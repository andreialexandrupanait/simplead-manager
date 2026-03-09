<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transient-based rate limiter for REST API requests.
 */
class SAM_Rate_Limiter {

    private const GENERAL_LIMIT    = 60;  // requests per minute
    private const DESTRUCTIVE_LIMIT = 30; // requests per minute for destructive ops
    private const WINDOW            = 60; // seconds

    /**
     * Destructive endpoints (POST/DELETE operations that modify state significantly).
     */
    private const DESTRUCTIVE_ROUTES = [
        '/backup/db',
        '/backup/files',
        '/security-fix',
        '/db-cleanup-run',
        '/plugins/delete',
        '/plugins/update',
        '/cron-disable',
        '/cron-enable',
        '/cron-run',
        '/ip-rules/sync',
    ];

    /**
     * Check rate limit for the given API key.
     * Returns true if allowed, WP_Error if rate-limited.
     *
     * @return true|WP_Error
     */
    public static function check(WP_REST_Request $request) {
        $api_key = $request->get_header('X-SAM-Key');
        if (empty($api_key)) {
            return true; // Let auth handle missing key
        }

        $key_hash = substr(md5($api_key), 0, 12);
        $route = $request->get_route();

        // Check general rate limit
        $general_key = 'sam_rl_' . $key_hash;
        $general_count = (int) get_transient($general_key);

        if ($general_count >= self::GENERAL_LIMIT) {
            return new WP_Error(
                'RATE_LIMITED',
                'Rate limit exceeded. Maximum ' . self::GENERAL_LIMIT . ' requests per minute.',
                ['status' => 429]
            );
        }

        // Increment general counter
        if ($general_count === 0) {
            set_transient($general_key, 1, self::WINDOW);
        } else {
            set_transient($general_key, $general_count + 1, self::WINDOW);
        }

        // Check destructive rate limit
        $is_destructive = false;
        foreach (self::DESTRUCTIVE_ROUTES as $pattern) {
            if (str_ends_with($route, $pattern)) {
                $is_destructive = true;
                break;
            }
        }

        if ($is_destructive) {
            $destructive_key = 'sam_rld_' . $key_hash;
            $destructive_count = (int) get_transient($destructive_key);

            if ($destructive_count >= self::DESTRUCTIVE_LIMIT) {
                return new WP_Error(
                    'RATE_LIMITED',
                    'Rate limit exceeded for destructive operations. Maximum ' . self::DESTRUCTIVE_LIMIT . ' per minute.',
                    ['status' => 429]
                );
            }

            if ($destructive_count === 0) {
                set_transient($destructive_key, 1, self::WINDOW);
            } else {
                set_transient($destructive_key, $destructive_count + 1, self::WINDOW);
            }
        }

        return true;
    }
}
