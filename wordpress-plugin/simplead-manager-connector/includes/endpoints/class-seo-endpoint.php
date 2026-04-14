<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /seo endpoint - SEO analysis for the WordPress site.
 */
class SAM_SEO_Endpoint extends SAM_Endpoint_Base {

    /** Known SEO plugin slugs mapped to display names. */
    private const SEO_PLUGINS = [
        'wordpress-seo/wp-seo.php'                          => 'Yoast SEO',
        'wordpress-seo-premium/wp-seo-premium.php'          => 'Yoast SEO Premium',
        'seo-by-rank-math/rank-math.php'                    => 'Rank Math',
        'seo-by-rank-math-pro/rank-math-pro.php'            => 'Rank Math Pro',
        'all-in-one-seo-pack/all_in_one_seo_pack.php'       => 'All in One SEO',
        'all-in-one-seo-pack-pro/all_in_one_seo_pack.php'   => 'AIOSEO Pro',
        'squirrly-seo/squirrly.php'                         => 'Squirrly SEO',
        'smartcrawl-seo/wpmu-dev-seo.php'                   => 'SmartCrawl',
        'autodescription/autodescription.php'                => 'The SEO Framework',
        'jetstash-for-rank-math/jetstash-for-rank-math.php' => 'JetStash for Rank Math',
    ];

    /** Maximum pages to crawl for meta tag extraction. */
    private const MAX_CRAWL_PAGES = 10;

    /** Maximum internal links to check for broken links. */
    private const MAX_LINK_CHECKS = 20;

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/seo/analysis', [
            'methods'             => 'GET',
            'callback'            => [$this, 'analyze'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/seo/update-meta', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_meta'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/seo/update-robots', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_robots'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/seo/update-canonical', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_canonical'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/seo/update-og', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_og'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/seo/update-alt-text', [
            'methods'             => 'POST',
            'callback'            => [$this, 'update_alt_text'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/seo/toggle-search-visibility', [
            'methods'             => 'POST',
            'callback'            => [$this, 'toggle_search_visibility'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/seo/redirects', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_redirects'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/seo/redirects', [
            'methods'             => 'POST',
            'callback'            => [$this, 'create_redirect'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/seo/redirects/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete_redirect'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Update SEO meta (title, description) for a specific page/post by URL.
     */
    public function update_meta(WP_REST_Request $request): WP_REST_Response {
        $url   = esc_url_raw($request->get_param('url') ?? '');
        $title = sanitize_text_field($request->get_param('meta_title') ?? '');
        $desc  = sanitize_text_field($request->get_param('meta_description') ?? '');

        if (empty($url)) {
            return $this->error('missing_url', 'URL is required.', 400);
        }

        $post_id = $this->find_post_by_url($url);
        if (!$post_id) {
            return $this->error('post_not_found', 'No post/page found for this URL.', 404);
        }

        $updated = [];

        // Update via Yoast SEO
        if (defined('WPSEO_VERSION')) {
            if ($title) {
                update_post_meta($post_id, '_yoast_wpseo_title', $title);
                $updated['title'] = 'yoast';
            }
            if ($desc) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $desc);
                $updated['description'] = 'yoast';
            }
        }
        // Update via RankMath
        elseif (defined('RANK_MATH_VERSION')) {
            if ($title) {
                update_post_meta($post_id, 'rank_math_title', $title);
                $updated['title'] = 'rankmath';
            }
            if ($desc) {
                update_post_meta($post_id, 'rank_math_description', $desc);
                $updated['description'] = 'rankmath';
            }
        }
        // Fallback: update WordPress native title + custom meta
        else {
            if ($title) {
                wp_update_post(['ID' => $post_id, 'post_title' => $title]);
                $updated['title'] = 'wordpress';
            }
            if ($desc) {
                update_post_meta($post_id, '_sam_meta_description', $desc);
                $updated['description'] = 'custom';
            }
        }

        return $this->success([
            'post_id' => $post_id,
            'updated' => $updated,
            'permalink' => get_permalink($post_id),
        ]);
    }

    /**
     * Update meta robots (index/noindex) for a specific page/post by URL.
     */
    public function update_robots(WP_REST_Request $request): WP_REST_Response {
        $url    = esc_url_raw($request->get_param('url') ?? '');
        $action = sanitize_text_field($request->get_param('action') ?? '');

        if (empty($url)) {
            return $this->error('missing_url', 'URL is required.', 400);
        }
        if (!in_array($action, ['index', 'noindex'], true)) {
            return $this->error('invalid_action', 'Action must be "index" or "noindex".', 400);
        }

        $post_id = $this->find_post_by_url($url);
        if (!$post_id) {
            return $this->error('post_not_found', 'No post/page found for this URL.', 404);
        }

        $via = 'custom';

        if (defined('WPSEO_VERSION')) {
            // Yoast: 0 = default, 1 = noindex, 2 = index
            update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $action === 'index' ? '2' : '1');
            $via = 'yoast';
        } elseif (defined('RANK_MATH_VERSION')) {
            $robots = get_post_meta($post_id, 'rank_math_robots', true);
            if (!is_array($robots)) {
                $robots = [];
            }
            if ($action === 'noindex') {
                if (!in_array('noindex', $robots, true)) {
                    $robots[] = 'noindex';
                }
                $robots = array_values(array_diff($robots, ['index']));
            } else {
                $robots = array_values(array_diff($robots, ['noindex']));
                if (!in_array('index', $robots, true)) {
                    $robots[] = 'index';
                }
            }
            update_post_meta($post_id, 'rank_math_robots', $robots);
            $via = 'rankmath';
        } elseif (class_exists('\AIOSEO\Plugin\AIOSEO')) {
            update_post_meta($post_id, '_aioseo_noindex', $action === 'noindex' ? '1' : '0');
            $via = 'aioseo';
        } else {
            update_post_meta($post_id, '_sam_meta_robots', $action === 'index' ? 'index,follow' : 'noindex,follow');
        }

        return $this->success([
            'post_id'   => $post_id,
            'action'    => $action,
            'via'       => $via,
            'permalink' => get_permalink($post_id),
        ]);
    }

    /**
     * Update canonical URL for a specific page/post by URL.
     */
    public function update_canonical(WP_REST_Request $request): WP_REST_Response {
        $url           = esc_url_raw($request->get_param('url') ?? '');
        $canonical_url = esc_url_raw($request->get_param('canonical_url') ?? '');

        if (empty($url)) {
            return $this->error('missing_url', 'URL is required.', 400);
        }
        if (empty($canonical_url)) {
            return $this->error('missing_canonical', 'Canonical URL is required.', 400);
        }

        $post_id = $this->find_post_by_url($url);
        if (!$post_id) {
            return $this->error('post_not_found', 'No post/page found for this URL.', 404);
        }

        $via = 'custom';

        if (defined('WPSEO_VERSION')) {
            update_post_meta($post_id, '_yoast_wpseo_canonical', $canonical_url);
            $via = 'yoast';
        } elseif (defined('RANK_MATH_VERSION')) {
            update_post_meta($post_id, 'rank_math_canonical_url', $canonical_url);
            $via = 'rankmath';
        } else {
            update_post_meta($post_id, '_sam_canonical_url', $canonical_url);
        }

        return $this->success([
            'post_id'       => $post_id,
            'canonical_url' => $canonical_url,
            'via'           => $via,
            'permalink'     => get_permalink($post_id),
        ]);
    }

    /**
     * Update Open Graph tags for a specific page/post by URL.
     */
    public function update_og(WP_REST_Request $request): WP_REST_Response {
        $url            = esc_url_raw($request->get_param('url') ?? '');
        $og_title       = sanitize_text_field($request->get_param('og_title') ?? '');
        $og_description = sanitize_text_field($request->get_param('og_description') ?? '');
        $og_image       = esc_url_raw($request->get_param('og_image') ?? '');

        if (empty($url)) {
            return $this->error('missing_url', 'URL is required.', 400);
        }
        if (empty($og_title) && empty($og_description) && empty($og_image)) {
            return $this->error('no_data', 'At least one OG field is required.', 400);
        }

        $post_id = $this->find_post_by_url($url);
        if (!$post_id) {
            return $this->error('post_not_found', 'No post/page found for this URL.', 404);
        }

        $updated = [];
        $via = 'custom';

        if (defined('WPSEO_VERSION')) {
            $via = 'yoast';
            if ($og_title) {
                update_post_meta($post_id, '_yoast_wpseo_opengraph-title', $og_title);
                $updated[] = 'og_title';
            }
            if ($og_description) {
                update_post_meta($post_id, '_yoast_wpseo_opengraph-description', $og_description);
                $updated[] = 'og_description';
            }
            if ($og_image) {
                update_post_meta($post_id, '_yoast_wpseo_opengraph-image', $og_image);
                $updated[] = 'og_image';
            }
        } elseif (defined('RANK_MATH_VERSION')) {
            $via = 'rankmath';
            if ($og_title) {
                update_post_meta($post_id, 'rank_math_facebook_title', $og_title);
                $updated[] = 'og_title';
            }
            if ($og_description) {
                update_post_meta($post_id, 'rank_math_facebook_description', $og_description);
                $updated[] = 'og_description';
            }
            if ($og_image) {
                update_post_meta($post_id, 'rank_math_facebook_image', $og_image);
                $updated[] = 'og_image';
            }
        } else {
            if ($og_title) {
                update_post_meta($post_id, '_sam_og_title', $og_title);
                $updated[] = 'og_title';
            }
            if ($og_description) {
                update_post_meta($post_id, '_sam_og_description', $og_description);
                $updated[] = 'og_description';
            }
            if ($og_image) {
                update_post_meta($post_id, '_sam_og_image', $og_image);
                $updated[] = 'og_image';
            }
        }

        return $this->success([
            'post_id'       => $post_id,
            'updated'       => $updated,
            'via'           => $via,
            'permalink'     => get_permalink($post_id),
        ]);
    }

    /**
     * Update alt text for an image attachment by its URL.
     */
    public function update_alt_text(WP_REST_Request $request): WP_REST_Response {
        $image_url = esc_url_raw($request->get_param('image_url') ?? '');
        $alt_text  = sanitize_text_field($request->get_param('alt_text') ?? '');

        if (empty($image_url)) {
            return $this->error('missing_url', 'Image URL is required.', 400);
        }
        if (empty($alt_text)) {
            return $this->error('missing_alt', 'Alt text is required.', 400);
        }

        $attachment_id = attachment_url_to_postid($image_url);

        if (!$attachment_id) {
            // Try matching by filename in guid
            global $wpdb;
            $filename = basename(wp_parse_url($image_url, PHP_URL_PATH));
            if ($filename) {
                $attachment_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s LIMIT 1",
                    '%' . $wpdb->esc_like($filename)
                ));
            }
        }

        if (!$attachment_id) {
            return $this->error('attachment_not_found', 'No attachment found for this image URL.', 404);
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);

        return $this->success([
            'attachment_id' => $attachment_id,
            'alt_text'      => $alt_text,
            'image_url'     => wp_get_attachment_url($attachment_id),
        ]);
    }

    /**
     * Toggle WordPress "Discourage search engines" setting.
     */
    public function toggle_search_visibility(WP_REST_Request $request): WP_REST_Response {
        $visible = $request->get_param('visible');

        if ($visible === null) {
            return $this->error('missing_param', 'The "visible" parameter is required.', 400);
        }

        $value = filter_var($visible, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        update_option('blog_public', $value);

        return $this->success([
            'blog_public' => get_option('blog_public'),
            'visible'     => get_option('blog_public') === '1',
        ]);
    }

    /**
     * List all redirects from the active SEO/redirect plugin.
     */
    public function list_redirects(WP_REST_Request $request): WP_REST_Response {
        $redirects = [];
        $plugin    = 'none';

        // Rank Math
        if (defined('RANK_MATH_VERSION')) {
            global $wpdb;
            $table = $wpdb->prefix . 'rank_math_redirections';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
                $plugin = 'rankmath';
                $rows   = $wpdb->get_results("SELECT id, sources, url_to, header_code, status FROM {$table} ORDER BY id DESC LIMIT 200");
                foreach ($rows as $row) {
                    $sources = json_decode($row->sources, true);
                    $source  = is_array($sources) && isset($sources[0]['pattern']) ? $sources[0]['pattern'] : (string) $row->sources;
                    $redirects[] = [
                        'id'     => (int) $row->id,
                        'source' => $source,
                        'target' => $row->url_to,
                        'type'   => (int) $row->header_code,
                        'status' => $row->status,
                        'via'    => 'rankmath',
                    ];
                }
            }
        }
        // Yoast Premium
        elseif (class_exists('WPSEO_Redirect_Manager') || defined('WPSEO_PREMIUM_FILE')) {
            $plugin    = 'yoast';
            $yoast_redirects = get_option('wpseo-premium-redirects-base', []);
            if (is_array($yoast_redirects)) {
                $id = 1;
                foreach ($yoast_redirects as $source => $data) {
                    $redirects[] = [
                        'id'     => $id++,
                        'source' => $source,
                        'target' => $data['url'] ?? '',
                        'type'   => (int) ($data['type'] ?? 301),
                        'status' => 'active',
                        'via'    => 'yoast',
                    ];
                }
            }
        }
        // Redirection plugin
        elseif (class_exists('Red_Item') || defined('REDIRECTION_FILE')) {
            global $wpdb;
            $table = $wpdb->prefix . 'redirection_items';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
                $plugin = 'redirection';
                $rows   = $wpdb->get_results("SELECT id, url AS source, action_data AS target, action_code AS type, status FROM {$table} WHERE action_type = 'url' ORDER BY id DESC LIMIT 200");
                foreach ($rows as $row) {
                    $redirects[] = [
                        'id'     => (int) $row->id,
                        'source' => $row->source,
                        'target' => $row->target,
                        'type'   => (int) $row->type,
                        'status' => $row->status,
                        'via'    => 'redirection',
                    ];
                }
            }
        }

        return $this->success([
            'redirects' => $redirects,
            'plugin'    => $plugin,
            'count'     => count($redirects),
        ]);
    }

    /**
     * Create a new redirect via the active SEO/redirect plugin.
     */
    public function create_redirect(WP_REST_Request $request): WP_REST_Response {
        $source = sanitize_text_field($request->get_param('source') ?? '');
        $target = esc_url_raw($request->get_param('target') ?? '');
        $type   = (int) ($request->get_param('type') ?? 301);

        if (empty($source) || empty($target)) {
            return $this->error('missing_params', 'Source and target are required.', 400);
        }
        if (!in_array($type, [301, 302, 307, 410], true)) {
            return $this->error('invalid_type', 'Invalid redirect type. Use 301, 302, 307, or 410.', 400);
        }

        // Rank Math
        if (defined('RANK_MATH_VERSION')) {
            global $wpdb;
            $table  = $wpdb->prefix . 'rank_math_redirections';
            $sources_json = wp_json_encode([['pattern' => $source, 'comparison' => 'exact']]);
            $wpdb->insert($table, [
                'sources'     => $sources_json,
                'url_to'      => $target,
                'header_code' => $type,
                'status'      => 'active',
                'created'     => current_time('mysql'),
                'updated'     => current_time('mysql'),
            ]);
            $new_id = (int) $wpdb->insert_id;

            return $this->success(['redirect_id' => $new_id, 'source' => $source, 'target' => $target, 'type' => $type, 'via' => 'rankmath']);
        }

        // Yoast Premium
        if (class_exists('WPSEO_Redirect_Manager') || defined('WPSEO_PREMIUM_FILE')) {
            $yoast_redirects = get_option('wpseo-premium-redirects-base', []);
            if (!is_array($yoast_redirects)) {
                $yoast_redirects = [];
            }
            $yoast_redirects[$source] = ['url' => $target, 'type' => $type];
            update_option('wpseo-premium-redirects-base', $yoast_redirects);

            return $this->success(['redirect_id' => crc32($source), 'source' => $source, 'target' => $target, 'type' => $type, 'via' => 'yoast']);
        }

        // Redirection plugin
        if (class_exists('Red_Item') || defined('REDIRECTION_FILE')) {
            global $wpdb;
            $table = $wpdb->prefix . 'redirection_items';
            $wpdb->insert($table, [
                'url'         => $source,
                'action_data' => $target,
                'action_code' => $type,
                'action_type' => 'url',
                'match_type'  => 'url',
                'regex'       => 0,
                'status'      => 'enabled',
                'group_id'    => 1,
            ]);
            $new_id = (int) $wpdb->insert_id;

            return $this->success(['redirect_id' => $new_id, 'source' => $source, 'target' => $target, 'type' => $type, 'via' => 'redirection']);
        }

        return $this->error('no_plugin', 'No supported redirect plugin found. Install Rank Math, Yoast Premium, or Redirection plugin.', 400);
    }

    /**
     * Delete a redirect via the active SEO/redirect plugin.
     */
    public function delete_redirect(WP_REST_Request $request): WP_REST_Response {
        $id = (int) $request->get_param('id');

        if (!$id) {
            return $this->error('missing_id', 'Redirect ID is required.', 400);
        }

        // Rank Math
        if (defined('RANK_MATH_VERSION')) {
            global $wpdb;
            $table   = $wpdb->prefix . 'rank_math_redirections';
            $deleted = $wpdb->delete($table, ['id' => $id]);

            return $deleted ? $this->success(['deleted' => $id, 'via' => 'rankmath'])
                : $this->error('not_found', 'Redirect not found.', 404);
        }

        // Redirection plugin
        if (class_exists('Red_Item') || defined('REDIRECTION_FILE')) {
            global $wpdb;
            $table   = $wpdb->prefix . 'redirection_items';
            $deleted = $wpdb->delete($table, ['id' => $id]);

            return $deleted ? $this->success(['deleted' => $id, 'via' => 'redirection'])
                : $this->error('not_found', 'Redirect not found.', 404);
        }

        return $this->error('no_plugin', 'No supported redirect plugin found.', 400);
    }

    /**
     * Find a post/page by URL with multiple fallback strategies.
     */
    private function find_post_by_url(string $url): int {
        $post_id = url_to_postid($url);
        if (!$post_id) {
            $post_id = url_to_postid(trailingslashit($url));
        }
        if (!$post_id) {
            if (rtrim($url, '/') === rtrim(home_url(), '/')) {
                $post_id = (int) get_option('page_on_front');
            }
        }

        return $post_id;
    }

    public function analyze(WP_REST_Request $request): WP_REST_Response {
        $start = microtime(true);

        $seo_plugin     = $this->detect_seo_plugin();
        $robots_txt     = $this->check_robots_txt();
        $sitemaps       = $this->check_sitemaps();
        $homepage       = $this->crawl_page(home_url('/'));
        $pages          = $this->crawl_top_pages();
        $redirects      = $this->check_redirects();
        $broken_links   = $this->check_broken_links($homepage);
        $search_engines = $this->check_search_engine_visibility();

        return $this->success([
            'seo_plugin'        => $seo_plugin,
            'robots_txt'        => $robots_txt,
            'sitemaps'          => $sitemaps,
            'homepage'          => $homepage,
            'pages'             => $pages,
            'redirects'         => $redirects,
            'broken_links'      => $broken_links,
            'search_visibility' => $search_engines,
            'scan_duration_ms'  => (int) round((microtime(true) - $start) * 1000),
        ]);
    }

    /**
     * Detect active SEO plugin.
     */
    private function detect_seo_plugin(): ?array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_plugins = get_option('active_plugins', []);
        $all_plugins    = get_plugins();

        foreach (self::SEO_PLUGINS as $file => $display_name) {
            if (in_array($file, $active_plugins, true) && isset($all_plugins[$file])) {
                return [
                    'name'    => $display_name,
                    'file'    => $file,
                    'version' => $all_plugins[$file]['Version'] ?? 'unknown',
                    'active'  => true,
                ];
            }
        }

        // Check for any inactive SEO plugin
        foreach (self::SEO_PLUGINS as $file => $display_name) {
            if (isset($all_plugins[$file])) {
                return [
                    'name'    => $display_name,
                    'file'    => $file,
                    'version' => $all_plugins[$file]['Version'] ?? 'unknown',
                    'active'  => false,
                ];
            }
        }

        return null;
    }

    /**
     * Check robots.txt content and validity.
     */
    private function check_robots_txt(): array {
        $result = [
            'exists'           => false,
            'content'          => null,
            'allows_crawling'  => true,
            'has_sitemap'      => false,
            'sitemap_urls'     => [],
            'blocks_all'       => false,
            'issues'           => [],
        ];

        $response = wp_remote_get(home_url('/robots.txt'), [
            'timeout'   => 5,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'SAM-SEO/1.0'],
        ]);

        if (is_wp_error($response)) {
            $result['issues'][] = 'Could not fetch robots.txt: ' . $response->get_error_message();
            return $result;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $result['issues'][] = "robots.txt returned HTTP {$code}";
            return $result;
        }

        $body = wp_remote_retrieve_body($response);
        $result['exists']  = true;
        $result['content'] = substr($body, 0, 5000);

        // Parse for sitemaps
        if (preg_match_all('/^Sitemap:\s*(.+)$/mi', $body, $matches)) {
            $result['has_sitemap']  = true;
            $result['sitemap_urls'] = array_map('trim', $matches[1]);
        }

        // Check if all crawling is blocked
        if (preg_match('/^User-agent:\s*\*\s*\nDisallow:\s*\/\s*$/mi', $body)) {
            $result['blocks_all']      = true;
            $result['allows_crawling'] = false;
            $result['issues'][]        = 'robots.txt blocks all crawlers with Disallow: /';
        }

        if (!$result['has_sitemap']) {
            $result['issues'][] = 'No Sitemap directive found in robots.txt';
        }

        return $result;
    }

    /**
     * Check common sitemap locations.
     */
    private function check_sitemaps(): array {
        $candidates = [
            home_url('/sitemap_index.xml'),
            home_url('/sitemap.xml'),
            home_url('/wp-sitemap.xml'),
        ];

        $found = [];

        foreach ($candidates as $url) {
            $response = wp_remote_get($url, [
                'timeout'   => 5,
                'sslverify' => false,
                'headers'   => ['User-Agent' => 'SAM-SEO/1.0'],
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                continue;
            }

            $body   = wp_remote_retrieve_body($response);
            $is_xml = (strpos($body, '<?xml') !== false || strpos($body, '<urlset') !== false || strpos($body, '<sitemapindex') !== false);

            if (!$is_xml) {
                continue;
            }

            $url_count = 0;
            $is_index  = (strpos($body, '<sitemapindex') !== false);

            if ($is_index) {
                $url_count = substr_count($body, '<sitemap>');
            } else {
                $url_count = substr_count($body, '<url>');
            }

            $found[] = [
                'url'       => $url,
                'is_index'  => $is_index,
                'url_count' => $url_count,
                'size'      => strlen($body),
            ];
        }

        return [
            'found'  => !empty($found),
            'maps'   => $found,
            'issues' => empty($found) ? ['No XML sitemap found at common locations'] : [],
        ];
    }

    /**
     * Crawl a single page and extract SEO-relevant data.
     */
    private function crawl_page(string $url): ?array {
        $response = wp_remote_get($url, [
            'timeout'   => 10,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'SAM-SEO/1.0'],
        ]);

        if (is_wp_error($response)) {
            return ['url' => $url, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return ['url' => $url, 'status' => $code, 'error' => "HTTP {$code}"];
        }

        return $this->extract_seo_data($url, $body);
    }

    /**
     * Crawl top published pages/posts for meta tag analysis.
     */
    private function crawl_top_pages(): array {
        $pages = [];
        $urls  = [];

        // Get top pages by type
        $post_types = ['page', 'post'];
        foreach ($post_types as $type) {
            $posts = get_posts([
                'post_type'      => $type,
                'post_status'    => 'publish',
                'posts_per_page' => 3,
                'orderby'        => 'date',
                'order'          => 'DESC',
            ]);
            foreach ($posts as $post) {
                $url = get_permalink($post);
                if ($url && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        // Limit total pages crawled
        $urls = array_slice($urls, 0, self::MAX_CRAWL_PAGES - 1); // -1 because homepage is separate

        foreach ($urls as $url) {
            $data = $this->crawl_page($url);
            if ($data) {
                $pages[] = $data;
            }
        }

        return $pages;
    }

    /**
     * Extract SEO data from HTML body.
     */
    private function extract_seo_data(string $url, string $body): array {
        $data = [
            'url'             => $url,
            'status'          => 200,
            'title'           => null,
            'title_length'    => 0,
            'meta_description'=> null,
            'meta_desc_length'=> 0,
            'meta_robots'     => null,
            'canonical'       => null,
            'canonical_valid' => false,
            'og_title'        => null,
            'og_description'  => null,
            'og_image'        => null,
            'twitter_card'    => null,
            'h1_tags'         => [],
            'h2_count'        => 0,
            'image_count'     => 0,
            'images_without_alt' => 0,
            'word_count'      => 0,
            'internal_links'  => 0,
            'external_links'  => 0,
            'structured_data' => [],
            'issues'          => [],
        ];

        // Suppress HTML parsing errors
        libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $body, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        // Title tag
        $titles = $doc->getElementsByTagName('title');
        if ($titles->length > 0) {
            $data['title'] = trim($titles->item(0)->textContent);
            $data['title_length'] = mb_strlen($data['title']);
        }

        // Meta tags
        $metas = $doc->getElementsByTagName('meta');
        foreach ($metas as $meta) {
            $name     = strtolower($meta->getAttribute('name'));
            $property = strtolower($meta->getAttribute('property'));
            $content  = $meta->getAttribute('content');

            if ($name === 'description') {
                $data['meta_description'] = $content;
                $data['meta_desc_length'] = mb_strlen($content);
            } elseif ($name === 'robots') {
                $data['meta_robots'] = $content;
            } elseif ($property === 'og:title') {
                $data['og_title'] = $content;
            } elseif ($property === 'og:description') {
                $data['og_description'] = $content;
            } elseif ($property === 'og:image') {
                $data['og_image'] = $content;
            } elseif ($name === 'twitter:card') {
                $data['twitter_card'] = $content;
            }
        }

        // Canonical link
        $links = $doc->getElementsByTagName('link');
        foreach ($links as $link) {
            if (strtolower($link->getAttribute('rel')) === 'canonical') {
                $data['canonical'] = $link->getAttribute('href');
                $data['canonical_valid'] = filter_var($data['canonical'], FILTER_VALIDATE_URL) !== false;
                break;
            }
        }

        // Headings
        $h1s = $doc->getElementsByTagName('h1');
        for ($i = 0; $i < $h1s->length; $i++) {
            $data['h1_tags'][] = trim($h1s->item($i)->textContent);
        }
        $data['h2_count'] = $doc->getElementsByTagName('h2')->length;

        // Images
        $images = $doc->getElementsByTagName('img');
        $data['image_count'] = $images->length;
        $without_alt = 0;
        foreach ($images as $img) {
            $alt = trim($img->getAttribute('alt'));
            if ($alt === '') {
                $without_alt++;
            }
        }
        $data['images_without_alt'] = $without_alt;

        // Word count (approximate from body text)
        $body_tag = $doc->getElementsByTagName('body');
        if ($body_tag->length > 0) {
            $text = $body_tag->item(0)->textContent;
            $text = preg_replace('/\s+/', ' ', $text);
            $data['word_count'] = str_word_count($text);
        }

        // Links
        $anchors  = $doc->getElementsByTagName('a');
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $internal_urls = [];

        foreach ($anchors as $a) {
            $href = $a->getAttribute('href');
            if (empty($href) || strpos($href, '#') === 0 || strpos($href, 'javascript:') === 0) {
                continue;
            }

            $parsed = wp_parse_url($href);
            $link_host = $parsed['host'] ?? $home_host;

            if ($link_host === $home_host) {
                $data['internal_links']++;
                // Collect unique internal URLs for broken link checking
                $full_url = (strpos($href, 'http') === 0) ? $href : home_url($href);
                if (!in_array($full_url, $internal_urls, true)) {
                    $internal_urls[] = $full_url;
                }
            } else {
                $data['external_links']++;
            }
        }

        // Store internal URLs for broken link checking (limited)
        $data['_internal_urls'] = array_slice($internal_urls, 0, self::MAX_LINK_CHECKS);

        // Structured data (JSON-LD)
        $scripts = $doc->getElementsByTagName('script');
        foreach ($scripts as $script) {
            if (strtolower($script->getAttribute('type')) === 'application/ld+json') {
                $json = json_decode(trim($script->textContent), true);
                if ($json) {
                    $types = [];
                    if (isset($json['@type'])) {
                        $types[] = $json['@type'];
                    }
                    if (isset($json['@graph'])) {
                        foreach ($json['@graph'] as $item) {
                            if (isset($item['@type'])) {
                                $types[] = $item['@type'];
                            }
                        }
                    }
                    $data['structured_data'][] = [
                        'types' => $types,
                        'valid' => true,
                    ];
                } else {
                    $data['structured_data'][] = [
                        'types' => [],
                        'valid' => false,
                        'error' => 'Invalid JSON',
                    ];
                }
            }
        }

        return $data;
    }

    /**
     * Check for redirect chains on the homepage.
     */
    private function check_redirects(): array {
        $result = [
            'chain'         => [],
            'chain_length'  => 0,
            'has_mixed_ssl' => false,
            'issues'        => [],
        ];

        $url   = home_url('/');
        $chain = [];
        $max   = 10;

        for ($i = 0; $i < $max; $i++) {
            $response = wp_remote_get($url, [
                'timeout'     => 5,
                'sslverify'   => false,
                'redirection' => 0,
                'headers'     => ['User-Agent' => 'SAM-SEO/1.0'],
            ]);

            if (is_wp_error($response)) {
                $result['issues'][] = 'Request failed: ' . $response->get_error_message();
                break;
            }

            $code = wp_remote_retrieve_response_code($response);
            $chain[] = ['url' => $url, 'status' => $code];

            if ($code >= 300 && $code < 400) {
                $location = wp_remote_retrieve_header($response, 'location');
                if (!$location) {
                    break;
                }
                // Handle relative redirects
                if (strpos($location, 'http') !== 0) {
                    $parsed = wp_parse_url($url);
                    $location = $parsed['scheme'] . '://' . $parsed['host'] . $location;
                }
                $url = $location;
            } else {
                break;
            }
        }

        $result['chain']        = $chain;
        $result['chain_length'] = count($chain);

        // Check for mixed SSL
        $schemes = array_unique(array_map(function ($step) {
            return wp_parse_url($step['url'], PHP_URL_SCHEME);
        }, $chain));

        if (count($schemes) > 1) {
            $result['has_mixed_ssl'] = true;
            $result['issues'][]     = 'Redirect chain mixes HTTP and HTTPS';
        }

        if (count($chain) > 2) {
            $result['issues'][] = 'Redirect chain has ' . count($chain) . ' hops (recommended: max 2)';
        }

        return $result;
    }

    /**
     * Check a sample of internal links for broken (404) pages.
     */
    private function check_broken_links(?array $homepage_data): array {
        $result = [
            'checked'     => 0,
            'broken'      => [],
            'broken_count'=> 0,
        ];

        if (!$homepage_data || !isset($homepage_data['_internal_urls'])) {
            return $result;
        }

        $urls = $homepage_data['_internal_urls'];

        foreach ($urls as $url) {
            $response = wp_remote_get($url, [
                'timeout'   => 5,
                'sslverify' => false,
                'headers'   => ['User-Agent' => 'SAM-SEO/1.0'],
            ]);

            $result['checked']++;

            if (is_wp_error($response)) {
                $result['broken'][] = [
                    'url'    => $url,
                    'status' => 0,
                    'error'  => $response->get_error_message(),
                ];
                $result['broken_count']++;
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 400) {
                $result['broken'][] = [
                    'url'    => $url,
                    'status' => $code,
                ];
                $result['broken_count']++;
            }
        }

        return $result;
    }

    /**
     * Check if WordPress is set to discourage search engines.
     */
    private function check_search_engine_visibility(): array {
        $blog_public = get_option('blog_public', '1');

        return [
            'visible'  => (string) $blog_public === '1',
            'issues'   => $blog_public !== '1'
                ? ['WordPress is set to discourage search engines (Settings → Reading → Search engine visibility)']
                : [],
        ];
    }
}
