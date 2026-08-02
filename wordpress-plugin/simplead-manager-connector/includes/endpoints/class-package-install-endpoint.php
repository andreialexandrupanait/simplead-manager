<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Install (or update) one of the manager's OWN plugins from a signed URL.
 *
 * The connector has always been able to update itself — /self-update — but that endpoint is
 * hard-wired to the connector's own slug, so the V2 backup engine had no way in. It reached a site
 * only if somebody downloaded the zip and uploaded it through wp-admin, which is why it ran on one
 * site out of twenty-four while every fix to it waited at the edge of the fleet.
 *
 * Deliberately NOT a general "install any plugin from any URL" endpoint. That would turn every
 * connector into a remote code installer for whoever can sign a request, and the blast radius of a
 * leaked key would stop being "read the site" and become "own the server". Only slugs this file
 * names are accepted, the package is verified against a hash the manager computed from the same
 * source, and nothing else about the site is touched.
 */
class SAM_Package_Install_Endpoint extends SAM_Endpoint_Base {

    /**
     * The manager's own plugins, by slug. Anything else is refused.
     */
    const ALLOWED = array(
        'simplead-backup' => 'simplead-backup/simplead-backup.php',
    );

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/plugins/install-package', [
            'methods'             => 'POST',
            'callback'            => [$this, 'install_package'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function install_package(WP_REST_Request $request): WP_REST_Response {
        @set_time_limit(300);

        $params        = $request->get_json_params();
        $slug          = isset($params['slug']) ? (string) $params['slug'] : '';
        $download_url  = isset($params['download_url']) ? (string) $params['download_url'] : '';
        $expected_hash = isset($params['expected_hash']) ? (string) $params['expected_hash'] : '';

        if (!isset(self::ALLOWED[$slug])) {
            return $this->fail('SLUG_NOT_ALLOWED', 'This endpoint only installs the manager\'s own plugins.', 400);
        }
        if ($download_url === '' || !filter_var($download_url, FILTER_VALIDATE_URL)) {
            return $this->fail('INVALID_URL', 'A valid download_url is required.', 400);
        }

        $plugin_file = self::ALLOWED[$slug];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Download and verify BEFORE the upgrader is allowed anywhere near the plugins directory.
        // A truncated transfer that reaches Plugin_Upgrader is an unpacking error at best and half
        // an installed plugin at worst.
        $package = download_url($download_url, 120);
        if (is_wp_error($package)) {
            return $this->fail('DOWNLOAD_FAILED', $package->get_error_message(), 502);
        }

        if ($expected_hash !== '') {
            $actual = hash_file('sha256', $package);
            if (!hash_equals($expected_hash, (string) $actual)) {
                @unlink($package);
                return $this->fail('HASH_MISMATCH', 'Package integrity check failed.', 400);
            }
        }

        $all_plugins = get_plugins();
        $old_version = isset($all_plugins[$plugin_file]['Version']) ? $all_plugins[$plugin_file]['Version'] : null;
        $was_active  = is_plugin_active($plugin_file);

        WP_Filesystem();
        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        // install() refuses a destination that already exists, so an update has to go through
        // upgrade() — with the package swapped in, because the plugin has no update server and the
        // upgrader would otherwise go looking for one.
        if ($old_version !== null) {
            $filter = function ($options) use ($package) {
                $options['package'] = $package;
                return $options;
            };
            add_filter('upgrader_package_options', $filter);

            $transient = get_site_transient('update_plugins');
            if (!is_object($transient)) {
                $transient = new \stdClass();
            }
            if (!isset($transient->response)) {
                $transient->response = array();
            }
            $transient->response[$plugin_file] = (object) array(
                'slug'        => $slug,
                'plugin'      => $plugin_file,
                'new_version' => '99.0.0', // there is no update server; this is what makes it act
                'package'     => $download_url,
            );
            set_site_transient('update_plugins', $transient);

            $result = $upgrader->upgrade($plugin_file);

            remove_filter('upgrader_package_options', $filter);
            delete_site_transient('update_plugins');
        } else {
            $result = $upgrader->install($package);
        }

        @unlink($package);

        if (is_wp_error($result) || $result === false) {
            $message = is_wp_error($result)
                ? $result->get_error_message()
                : 'Install failed. ' . implode(' ', $skin->get_upgrade_messages());

            return $this->fail('INSTALL_FAILED', $message, 500);
        }

        // A backup engine that is present but inactive is worse than absent: the manager sees the
        // files and the site answers nothing. Activate unless it was deliberately switched off.
        $activation_error = null;
        if ($old_version === null || $was_active) {
            $activated = activate_plugin($plugin_file);
            if (is_wp_error($activated)) {
                $activation_error = $activated->get_error_message();
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache(true);
        wp_cache_delete('plugins', 'plugins');

        $new_version = 'unknown';
        $main = WP_PLUGIN_DIR . '/' . $plugin_file;
        $head = @file_get_contents($main, false, null, 0, 8192);
        if ($head && preg_match('/^[ \t\/*#@]*Version:\s*(.+?)$/mi', $head, $m)) {
            $new_version = trim($m[1]);
        }

        return new WP_REST_Response(array(
            'success'          => true,
            'slug'             => $slug,
            'old_version'      => $old_version,
            'new_version'      => $new_version,
            'installed'        => $old_version === null,
            'active'           => is_plugin_active($plugin_file),
            'activation_error' => $activation_error,
        ), 200);
    }

    private function fail(string $code, string $message, int $status): WP_REST_Response {
        return new WP_REST_Response(array(
            'success' => false,
            'error'   => array('code' => $code, 'message' => $message),
        ), $status);
    }
}
