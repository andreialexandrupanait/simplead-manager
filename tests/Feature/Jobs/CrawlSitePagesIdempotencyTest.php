<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CrawlSitePages;
use App\Models\SeoAudit;
use App\Models\SeoImage;
use App\Models\SeoLink;
use App\Models\SeoPage;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P2-20: the crawl must be idempotent. A retry (tries=2) re-runs handle() and
 * must NOT duplicate the crawled-page / link / image rows for the audit.
 */
class CrawlSitePagesIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_the_crawl_twice_does_not_duplicate_rows(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/sitemap.xml' => Http::response('', 404),
            '*/robots.txt' => Http::response('', 404),
            '*' => Http::response(
                '<html><head><title>Home</title></head><body><h1>Hi</h1>'
                .'<img src="/logo.png" alt="logo"></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $site = Site::factory()->create(['url' => 'https://acme.com']);
        $audit = SeoAudit::create(['site_id' => $site->id, 'status' => 'pending']);

        (new CrawlSitePages($site, $audit))->handle();

        $pagesAfterFirst = SeoPage::where('seo_audit_id', $audit->id)->count();
        $imagesAfterFirst = SeoImage::where('seo_audit_id', $audit->id)->count();

        // Simulate the retry: same audit, second run.
        (new CrawlSitePages($site, $audit))->handle();

        $pagesAfterSecond = SeoPage::where('seo_audit_id', $audit->id)->count();
        $imagesAfterSecond = SeoImage::where('seo_audit_id', $audit->id)->count();
        $linksAfterSecond = SeoLink::where('seo_audit_id', $audit->id)->count();

        $this->assertSame($pagesAfterFirst, $pagesAfterSecond, 'retry must not duplicate crawled pages');
        $this->assertSame($imagesAfterFirst, $imagesAfterSecond, 'retry must not duplicate images');
        $this->assertSame(1, $pagesAfterSecond);
        $this->assertLessThanOrEqual($imagesAfterFirst, $linksAfterSecond);
    }
}
