<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO check endpoint.
 */
class SAM_SEO_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/seo-check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'seo_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function seo_check(WP_REST_Request $request): WP_REST_Response {
        $checks = [];

        // 1. Site title
        $site_title = get_bloginfo('name');
        $checks['site_title'] = [
            'pass'    => !empty($site_title) && $site_title !== 'Just another WordPress site',
            'label'   => 'Site title set',
            'value'   => $site_title,
            'message' => empty($site_title) ? 'No site title set.' : ($site_title === 'Just another WordPress site' ? 'Using default tagline.' : 'OK'),
        ];

        // 2. Tagline
        $tagline = get_bloginfo('description');
        $checks['tagline'] = [
            'pass'    => !empty($tagline) && $tagline !== 'Just another WordPress site',
            'label'   => 'Tagline customized',
            'value'   => $tagline,
            'message' => empty($tagline) ? 'No tagline set.' : ($tagline === 'Just another WordPress site' ? 'Still using default tagline.' : 'OK'),
        ];

        // 3. Search engine visibility
        $blog_public = get_option('blog_public');
        $checks['search_visibility'] = [
            'pass'    => (bool) $blog_public,
            'label'   => 'Search engines allowed',
            'value'   => $blog_public ? 'Visible' : 'Discouraged',
            'message' => $blog_public ? 'Search engines can index this site.' : 'Search engines are discouraged from indexing this site.',
        ];

        // 4. Permalink structure (not default)
        $permalink_structure = get_option('permalink_structure');
        $checks['permalinks'] = [
            'pass'    => !empty($permalink_structure),
            'label'   => 'Pretty permalinks enabled',
            'value'   => $permalink_structure ?: 'Default (plain)',
            'message' => empty($permalink_structure) ? 'Using plain permalinks. SEO-friendly permalinks recommended.' : 'OK',
        ];

        // 5. SSL
        $checks['ssl'] = [
            'pass'    => is_ssl(),
            'label'   => 'HTTPS active',
            'value'   => is_ssl() ? 'Yes' : 'No',
            'message' => is_ssl() ? 'Site is served over HTTPS.' : 'Site is not using HTTPS. This affects SEO rankings.',
        ];

        // 6. XML Sitemap
        $sitemap_url = home_url('/wp-sitemap.xml');
        $sitemap_response = wp_remote_head($sitemap_url, ['timeout' => 5, 'sslverify' => false]);
        $sitemap_exists = !is_wp_error($sitemap_response) && wp_remote_retrieve_response_code($sitemap_response) === 200;

        // Also check for Yoast / RankMath sitemaps
        if (!$sitemap_exists) {
            $alt_sitemap = home_url('/sitemap_index.xml');
            $alt_response = wp_remote_head($alt_sitemap, ['timeout' => 5, 'sslverify' => false]);
            $sitemap_exists = !is_wp_error($alt_response) && wp_remote_retrieve_response_code($alt_response) === 200;
        }

        $checks['sitemap'] = [
            'pass'    => $sitemap_exists,
            'label'   => 'XML sitemap available',
            'value'   => $sitemap_exists ? 'Found' : 'Not found',
            'message' => $sitemap_exists ? 'XML sitemap is accessible.' : 'No XML sitemap found.',
        ];

        // 7. Robots.txt
        $robots_url = home_url('/robots.txt');
        $robots_response = wp_remote_head($robots_url, ['timeout' => 5, 'sslverify' => false]);
        $robots_exists = !is_wp_error($robots_response) && wp_remote_retrieve_response_code($robots_response) === 200;

        $checks['robots_txt'] = [
            'pass'    => $robots_exists,
            'label'   => 'Robots.txt present',
            'value'   => $robots_exists ? 'Found' : 'Not found',
            'message' => $robots_exists ? 'robots.txt is accessible.' : 'No robots.txt found.',
        ];

        // 8. Homepage meta description (check front page)
        $front_page_id = (int) get_option('page_on_front');
        $has_meta_desc = false;
        if ($front_page_id) {
            // Check popular SEO plugin meta
            $meta_desc = get_post_meta($front_page_id, '_yoast_wpseo_metadesc', true)
                ?: get_post_meta($front_page_id, 'rank_math_description', true)
                ?: get_post_meta($front_page_id, '_aioseo_description', true);
            $has_meta_desc = !empty($meta_desc);
        }

        $checks['meta_description'] = [
            'pass'    => $has_meta_desc,
            'label'   => 'Homepage meta description',
            'value'   => $has_meta_desc ? 'Set' : 'Not set',
            'message' => $has_meta_desc ? 'Homepage has a meta description.' : 'No meta description found on the homepage.',
        ];

        // 9. Open Graph / Social Meta
        $has_og = false;
        if (function_exists('is_plugin_active')) {
            $seo_plugins = [
                'wordpress-seo/wp-seo.php',         // Yoast
                'seo-by-rank-math/rank-math.php',   // RankMath
                'all-in-one-seo-pack/all_in_one_seo_pack.php', // AIOSEO
            ];
            foreach ($seo_plugins as $plugin) {
                if (is_plugin_active($plugin)) {
                    $has_og = true;
                    break;
                }
            }
        }

        $checks['seo_plugin'] = [
            'pass'    => $has_og,
            'label'   => 'SEO plugin active',
            'value'   => $has_og ? 'Yes' : 'No',
            'message' => $has_og ? 'An SEO plugin is active.' : 'No SEO plugin detected. Consider installing one for better SEO control.',
        ];

        // 10. Favicon
        $site_icon = get_option('site_icon');
        $checks['favicon'] = [
            'pass'    => !empty($site_icon),
            'label'   => 'Favicon set',
            'value'   => !empty($site_icon) ? 'Set' : 'Not set',
            'message' => !empty($site_icon) ? 'Site icon / favicon is configured.' : 'No favicon set. Add one in Appearance > Customize > Site Identity.',
        ];

        // Score
        $total = count($checks);
        $passed = count(array_filter($checks, fn($c) => $c['pass']));
        $score = $total > 0 ? round(($passed / $total) * 100) : 0;

        return $this->success([
            'checks' => $checks,
            'score'  => $score,
            'total'  => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
        ]);
    }
}
