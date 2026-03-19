<?php

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Diagnostic_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/diagnostic', [
            'methods'             => 'GET',
            'callback'            => [$this, 'run_diagnostic'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/diagnostic/fix-elementor', [
            'methods'             => 'POST',
            'callback'            => [$this, 'fix_elementor'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/diagnostic/deactivate-plugin', [
            'methods'             => 'POST',
            'callback'            => [$this, 'deactivate_plugin'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/diagnostic/activate-plugin', [
            'methods'             => 'POST',
            'callback'            => [$this, 'activate_plugin'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function run_diagnostic(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $results = [];

        // 1. Check key WP options
        $options = $wpdb->get_results(
            "SELECT option_name, LEFT(option_value, 1000) as option_value FROM {$wpdb->options}
             WHERE option_name IN ('active_plugins', 'template', 'stylesheet', 'siteurl', 'home',
                                    'wp_paused_extensions', 'elementor_version', 'elementor_css_print_method')",
            OBJECT_K
        );
        $results['options'] = [];
        foreach ($options as $name => $row) {
            $results['options'][$name] = $row->option_value;
        }

        // 2. Check theme files
        $template = get_template();
        $stylesheet = get_stylesheet();
        $theme_root = get_theme_root();

        $results['theme'] = [
            'template' => $template,
            'stylesheet' => $stylesheet,
            'parent_exists' => is_dir($theme_root . '/' . $template),
            'child_exists' => is_dir($theme_root . '/' . $stylesheet),
            'parent_functions' => file_exists($theme_root . '/' . $template . '/functions.php'),
            'child_functions' => file_exists($theme_root . '/' . $stylesheet . '/functions.php'),
            'parent_style' => file_exists($theme_root . '/' . $template . '/style.css'),
            'child_style' => file_exists($theme_root . '/' . $stylesheet . '/style.css'),
        ];

        // 3. Check for PHP errors in debug.log
        $debug_log = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($debug_log)) {
            $size = filesize($debug_log);
            $tail = $size > 5000 ? file_get_contents($debug_log, false, null, $size - 5000) : file_get_contents($debug_log);
            $results['debug_log'] = ['size' => $size, 'tail' => $tail];
        } else {
            $results['debug_log'] = null;
        }

        // 4. Check PHP error log
        $error_log = ini_get('error_log');
        $results['php_error_log_path'] = $error_log;
        if ($error_log && file_exists($error_log)) {
            $size = filesize($error_log);
            $read_size = 100000;
            $tail = $size > $read_size ? file_get_contents($error_log, false, null, $size - $read_size) : file_get_contents($error_log);
            $results['php_error_log'] = ['size' => $size, 'tail' => $tail];
        } else {
            $results['php_error_log'] = null;
        }

        // 5. Try internal loopback request to homepage
        $response = wp_remote_get(home_url('/'), [
            'timeout' => 10,
            'sslverify' => false,
            'headers' => ['User-Agent' => 'SAM-Diagnostic/1.0'],
        ]);
        if (is_wp_error($response)) {
            $results['loopback'] = ['error' => $response->get_error_message()];
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $results['loopback'] = [
                'status' => $code,
                'body_length' => strlen($body),
                'body_preview' => substr($body, 0, 2000),
            ];
        }

        // 6. Check WP recovery mode / paused extensions
        $paused = get_option('wp_paused_extensions', []);
        $results['paused_extensions'] = $paused;

        return $this->success($results);
    }

    public function fix_elementor(WP_REST_Request $request): WP_REST_Response {
        $fixed = [];
        global $wpdb;

        // Get the actual installed Elementor version from plugin file
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $elementor_file = WP_PLUGIN_DIR . '/elementor/elementor.php';
        if (!file_exists($elementor_file)) {
            return $this->success(['fixed' => ['Elementor not installed']]);
        }

        $plugin_data = get_plugin_data($elementor_file);
        $installed_version = $plugin_data['Version'];
        $db_version = get_option('elementor_version', '');
        $version_mismatch = ($installed_version !== $db_version);
        $fixed[] = "installed: {$installed_version}, db: {$db_version}" . ($version_mismatch ? ' (MISMATCH)' : ' (match)');

        // Step 0: Fix null dynamic tag settings (PHP 8.x TypeError prevention)
        // Dynamic tags with settings="" cause json_decode to return null,
        // which crashes get_tag_data_content() on PHP 8.x strict typing.
        // Replace with settings="%7B%7D" which decodes to empty object {}.
        $search = 'settings=\\"\\"]';
        $replace = 'settings=\\"%7B%7D\\"]';
        $like = '%' . $wpdb->esc_like($search) . '%';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)
             WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
            $search, $replace, $like
        ));
        if ($updated > 0) {
            $fixed[] = "Fixed {$updated} posts with null dynamic tag settings";
        }

        // Step 1: Sync version numbers
        if ($version_mismatch) {
            update_option('elementor_version', '0');
            $fixed[] = 'Set elementor_version to 0 to force upgrade';

            // Try to run Elementor upgrade manager
            try {
                if (did_action('elementor/loaded') || class_exists('\Elementor\Plugin')) {
                    $elementor = \Elementor\Plugin::instance();
                    if (isset($elementor->upgrade) && method_exists($elementor->upgrade, 'do_upgrade')) {
                        $elementor->upgrade->do_upgrade();
                        $fixed[] = 'Ran Elementor upgrade routines';
                    }
                }
            } catch (\Throwable $e) {
                $fixed[] = 'Upgrade error: ' . $e->getMessage();
            }

            update_option('elementor_version', $installed_version);
            $fixed[] = "Set elementor_version to {$installed_version}";
        }

        // Step 2: Sync Elementor Pro version
        $pro_file = WP_PLUGIN_DIR . '/elementor-pro/elementor-pro.php';
        if (file_exists($pro_file)) {
            $pro_data = get_plugin_data($pro_file);
            $pro_db_version = get_option('elementor_pro_version', '');
            if ($pro_data['Version'] !== $pro_db_version) {
                update_option('elementor_pro_version', $pro_data['Version']);
                $fixed[] = "Set elementor_pro_version to {$pro_data['Version']}";
            }
        }

        // Step 3: Only clear CSS caches if versions mismatched
        // Clearing CSS forces regeneration, which can crash on pages with
        // complex dynamic tags. When versions match, keep existing CSS intact.
        if ($version_mismatch) {
            $upload_dir = wp_upload_dir();
            $elementor_css_dir = $upload_dir['basedir'] . '/elementor/css';
            if (is_dir($elementor_css_dir)) {
                $files = glob($elementor_css_dir . '/*.css');
                foreach ($files as $file) {
                    @unlink($file);
                }
                $fixed[] = 'Cleared ' . count($files) . ' Elementor CSS files';
            }

            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_css'");
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_inline_svg'");
            $fixed[] = 'Cleared _elementor_css and _elementor_inline_svg post meta';

            delete_option('_elementor_global_css');
            $fixed[] = 'Cleared Elementor global CSS option';
        } else {
            $fixed[] = 'Versions match — kept CSS caches intact';
        }

        // Step 4: Clean up stale Elementor metadata (safe, non-breaking)
        delete_option('elementor_remote_info_library');
        delete_option('elementor_scheme_color');
        delete_option('elementor_scheme_typography');
        delete_option('elementor_scheme_color-picker');
        $fixed[] = 'Cleared stale Elementor options';

        // Step 5: Ensure CSS print method is external
        // External mode generates CSS files once and serves them statically.
        // Internal mode regenerates CSS on EVERY page load, which can crash
        // if any page has dynamic tags that fail during CSS parsing.
        $css_method = get_option('elementor_css_print_method', 'external');
        if ($css_method !== 'external') {
            update_option('elementor_css_print_method', 'external');
            $fixed[] = "Changed CSS print method from {$css_method} to external";
        }

        // Step 6: Flush everything
        wp_cache_flush();
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        $fixed[] = 'Flushed object cache and OPcache';

        return $this->success(['fixed' => $fixed]);
    }

    public function deactivate_plugin(WP_REST_Request $request): WP_REST_Response {
        $plugin = $request->get_param('plugin');
        if (empty($plugin)) {
            return new WP_REST_Response(['success' => false, 'error' => 'plugin parameter required'], 400);
        }

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins($plugin);
        $is_active = is_plugin_active($plugin);

        return $this->success([
            'plugin' => $plugin,
            'deactivated' => !$is_active,
            'still_active' => $is_active,
        ]);
    }

    public function activate_plugin(WP_REST_Request $request): WP_REST_Response {
        $plugin = $request->get_param('plugin');
        if (empty($plugin)) {
            return new WP_REST_Response(['success' => false, 'error' => 'plugin parameter required'], 400);
        }

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($plugin);
        if (is_wp_error($result)) {
            return $this->success([
                'plugin' => $plugin,
                'activated' => false,
                'error' => $result->get_error_message(),
            ]);
        }

        return $this->success([
            'plugin' => $plugin,
            'activated' => true,
        ]);
    }
}
