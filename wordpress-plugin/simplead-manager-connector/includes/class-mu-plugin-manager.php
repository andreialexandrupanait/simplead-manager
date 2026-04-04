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

// OPcache invalidation — detect stale connector code via flag file
// This runs before the connector plugin loads, ensuring fresh code on next request
$_sam_flag = WP_PLUGIN_DIR . '/simplead-manager-connector/.opcache-invalidate';
if (file_exists($_sam_flag)) {
    $_sam_patterns = [
        WP_PLUGIN_DIR . '/simplead-manager-connector/*.php',
        WP_PLUGIN_DIR . '/simplead-manager-connector/includes/*.php',
        WP_PLUGIN_DIR . '/simplead-manager-connector/includes/endpoints/*.php',
    ];
    foreach ($_sam_patterns as $_sam_pat) {
        foreach (glob($_sam_pat) ?: [] as $_sam_f) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($_sam_f, true);
            }
        }
    }
    @unlink($_sam_flag);
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
                // Allow SimpleAd endpoints through first — they use HMAC auth
                // Must check BEFORE $result so we override other plugins' restrictions (e.g. ASE)
                $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
                if (strpos($uri, '/wp-json/simplead/v1/') !== false) {
                    return null;
                }
                if (!empty($result)) {
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

    // ─── Admin UX ──────────────────────────────────────────────────────
    $admin_ux = get_option('sam_admin_ux_settings', []);

    if (!empty($admin_ux)) {
        // Hide admin notices
        if (!empty($admin_ux['hide_admin_notices'])) {
            add_action('admin_notices', function () { ob_start(); }, 0);
            add_action('admin_notices', function () { ob_end_clean(); }, PHP_INT_MAX);
            add_action('all_admin_notices', function () { ob_start(); }, 0);
            add_action('all_admin_notices', function () { ob_end_clean(); }, PHP_INT_MAX);
        }

        // Clean admin bar
        if (!empty($admin_ux['clean_admin_bar'])) {
            $ab = is_array($admin_ux['clean_admin_bar']) ? $admin_ux['clean_admin_bar'] : [];
            add_action('admin_bar_menu', function ($bar) use ($ab) {
                if (!empty($ab['remove_wp_logo'])) $bar->remove_node('wp-logo');
                if (!empty($ab['remove_comments'])) $bar->remove_node('comments');
                if (!empty($ab['remove_new_content'])) $bar->remove_node('new-content');
                if (!empty($ab['remove_customize'])) $bar->remove_node('customize');
            }, 999);
        }

        // Hide admin bar on frontend
        if (!empty($admin_ux['hide_admin_bar'])) {
            $hab = is_array($admin_ux['hide_admin_bar']) ? $admin_ux['hide_admin_bar'] : [];
            $hide_for = $hab['hide_for'] ?? 'non_admins';
            add_filter('show_admin_bar', function ($show) use ($hide_for) {
                if ($hide_for === 'all') return false;
                if ($hide_for === 'non_admins' && !current_user_can('manage_options')) return false;
                if ($hide_for === 'non_editors' && !current_user_can('edit_others_posts')) return false;
                return $show;
            });
        }

        // Custom frontend CSS
        if (!empty($admin_ux['custom_frontend_css'])) {
            $cfc = is_array($admin_ux['custom_frontend_css']) ? $admin_ux['custom_frontend_css'] : [];
            $css = $cfc['css'] ?? '';
            if ($css !== '') {
                add_action('wp_head', function () use ($css) {
                    echo '<style id="sam-frontend-css">' . wp_strip_all_tags($css) . '</style>';
                });
            }
        }

        // Custom admin CSS
        if (!empty($admin_ux['custom_admin_css'])) {
            $cac = is_array($admin_ux['custom_admin_css']) ? $admin_ux['custom_admin_css'] : [];
            $css = $cac['css'] ?? '';
            if ($css !== '') {
                add_action('admin_head', function () use ($css) {
                    echo '<style id="sam-admin-css">' . wp_strip_all_tags($css) . '</style>';
                });
            }
        }

        // Wider admin menu
        if (!empty($admin_ux['wider_admin_menu'])) {
            add_action('admin_head', function () {
                echo '<style id="sam-wider-menu">@media screen and (min-width:783px){#adminmenuback,#adminmenuwrap,#adminmenu{width:200px}#wpcontent,#wpfooter{margin-left:200px}#adminmenu .wp-submenu{left:200px}}</style>';
            });
        }

        // Custom admin footer
        if (!empty($admin_ux['custom_admin_footer'])) {
            $caf = is_array($admin_ux['custom_admin_footer']) ? $admin_ux['custom_admin_footer'] : [];
            $text = $caf['text'] ?? '';
            if ($text !== '') {
                add_filter('admin_footer_text', function () use ($text) {
                    return esc_html($text);
                });
            }
        }

        // Dashboard widgets
        if (!empty($admin_ux['disable_dashboard_widgets'])) {
            $dw = is_array($admin_ux['disable_dashboard_widgets']) ? $admin_ux['disable_dashboard_widgets'] : [];
            add_action('wp_dashboard_setup', function () use ($dw) {
                if (!empty($dw['remove_welcome'])) remove_action('welcome_panel', 'wp_welcome_panel');
                if (!empty($dw['remove_quick_press'])) remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
                if (!empty($dw['remove_activity'])) remove_meta_box('dashboard_activity', 'dashboard', 'normal');
                if (!empty($dw['remove_primary'])) remove_meta_box('dashboard_primary', 'dashboard', 'side');
                if (!empty($dw['remove_events'])) remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
            }, 999);
        }

        // Admin menu organizer
        if (!empty($admin_ux['admin_menu_organizer'])) {
            $amo = is_array($admin_ux['admin_menu_organizer']) ? $admin_ux['admin_menu_organizer'] : [];
            $hidden = $amo['hidden_items'] ?? [];
            if (!empty($hidden)) {
                add_action('admin_menu', function () use ($hidden) {
                    if (current_user_can('manage_options')) return;
                    foreach ($hidden as $slug) {
                        remove_menu_page(sanitize_text_field($slug));
                    }
                }, 999);
            }
        }
    }

    // ─── Content & Media ───────────────────────────────────────────────
    $cm = get_option('sam_content_media_settings', []);

    if (!empty($cm)) {
        // SVG upload support
        if (!empty($cm['svg_upload'])) {
            add_filter('upload_mimes', function ($mimes) {
                $mimes['svg'] = 'image/svg+xml';
                $mimes['svgz'] = 'image/svg+xml';
                return $mimes;
            });
        }

        // AVIF upload support
        if (!empty($cm['avif_upload'])) {
            add_filter('upload_mimes', function ($mimes) {
                $mimes['avif'] = 'image/avif';
                return $mimes;
            });
        }

        // Open external links in new tab
        if (!empty($cm['open_external_links_new_tab'])) {
            add_filter('the_content', function ($content) {
                if (empty($content)) return $content;
                $host = wp_parse_url(home_url(), PHP_URL_HOST);
                return preg_replace_callback('/<a\s([^>]*)>/i', function ($m) use ($host) {
                    $attrs = $m[1];
                    if (!preg_match('/href\s*=\s*["\']([^"\']*)["\']/', $attrs, $h)) return $m[0];
                    $href = $h[1];
                    if (empty($href) || $href[0] === '#' || $href[0] === '/') return $m[0];
                    $lh = wp_parse_url($href, PHP_URL_HOST);
                    if (!$lh || $lh === $host) return $m[0];
                    if (preg_match('/target\s*=/i', $attrs)) return $m[0];
                    return '<a ' . $attrs . ' target="_blank" rel="noopener noreferrer">';
                }, $content);
            });
        }

        // Auto-publish missed schedule
        if (!empty($cm['auto_publish_missed_schedule'])) {
            add_action('wp_loaded', function () {
                if (get_transient('sam_missed_schedule_check')) return;
                set_transient('sam_missed_schedule_check', 1, 5 * MINUTE_IN_SECONDS);
                global $wpdb;
                $now = current_time('mysql', false);
                $posts = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'future' AND post_date <= %s LIMIT 20", $now
                ));
                if ($posts) {
                    foreach ($posts as $p) wp_publish_post((int)$p->ID);
                }
            });
        }

        // Media visibility control
        if (!empty($cm['media_visibility_control'])) {
            add_filter('ajax_query_attachments_args', function ($query) {
                if (!current_user_can('manage_options')) {
                    $query['author'] = get_current_user_id();
                }
                return $query;
            });
        }
    }

    // ─── Email ─────────────────────────────────────────────────────────
    $email = get_option('sam_email_settings', []);

    if (!empty($email)) {
        // Custom email from
        if (!empty($email['custom_email_from'])) {
            $ef = is_array($email['custom_email_from']) ? $email['custom_email_from'] : [];
            if (!empty($ef['from_email'])) {
                $fe = $ef['from_email'];
                add_filter('wp_mail_from', function () use ($fe) { return sanitize_email($fe); });
            }
            if (!empty($ef['from_name'])) {
                $fn = $ef['from_name'];
                add_filter('wp_mail_from_name', function () use ($fn) { return sanitize_text_field($fn); });
            }
        }

        // Postmark SMTP
        if (!empty($email['postmark_config'])) {
            $pm = is_array($email['postmark_config']) ? $email['postmark_config'] : [];
            $token = $pm['server_token'] ?? '';
            $stream = $pm['message_stream'] ?? 'outbound';
            if ($token !== '') {
                add_action('phpmailer_init', function ($phpmailer) use ($token, $stream) {
                    $phpmailer->isSMTP();
                    $phpmailer->Host = 'smtp.postmarkapp.com';
                    $phpmailer->Port = 587;
                    $phpmailer->SMTPSecure = 'tls';
                    $phpmailer->SMTPAuth = true;
                    $phpmailer->Username = $token;
                    $phpmailer->Password = $token;
                    $phpmailer->addCustomHeader('X-PM-Message-Stream', sanitize_text_field($stream));
                });
            }
        }

        // Email logging
        if (!empty($email['email_logging'])) {
            add_filter('wp_mail', function ($args) {
                $entry = [
                    'to' => is_array($args['to']) ? implode(', ', $args['to']) : $args['to'],
                    'subject' => $args['subject'] ?? '',
                    'timestamp' => current_time('mysql'),
                    'status' => 'sending',
                ];
                $logs = get_option('sam_email_log', []);
                array_unshift($logs, $entry);
                update_option('sam_email_log', array_slice($logs, 0, 100), false);
                return $args;
            });
        }
    }
})();
MUPHP;

        return str_replace('PLUGIN_VERSION', $version, $template);
    }
}
