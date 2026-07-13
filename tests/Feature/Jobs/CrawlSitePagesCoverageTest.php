<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CrawlSitePages;
use App\Models\SeoAudit;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P3-20: a crawl truncated by the page cap (or runtime deadline) used to look
 * identical to a complete one. It must now flag partial coverage.
 */
class CrawlSitePagesCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSite(string $body): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/sitemap.xml' => Http::response('', 404),
            '*/robots.txt' => Http::response('', 404),
            '*' => Http::response($body, 200, ['Content-Type' => 'text/html']),
        ]);
    }

    public function test_crawl_capped_below_the_queue_is_flagged_partial(): void
    {
        // Cap the crawl at a single page while the home page links to two more,
        // leaving the queue non-empty when the loop exits.
        config(['seo.crawler.default_max_pages' => 1]);
        $this->fakeSite(
            '<html><head><title>Home</title></head><body>'
            .'<a href="/a">A</a><a href="/b">B</a></body></html>'
        );

        $site = Site::factory()->create(['url' => 'https://acme.com']);
        $audit = SeoAudit::create(['site_id' => $site->id, 'status' => 'pending']);

        (new CrawlSitePages($site, $audit))->handle();

        $audit->refresh();
        $this->assertTrue($audit->coverage_partial, 'a capped crawl with pages remaining must be flagged partial');
    }

    public function test_full_crawl_is_not_flagged_partial(): void
    {
        config(['seo.crawler.default_max_pages' => 100]);
        // No internal links -> queue drains completely.
        $this->fakeSite('<html><head><title>Home</title></head><body><h1>Hi</h1></body></html>');

        $site = Site::factory()->create(['url' => 'https://acme.com']);
        $audit = SeoAudit::create(['site_id' => $site->id, 'status' => 'pending']);

        (new CrawlSitePages($site, $audit))->handle();

        $audit->refresh();
        $this->assertFalse($audit->coverage_partial, 'a fully-drained crawl must not be flagged partial');
    }
}
