<?php
/**
 * Manages the SimpleAd Security MU-plugin.
 *
 * Generates and maintains wp-content/mu-plugins/simplead-security.php so that
 * critical security settings (hardening, performance tweaks, site control) persist
 * even when the connector plugin is deactivated.
 *
 * The MU-plugin reads settings from WP options and applies hooks independently.
 * wp-config.php constants are NOT managed by the MU-plugin — those are written
 * directly by the connector plugin since they must load before plugins.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_MU_Plugin_Manager {

    /** @var string Path to the generated MU-plugin file. */
    private static string $mu_plugin_path = '';

    /**
     * Get the MU-plugin file path.
     */
    public static function get_path(): string {
        if (self::$mu_plugin_path === '') {
            self::$mu_plugin_path = WPMU_PLUGIN_DIR . '/simplead-security.php';
        }
        return self::$mu_plugin_path;
    }

    /**
     * Install or update the MU-plugin.
     * Called on plugin activation and after settings are pushed.
     *
     * @return array Diagnostic info about the operation.
     */
    public static function install(): array {
        $diag = ['action' => 'mu_plugin_install'];
        $path = self::get_path();
        $dir = dirname($path);

        // Ensure mu-plugins directory exists
        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                $diag['result'] = 'dir_create_failed';
                $diag['dir'] = $dir;
                return $diag;
            }
        }

        if (!is_writable($dir)) {
            $diag['result'] = 'dir_not_writable';
            $diag['dir'] = $dir;
            return $diag;
        }

        $content = self::generate_mu_plugin();

        // Atomic write: temp file + rename
        $tmp = $path . '.tmp.' . uniqid('', true);
        if (file_put_contents($tmp, $content) === false) {
            $diag['result'] = 'write_failed';
            return $diag;
        }
        @chmod($tmp, 0644);

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            $diag['result'] = 'rename_failed';
            return $diag;
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        $diag['result'] = 'installed';
        $diag['path'] = $path;
        return $diag;
    }

    /**
     * Remove the MU-plugin on connector plugin uninstall.
     */
    public static function uninstall(): bool {
        $path = self::get_path();
        if (file_exists($path)) {
            return @unlink($path);
        }
        return true;
    }

    /**
     * Check if the MU-plugin is installed and current.
     */
    public static function is_installed(): bool {
        return file_exists(self::get_path());
    }

    /**
     * Generate the MU-plugin PHP code.
     *
     * The generated code is self-contained: it reads from WP options and applies
     * hooks without depending on the connector plugin classes.
     */
    private static function generate_mu_plugin(): string {
        $version = defined('SAM_VERSION') ? SAM_VERSION : '0.0.0';

        $template = <<<'MUPHP'
<?php
/**
 * Plugin Name: SimpleAd Security Enforcement
 * Description: Persistent security enforcement for SimpleAd Manager. Auto-managed — do not edit.
 * Version: PLUGIN_VERSION
 * Author: SimpleAd
 *
 * This MU-plugin is generated and maintained by the SimpleAd Manager Connector plugin.
 * It ensures critical security settings remain active even if the connector is deactivated.
 *
 * Settings are read from WP options at runtime:
 *   - sam_security_settings     (hardening toggles)
 *   - sam_security_htaccess     (PHP-level htaccess rule enforcement)
 *   - sam_performance_settings  (performance tweaks)
 *   - sam_site_control_settings (site control toggles)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Skip if the connector plugin is active — it handles enforcement itself
if (defined('SAM_VERSION')) {
    return;
}

(function () {
    // ─── Hardening ──────────────────────────────────────────────────────
    $hardening = get_option('sam_security_settings', []);

    if (!empty($hardening)) {
        // Security headers
        if (!empty($hardening['security_headers'])) {
            add_filter('wp_headers', function (array $headers): array {
                $headers['X-Content-Type-Options'] = 'nosniff';
                $headers['X-Frame-Options'] = 'SAMEORIGIN';
                $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
                $headers['X-XSS-Protection'] = '1; mode=block';
                $headers['Permissions-Policy'] = 'geolocation=(), microphone=(), camera=()';
                return $headers;
            });
        }

        // Block application passwords
        if (!empty($hardening['block_application_passwords'])) {
            add_filter('wp_is_application_passwords_available', '__return_false');
        }

        // Disable XML-RPC
        if (!empty($hardening['restrict_xmlrpc'])) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('wp_headers', function (array $headers): array {
                unset($headers['X-Pingback']);
                return $headers;
            });
        }

        // Restrict REST API to authenticated users (allow SimpleAd endpoints through)
        if (!empty($hardening['restrict_rest_api'])) {
            add_filter('rest_authentication_errors', function ($result) {
                if (!empty($result)) {
                    return $result;
                }
                // Allow SimpleAd endpoints through — they use HMAC auth
                $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
                if (strpos($uri, '/wp-json/simplead/v1/') !== false) {
                    return $result;
                }
                if (!is_user_logged_in()) {
                    return new WP_Error(
                        'rest_not_logged_in',
                        'REST API access is restricted.',
                        ['status' => 401]
                    );
                }
                return $result;
            });
        }

        // Disable user enumeration
        if (!empty($hardening['disable_user_enumeration'])) {
            add_action('init', function () {
                if (!is_admin() && isset($_GET['author']) && is_numeric($_GET['author'])) {
                    wp_safe_redirect(home_url(), 301);
                    exit;
                }
            }, 1);
            add_filter('rest_endpoints', function (array $endpoints): array {
                if (!is_user_logged_in()) {
                    unset($endpoints['/wp/v2/users']);
                    unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
                }
                return $endpoints;
            });
        }

        // Hide WP version
        if (!empty($hardening['hide_wp_version'])) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
            add_filter('style_loader_src', function (string $src): string {
                return strpos($src, 'ver=') !== false ? remove_query_arg('ver', $src) : $src;
            }, 10);
            add_filter('script_loader_src', function (string $src): string {
                return strpos($src, 'ver=') !== false ? remove_query_arg('ver', $src) : $src;
            }, 10);
        }
    }

    // ─── PHP-level htaccess rule enforcement ────────────────────────────
    $htaccess = get_option('sam_security_htaccess', []);

    if (!empty($htaccess)) {
        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? strtolower(parse_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH) ?: '')
            : '';

        if ($request_uri !== '') {
            $basename = basename($request_uri);

            // Block sensitive files
            if (!empty($htaccess['block_default_files'])) {
                if (in_array($basename, ['wp-config.php', 'install.php', 'wp-settings.php', 'wp-load.php'], true)) {
                    status_header(403);
                    die('Access denied.');
                }
            }

            // Block readme/license
            if (!empty($htaccess['block_readme_access'])) {
                if (in_array($basename, ['readme.html', 'readme.txt', 'license.txt'], true)) {
                    status_header(403);
                    die('Access denied.');
                }
            }

            // Block debug log
            if (!empty($htaccess['block_debug_log'])) {
                if (strpos($request_uri, 'debug.log') !== false) {
                    status_header(403);
                    die('Access denied.');
                }
            }

            // Basic firewall — block common attack patterns in query strings
            if (!empty($htaccess['firewall_enabled'])) {
                $qs = isset($_SERVER['QUERY_STRING'])
                    ? sanitize_text_field(wp_unslash($_SERVER['QUERY_STRING']))
                    : '';
                if ($qs !== '') {
                    $patterns = [
                        '/union\s+(all\s+)?select/i',
                        '/concat\s*\(/i',
                        '/information_schema/i',
                        '/<script[\s>]/i',
                        '/javascript\s*:/i',
                        '/\.\.\//i',
                        '/(etc\/passwd|proc\/self)/i',
                        '/eval\s*\(/i',
                        '/base64_(encode|decode)\s*\(/i',
                    ];
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $qs)) {
                            status_header(403);
                            die('Access denied.');
                        }
                    }
                }
            }
        }
    }

    // ─── Performance Tweaks ─────────────────────────────────────────────
    $perf = get_option('sam_performance_settings', []);

    if (!empty($perf)) {
        // Disable emojis
        if (!empty($perf['disable_emojis'])) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_styles', 'print_emoji_styles');
            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        }

        // Disable dashicons on frontend
        if (!empty($perf['disable_dashicons'])) {
            add_action('wp_enqueue_scripts', function () {
                if (!is_user_logged_in()) {
                    wp_deregister_style('dashicons');
                }
            });
        }

        // Disable jQuery Migrate
        if (!empty($perf['disable_jquery_migrate'])) {
            add_action('wp_default_scripts', function ($scripts) {
                if (!is_admin() && isset($scripts->registered['jquery'])) {
                    $script = $scripts->registered['jquery'];
                    if ($script->deps) {
                        $script->deps = array_diff($script->deps, ['jquery-migrate']);
                    }
                }
            });
        }

        // Disable generator tag
        if (!empty($perf['disable_generator_tag'])) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
        }

        // Disable WLW Manifest
        if (!empty($perf['disable_wlw_manifest'])) {
            remove_action('wp_head', 'wlwmanifest_link');
        }

        // Disable RSD link
        if (!empty($perf['disable_rsd_link'])) {
            remove_action('wp_head', 'rsd_link');
        }

        // Disable shortlinks
        if (!empty($perf['disable_shortlinks'])) {
            remove_action('wp_head', 'wp_shortlink_wp_head');
            remove_action('template_redirect', 'wp_shortlink_header', 11);
        }

        // Disable lazy loading
        if (!empty($perf['disable_lazy_load'])) {
            add_filter('wp_lazy_loading_enabled', '__return_false');
        }

        // Disable block widgets
        if (!empty($perf['disable_block_widgets'])) {
            add_filter('gutenberg_use_widgets_block_editor', '__return_false');
            add_filter('use_widgets_block_editor', '__return_false');
        }

        // Heartbeat control
        if (!empty($perf['heartbeat_control'])) {
            $hb = is_array($perf['heartbeat_control']) ? $perf['heartbeat_control'] : [];
            $frontend = $hb['frontend'] ?? 'disable';

            if ($frontend === 'disable') {
                add_action('wp_enqueue_scripts', function () {
                    wp_deregister_script('heartbeat');
                }, 1);
            }
        }

        // Revisions limit
        if (!empty($perf['revisions_control'])) {
            $rev = is_array($perf['revisions_control']) ? $perf['revisions_control'] : [];
            $limit = (int) ($rev['limit'] ?? 5);
            add_filter('wp_revisions_to_keep', function () use ($limit) {
                return max(0, $limit);
            });
        }

        // Image upload quality
        if (!empty($perf['image_upload_control'])) {
            $img = is_array($perf['image_upload_control']) ? $perf['image_upload_control'] : [];
            if (!empty($img['jpeg_quality'])) {
                $quality = max(10, min(100, (int) $img['jpeg_quality']));
                add_filter('jpeg_quality', function () use ($quality) { return $quality; });
                add_filter('wp_editor_set_quality', function () use ($quality) { return $quality; });
            }
        }
    }

    // ─── Site Control ───────────────────────────────────────────────────
    $sc = get_option('sam_site_control_settings', []);

    if (!empty($sc)) {
        // Disable all updates
        if (!empty($sc['disable_all_updates'])) {
            add_filter('auto_update_core', '__return_false');
            add_filter('allow_major_auto_core_updates', '__return_false');
            add_filter('allow_minor_auto_core_updates', '__return_false');
            add_filter('auto_update_plugin', '__return_false');
            add_filter('auto_update_theme', '__return_false');
            add_filter('auto_update_translation', '__return_false');
        }

        // Disable comments
        if (!empty($sc['disable_comments'])) {
            add_filter('comments_open', '__return_false', 20, 2);
            add_filter('pings_open', '__return_false', 20, 2);
            add_filter('comments_array', '__return_empty_array', 10, 2);
            add_action('admin_init', function () {
                remove_menu_page('edit-comments.php');
            });
        }

        // Disable RSS feeds
        if (!empty($sc['disable_feeds'])) {
            $disable = function () { wp_die('RSS feeds are disabled.', '', ['response' => 403]); };
            add_action('do_feed', $disable, 1);
            add_action('do_feed_rdf', $disable, 1);
            add_action('do_feed_rss', $disable, 1);
            add_action('do_feed_rss2', $disable, 1);
            add_action('do_feed_atom', $disable, 1);
            remove_action('wp_head', 'feed_links', 2);
            remove_action('wp_head', 'feed_links_extra', 3);
        }

        // Disable embeds
        if (!empty($sc['disable_embeds'])) {
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('wp_head', 'wp_oembed_add_host_js');
            remove_action('rest_api_init', 'wp_oembed_register_route');
            add_filter('embed_oembed_discover', '__return_false');
            add_action('wp_footer', function () { wp_dequeue_script('wp-embed'); });
        }

        // Redirect 404 to homepage
        if (!empty($sc['redirect_404'])) {
            add_action('template_redirect', function () {
                if (is_404()) {
                    wp_safe_redirect(home_url('/'), 301);
                    exit;
                }
            });
        }

        // Disable Gutenberg
        if (!empty($sc['disable_gutenberg'])) {
            add_filter('use_block_editor_for_post', '__return_false');
            add_filter('use_block_editor_for_post_type', '__return_false');
            add_action('wp_enqueue_scripts', function () {
                wp_dequeue_style('wp-block-library');
                wp_dequeue_style('wp-block-library-theme');
                wp_dequeue_style('global-styles');
            }, 100);
        }

        // Disable author archives
        if (!empty($sc['disable_author_archives'])) {
            add_action('template_redirect', function () {
                if (is_author()) {
                    global $wp_query;
                    $wp_query->set_404();
                    status_header(404);
                    nocache_headers();
                }
            });
        }
    }
})();
MUPHP;

        return str_replace('PLUGIN_VERSION', $version, $template);
    }
}
