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

        // Find the post by URL
        $post_id = url_to_postid($url);
        if (!$post_id) {
            // Try with trailing slash
            $post_id = url_to_postid(trailingslashit($url));
        }
        if (!$post_id) {
            // Try homepage
            if (rtrim($url, '/') === rtrim(home_url(), '/')) {
                $post_id = get_option('page_on_front');
            }
        }

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
