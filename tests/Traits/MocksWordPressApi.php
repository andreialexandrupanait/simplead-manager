<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Http;

trait MocksWordPressApi
{
    /**
     * Set up Http::fake() for WordPress REST API endpoints.
     * Matches any site URL calling wp-json/simplead/v1/*.
     */
    protected function fakeWordPressApi(array $overrides = []): void
    {
        $defaults = [
            '*/wp-json/simplead/v1/info' => Http::response([
                'wp_version' => '6.4.2',
                'php_version' => '8.2',
                'server_software' => 'Apache/2.4',
                'is_multisite' => false,
                'plugins_count' => 12,
                'themes_count' => 3,
                'users_count' => 5,
            ]),
            '*/wp-json/simplead/v1/plugins' => Http::response([
                ['name' => 'Akismet', 'slug' => 'akismet', 'version' => '5.3', 'is_active' => true, 'has_update' => false],
                ['name' => 'WooCommerce', 'slug' => 'woocommerce', 'version' => '8.4', 'is_active' => true, 'has_update' => true, 'update_version' => '8.5'],
            ]),
            '*/wp-json/simplead/v1/themes' => Http::response([
                ['name' => 'Twenty Twenty-Four', 'slug' => 'twentytwentyfour', 'version' => '1.0', 'is_active' => true],
            ]),
            '*/wp-json/simplead/v1/health-check' => Http::response([
                'status' => 'ok',
                'site_reachable' => true,
                'response_time' => 250,
                'database_ok' => true,
            ]),
            '*/wp-json/simplead/v1/security-check' => Http::response([
                'headers' => [
                    'x-frame-options' => true,
                    'x-content-type-options' => true,
                    'strict-transport-security' => false,
                    'content-security-policy' => false,
                    'referrer-policy' => true,
                    'permissions-policy' => false,
                ],
            ]),
            '*/wp-json/simplead/v1/ip-rules/sync' => Http::response(['success' => true]),
            '*/wp-json/simplead/v1/blocked-requests' => Http::response([]),
            '*/wp-json/simplead/v1/core-integrity' => Http::response([
                'wp_version' => '6.4.2',
                'modified' => [],
                'missing' => [],
                'unknown' => [],
            ]),
            '*/wp-json/simplead/v1/server-resources' => Http::response([
                'cpu_usage' => 25.5,
                'memory_used' => 2000000000,
                'memory_total' => 8000000000,
                'disk_used' => 40000000000,
                'disk_total' => 100000000000,
                'load_average' => [0.5, 0.4, 0.3],
            ]),
            '*/wp-json/simplead/v1/seo-check' => Http::response([
                'homepage_title' => 'Test Site',
                'homepage_meta_description' => 'A test site description',
                'has_sitemap' => true,
                'sitemap_url' => '/sitemap.xml',
                'has_robots_txt' => true,
                'has_og_tags' => true,
                'has_twitter_cards' => false,
                'has_schema_markup' => false,
                'has_canonical' => true,
                'has_h1' => true,
                'heading_hierarchy_ok' => true,
            ]),
            '*/wp-json/simplead/v1/woo/stats*' => Http::response([
                'orders_count' => 10,
                'revenue' => 1500.00,
                'currency' => 'USD',
                'average_order_value' => 150.00,
                'products_sold_count' => 25,
                'refunds_count' => 1,
                'refunds_amount' => 50.00,
                'new_customers' => 5,
                'returning_customers' => 5,
            ]),
            '*/wp-json/simplead/v1/woo/low-stock' => Http::response([]),
            '*/wp-json/simplead/v1/woo/out-of-stock' => Http::response([]),
            '*/wp-json/simplead/v1/db-cleanup/stats' => Http::response([
                'revisions' => 150,
                'auto_drafts' => 10,
                'trash_posts' => 5,
                'spam_comments' => 20,
                'trash_comments' => 8,
                'transients' => 45,
                'orphaned_meta' => 12,
            ]),
            '*/wp-json/simplead/v1/db-cleanup/run' => Http::response([
                'revisions_deleted' => 150,
                'auto_drafts_deleted' => 10,
                'trash_posts_deleted' => 5,
                'spam_comments_deleted' => 20,
                'trash_comments_deleted' => 8,
                'transients_deleted' => 45,
                'orphaned_meta_deleted' => 12,
                'space_saved' => 5242880,
            ]),
            '*/wp-json/simplead/v1/error-logs' => Http::response([]),
            '*/wp-json/simplead/v1/audit-logs' => Http::response([]),
            '*/wp-json/simplead/v1/rollback' => Http::response(['success' => true]),
            '*/wp-json/simplead/v1/*' => Http::response([]),
        ];

        Http::fake(array_merge($defaults, $overrides));
    }

    /**
     * Fake Cloudflare API responses.
     */
    protected function fakeCloudflareApi(array $overrides = []): void
    {
        $defaults = [
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
                'success' => true,
                'result' => ['status' => 'active'],
            ]),
            'https://api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [],
                'result_info' => ['total_count' => 0, 'total_pages' => 1, 'page' => 1],
            ]),
            'https://api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [],
            ]),
        ];

        Http::fake(array_merge($defaults, $overrides));
    }

    /**
     * Fake WordPress.org API responses (for vulnerability/checksum checks).
     */
    protected function fakeWordPressOrgApi(array $overrides = []): void
    {
        $defaults = [
            'https://api.wordpress.org/core/checksums/*' => Http::response([
                'checksums' => ['wp-admin/index.php' => 'abc123'],
            ]),
            'https://www.wordfence.com/api/*' => Http::response([]),
        ];

        Http::fake(array_merge($defaults, $overrides));
    }
}
