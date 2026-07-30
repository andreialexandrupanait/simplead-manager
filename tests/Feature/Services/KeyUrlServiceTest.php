<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Services\KeyUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC §7.5 — key-URL derivation: homepage + top Search-Console pages.
 */
class KeyUrlServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_derives_homepage_only_when_there_is_no_search_console_data(): void
    {
        $site = Site::factory()->create(['url' => 'https://example.com']);

        $urls = app(KeyUrlService::class)->derive($site);

        $this->assertSame(['https://example.com/'], $urls);
    }

    public function test_derives_homepage_plus_top_gsc_pages_same_host_sorted_by_clicks(): void
    {
        $site = Site::factory()->create(['url' => 'https://example.com']);
        SearchConsoleCache::create([
            'site_id' => $site->id,
            'date_range' => '28d',
            'start_date' => now()->subDays(28),
            'end_date' => now(),
            'data_type' => 'pages',
            'fetched_at' => now(),
            'expires_at' => now()->addHour(),
            'data' => [
                ['page' => 'https://example.com/blog', 'clicks' => 10],
                ['page' => 'https://example.com/pricing', 'clicks' => 90],
                ['page' => 'https://other-site.com/spam', 'clicks' => 999], // different host → dropped
                ['page' => 'https://example.com/about', 'clicks' => 50],
                ['page' => 'https://example.com/contact', 'clicks' => 5], // beyond the cap
            ],
        ]);

        $urls = app(KeyUrlService::class)->derive($site);

        // homepage first, then top 3 same-host pages by clicks (pricing, about, blog).
        $this->assertSame([
            'https://example.com/',
            'https://example.com/pricing',
            'https://example.com/about',
            'https://example.com/blog',
        ], $urls);
    }

    public function test_derive_and_store_persists_to_the_site(): void
    {
        $site = Site::factory()->create(['url' => 'https://example.com', 'key_urls' => null]);

        app(KeyUrlService::class)->deriveAndStore($site);

        $this->assertSame(['https://example.com/'], $site->fresh()->key_urls);
    }
}
