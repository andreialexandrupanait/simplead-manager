<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /cache-clear endpoint - Clears WordPress object cache, transients, and popular caching plugin caches.
 */
class SAM_Cache_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/cache-clear', [
            'methods'             => 'POST',
            'callback'            => [$this, 'clear_cache'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function clear_cache(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $cleared = [];

        // 1. WordPress object cache
        if (wp_cache_flush()) {
            $cleared[] = 'object_cache';
        }

        // 2. Expired transients
        $wpdb->query(
            "DELETE a, b FROM {$wpdb->options} a
             LEFT JOIN {$wpdb->options} b ON b.option_name = REPLACE(a.option_name, '_timeout', '')
             WHERE a.option_name LIKE '\_transient\_timeout\_%'
             AND a.option_value < UNIX_TIMESTAMP()"
        );
        $cleared[] = 'expired_transients';

        // 3. Popular caching plugins
        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $cleared[] = 'wp_super_cache';
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $cleared[] = 'w3_total_cache';
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed\Purge') && method_exists('LiteSpeed\Purge', 'purge_all')) {
            LiteSpeed\Purge::purge_all();
            $cleared[] = 'litespeed_cache';
        } elseif (defined('LSCWP_DIR') && function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
            $cleared[] = 'litespeed_cache';
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $cleared[] = 'wp_rocket';
        }

        // WP Fastest Cache
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache(true);
            $cleared[] = 'wp_fastest_cache';
        } elseif (class_exists('WpFastestCache') && method_exists('WpFastestCache', 'deleteCache')) {
            $wpfc = new WpFastestCache();
            $wpfc->deleteCache(true);
            $cleared[] = 'wp_fastest_cache';
        }

        // Autoptimize
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            autoptimizeCache::clearall();
            $cleared[] = 'autoptimize';
        }

        return $this->success([
            'cleared' => $cleared,
            'message' => 'Cache cleared successfully.',
        ]);
    }
}
