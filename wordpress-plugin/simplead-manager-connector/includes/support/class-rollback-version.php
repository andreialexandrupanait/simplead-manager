<?php

if (!defined('ABSPATH')) {
    // Pure helper: no WordPress dependency at load time so it is unit-testable
    // in isolation (see the manager's PluginRollbackVersionContractTest). Only
    // default_head() touches the WP HTTP API, and only when available.
}

/**
 * Resolves the exact wp.org download for a plugin/theme ROLLBACK target.
 *
 * The bug this exists to prevent (E2E Bug 2): a rollback that reinstalls the
 * LATEST wp.org release instead of the pinned pre-update version. Rollback must
 * fetch `downloads.wordpress.org/plugin/{slug}.{version}.zip` — the exact version
 * — and, if that version is no longer hosted, FAIL EXPLICITLY rather than let the
 * upgrader fall back to whatever wp.org currently serves.
 */
class SAM_Rollback_Version {

    /** Exact wp.org packaged-zip URL for a specific plugin version. */
    public static function plugin_download_url(string $slug, string $version): string {
        return 'https://downloads.wordpress.org/plugin/' . $slug . '.' . $version . '.zip';
    }

    /** Exact wp.org packaged-zip URL for a specific theme version. */
    public static function theme_download_url(string $slug, string $version): string {
        return 'https://downloads.wordpress.org/theme/' . $slug . '.' . $version . '.zip';
    }

    /**
     * Is the exact version still downloadable from wp.org? A HEAD returning 2xx
     * means yes; a 404 (version removed) or any network failure means no —
     * callers must treat "no" as an explicit rollback failure, never a silent
     * fallback to the latest release.
     *
     * @param callable|null $head Optional HEAD runner returning an HTTP status
     *                            int; injected in tests. Defaults to the real
     *                            wp.org HEAD.
     */
    public static function remote_version_available(string $url, ?callable $head = null): bool {
        $head   = $head ?? [self::class, 'default_head'];
        $status = (int) call_user_func($head, $url);

        return $status >= 200 && $status < 300;
    }

    /** Real HEAD against wp.org — WP HTTP API in WordPress, plain PHP elsewhere. */
    public static function default_head(string $url): int {
        if (function_exists('wp_remote_head')) {
            $response = wp_remote_head($url, ['timeout' => 20, 'redirection' => 3]);
            if (function_exists('is_wp_error') && is_wp_error($response)) {
                return 0;
            }

            return (int) wp_remote_retrieve_response_code($response);
        }

        // Non-WordPress fallback (unit tests): a plain HEAD request.
        $context = stream_context_create([
            'http' => ['method' => 'HEAD', 'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 1],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $headers = @get_headers($url, false, $context);
        if (!$headers || !isset($headers[0])) {
            return 0;
        }
        // The last status line wins after redirects (get_headers keeps them all).
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }
}
