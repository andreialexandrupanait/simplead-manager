<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Content URLs endpoint (Faza 5.3 — broken-link detection).
 *
 * Read-only scan of PUBLISHED content, navigation menus and text/HTML widgets for
 * the URLs they reference (anchor href + <img src>). The manager HEAD-checks those
 * URLs from ITS OWN network and reports on the difference between runs.
 *
 * The check is deliberately NOT done here: a loopback request from the WP host to
 * its own public URL is answered by Cloudflare with 403 (see CLAUDE.md). This
 * endpoint therefore only extracts + normalises + deduplicates URLs. No crawling,
 * no JS execution, no outbound HTTP. Pure DB read + regex. Paginated for large sites.
 */
class SAM_Content_Urls_Endpoint extends SAM_Endpoint_Base {

    /** Hard ceiling on posts scanned in a single request. */
    const MAX_PER_PAGE = 500;

    /** Cap on distinct source pages recorded per URL (report readability). */
    const MAX_SOURCES_PER_URL = 20;

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/content-urls', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_content_urls'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'page'     => ['type' => 'integer', 'default' => 1,   'sanitize_callback' => 'absint'],
                'per_page' => ['type' => 'integer', 'default' => 100, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public function get_content_urls(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $page     = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        if ($per_page < 1 || $per_page > self::MAX_PER_PAGE) {
            $per_page = 100;
        }
        $offset = ($page - 1) * $per_page;

        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);

        // url => ['is_internal' => bool, 'source_pages' => string[]]
        $map = [];

        $add = function ($raw, $source_url) use (&$map, $site_host) {
            $url = $this->normalize_url($raw);
            if ($url === null) {
                return;
            }
            if (!isset($map[$url])) {
                $map[$url] = [
                    'is_internal'  => $this->is_internal($url, $site_host),
                    'source_pages' => [],
                ];
            }
            if ($source_url !== ''
                && count($map[$url]['source_pages']) < self::MAX_SOURCES_PER_URL
                && !in_array($source_url, $map[$url]['source_pages'], true)) {
                $map[$url]['source_pages'][] = $source_url;
            }
        };

        // 1. Published post / page / product content (paginated).
        $post_types = ['post', 'page'];
        if (post_type_exists('product')) {
            $post_types[] = 'product';
        }
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ($placeholders)
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            array_merge($post_types, [$per_page, $offset])
        ));

        foreach ($rows as $row) {
            $permalink = get_permalink($row->ID);
            $permalink = is_string($permalink) ? $permalink : '';
            foreach ($this->extract_urls((string) $row->post_content) as $u) {
                $add($u, $permalink);
            }
        }

        // 2. Menus + widgets are global — only scan them on the first page.
        if ($page === 1) {
            $home = home_url('/');
            foreach ($this->menu_urls() as $u) {
                $add($u, $home);
            }
            foreach ($this->widget_urls() as $u) {
                $add($u, $home);
            }
        }

        $urls = [];
        foreach ($map as $url => $meta) {
            $urls[] = [
                'url'          => $url,
                'is_internal'  => $meta['is_internal'],
                'source_pages' => $meta['source_pages'],
            ];
        }

        $total_posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ($placeholders)",
            $post_types
        ));

        return $this->success([
            'urls'        => $urls,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_posts' => $total_posts,
            'has_more'    => ($offset + $per_page) < $total_posts,
        ]);
    }

    /**
     * Extract raw URLs from anchor href="" and img src="" attributes.
     *
     * @return string[]
     */
    private function extract_urls(string $html): array {
        if (trim($html) === '') {
            return [];
        }

        $found = [];
        if (preg_match_all('/<a\b[^>]*?\bhref\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            $found = array_merge($found, $m[1]);
        }
        if (preg_match_all('/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            $found = array_merge($found, $m[1]);
        }

        return $found;
    }

    /**
     * Normalise a raw href/src into an absolute http(s) URL, or null if it is not
     * a checkable web link (mailto/tel/javascript/data/anchor-only).
     */
    private function normalize_url(?string $raw): ?string {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '#') {
            return null;
        }

        // Drop the fragment.
        $hash = strpos($raw, '#');
        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }
        if ($raw === '') {
            return null;
        }

        $lower = strtolower($raw);
        foreach (['mailto:', 'tel:', 'javascript:', 'data:', 'sms:', 'ftp:', 'skype:', 'whatsapp:'] as $bad) {
            if (strpos($lower, $bad) === 0) {
                return null;
            }
        }

        // Protocol-relative → same scheme as the site.
        if (strpos($raw, '//') === 0) {
            $raw = (is_ssl() ? 'https:' : 'http:') . $raw;
        }

        // Root-relative or path-relative → resolve against the site home.
        if (!preg_match('#^https?://#i', $raw)) {
            if (strpos($raw, '/') === 0) {
                $raw = home_url($raw);
            } else {
                $raw = home_url('/' . ltrim($raw, '/'));
            }
        }

        $url = esc_url_raw($raw);

        return $url !== '' ? $url : null;
    }

    private function is_internal(string $url, ?string $site_host): bool {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host || !$site_host) {
            return false;
        }
        $host      = preg_replace('/^www\./', '', strtolower($host));
        $site_host = preg_replace('/^www\./', '', strtolower($site_host));

        return $host === $site_host;
    }

    /**
     * URLs referenced by every registered navigation menu item.
     *
     * @return string[]
     */
    private function menu_urls(): array {
        if (!function_exists('wp_get_nav_menus')) {
            return [];
        }

        $urls = [];
        foreach (wp_get_nav_menus() as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            if (!$items) {
                continue;
            }
            foreach ($items as $item) {
                if (!empty($item->url)) {
                    $urls[] = $item->url;
                }
            }
        }

        return $urls;
    }

    /**
     * URLs referenced inside Text / Custom HTML / Block widget instances.
     *
     * @return string[]
     */
    private function widget_urls(): array {
        $urls = [];
        foreach (['widget_text', 'widget_custom_html', 'widget_block'] as $option_key) {
            $option = get_option($option_key);
            if (!is_array($option)) {
                continue;
            }
            foreach ($option as $instance) {
                if (!is_array($instance)) {
                    continue;
                }
                $content = '';
                foreach (['text', 'content'] as $field) {
                    if (!empty($instance[$field]) && is_string($instance[$field])) {
                        $content .= ' ' . $instance[$field];
                    }
                }
                if ($content !== '') {
                    $urls = array_merge($urls, $this->extract_urls($content));
                }
            }
        }

        return $urls;
    }
}
