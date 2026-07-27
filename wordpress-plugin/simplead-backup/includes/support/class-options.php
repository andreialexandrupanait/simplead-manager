<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper over WP options with the plugin's own prefix (sam_backup_).
 *
 * The API key/secret prefer the plugin's own options. As a LAB convenience they fall
 * back to the connector's (sam_api_key / sam_api_secret) so the spike can authenticate
 * before the manager has provisioned dedicated backup credentials. In production the
 * dedicated options are always set, so the fallback is inert.
 */
final class SAM_Backup_Options {

    public static function get(string $key, $default = null) {
        return get_option(SAM_BACKUP_OPTION_PREFIX . $key, $default);
    }

    public static function set(string $key, $value): bool {
        return update_option(SAM_BACKUP_OPTION_PREFIX . $key, $value);
    }

    public static function delete(string $key): bool {
        return delete_option(SAM_BACKUP_OPTION_PREFIX . $key);
    }

    /**
     * API key: own option first, connector option as lab fallback.
     */
    public static function api_key(): string {
        $own = (string) self::get('api_key', '');
        if ($own !== '') {
            return $own;
        }
        return (string) get_option('sam_api_key', '');
    }

    /**
     * API secret: own option first, connector option as lab fallback.
     */
    public static function api_secret(): string {
        $own = (string) self::get('api_secret', '');
        if ($own !== '') {
            return $own;
        }
        return (string) get_option('sam_api_secret', '');
    }

    /**
     * Install default options on activation without clobbering existing values.
     */
    public static function install_defaults(): void {
        $defaults = array(
            'installed_version' => SAM_BACKUP_VERSION,
            'nonce_window'      => 300,      // anti-replay window in seconds
            'segment_bytes'     => 8388608,  // 8 MiB uncompressed target per gzip segment
            'time_budget'       => 90,       // soft per-run wall-clock budget (Cloudflare-aware)
            'files_chunk_bytes' => 104857600,// 100 MiB target per file chunk (single big file → own chunk)
            'files_compression' => 'store',  // STORE by default: WP payload is incompressible-dominated
        );
        foreach ($defaults as $key => $value) {
            if (self::get($key, null) === null) {
                self::set($key, $value);
            }
        }
        // Always record the current version.
        self::set('installed_version', SAM_BACKUP_VERSION);
    }
}
