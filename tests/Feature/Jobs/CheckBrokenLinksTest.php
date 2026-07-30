<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Models\LinkCheckResult;
use App\Models\LinkCheckRun;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Services\BrokenLinkChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Faza 5.3 — broken-link detection.
 *
 * The connector (/content-urls) and every target HEAD check are faked, so no real
 * WordPress or network is touched. Each test drives BrokenLinkChecker directly and
 * asserts the run snapshot + diff it writes.
 */
class CheckBrokenLinksTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://example-broken.test';

    /** @var array<int, array{url: string, is_internal?: bool, source_pages?: string[]}> */
    private array $contentUrls = [];

    /** @var array<string, int|array{status: int, location: string}> */
    private array $statusMap = [];

    private bool $faked = false;

    /**
     * Fake the connector + all HEAD checks. Registers a SINGLE Http stub and
     * mutates shared state on subsequent calls — stacking two closures would let
     * the first (stale) one keep answering, since Laravel uses the first stub that
     * returns a response.
     *
     * @param  array<int, array{url: string, is_internal?: bool, source_pages?: string[]}>  $contentUrls
     * @param  array<string, int|array{status: int, location: string}>  $statusMap  target URL => status (or redirect)
     */
    private function fakeNetwork(array $contentUrls, array $statusMap): void
    {
        $this->contentUrls = $contentUrls;
        $this->statusMap = $statusMap;

        if ($this->faked) {
            return;
        }
        $this->faked = true;

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/wp-json/simplead/v1/content-urls')) {
                return Http::response([
                    'success' => true,
                    'urls' => array_map(fn ($u) => [
                        'url' => $u['url'],
                        'is_internal' => $u['is_internal'] ?? true,
                        'source_pages' => $u['source_pages'] ?? [],
                    ], $this->contentUrls),
                    'page' => 1,
                    'per_page' => 100,
                    'total_posts' => count($this->contentUrls),
                    'has_more' => false,
                ], 200);
            }

            $clean = strtok($url, '?');
            $entry = $this->statusMap[$clean] ?? 200;

            if (is_array($entry)) {
                return Http::response('', $entry['status'], ['Location' => $entry['location']]);
            }

            return Http::response('', $entry);
        });
    }

    private function site(): Site
    {
        return Site::factory()->create([
            'url' => self::BASE,
            'is_connected' => true,
        ]);
    }

    public function test_diff_between_two_runs_reports_new_and_resolved(): void
    {
        $site = $this->site();
        $checker = new BrokenLinkChecker;

        // Run 1: three internal links broken, one healthy.
        $this->fakeNetwork(
            contentUrls: [
                ['url' => self::BASE.'/b1'],
                ['url' => self::BASE.'/b2'],
                ['url' => self::BASE.'/b3'],
                ['url' => self::BASE.'/ok'],
            ],
            statusMap: [
                self::BASE.'/b1' => 404,
                self::BASE.'/b2' => 404,
                self::BASE.'/b3' => 404,
                self::BASE.'/ok' => 200,
            ],
        );

        $run1 = $checker->check($site);

        $this->assertSame(3, $run1->broken_count);
        $this->assertSame(3, $run1->new_broken_count, 'First run: every broken link is new.');
        $this->assertSame(0, $run1->resolved_count);

        // Run 2: b1 repaired, b2/b3 still broken, two NEW broken links appear.
        $this->fakeNetwork(
            contentUrls: [
                ['url' => self::BASE.'/b1'],
                ['url' => self::BASE.'/b2'],
                ['url' => self::BASE.'/b3'],
                ['url' => self::BASE.'/n1'],
                ['url' => self::BASE.'/n2'],
            ],
            statusMap: [
                self::BASE.'/b1' => 200, // repaired
                self::BASE.'/b2' => 404,
                self::BASE.'/b3' => 404,
                self::BASE.'/n1' => 500, // new
                self::BASE.'/n2' => 404, // new
            ],
        );

        $run2 = $checker->check($site);

        $this->assertSame(4, $run2->broken_count, 'b2, b3, n1, n2 are broken.');
        $this->assertSame(2, $run2->new_broken_count, 'n1 and n2 are the new breakages.');
        $this->assertSame(1, $run2->resolved_count, 'b1 was resolved.');

        $this->assertSame(2, LinkCheckRun::where('site_id', $site->id)->count());
    }

    public function test_redirect_chain_is_recorded_with_all_hops(): void
    {
        $site = $this->site();
        $checker = new BrokenLinkChecker;

        // A → B → C (200): a 3-step internal chain that ultimately resolves.
        $this->fakeNetwork(
            contentUrls: [
                ['url' => self::BASE.'/a'],
            ],
            statusMap: [
                self::BASE.'/a' => ['status' => 301, 'location' => self::BASE.'/b'],
                self::BASE.'/b' => ['status' => 301, 'location' => self::BASE.'/c'],
                self::BASE.'/c' => 200,
            ],
        );

        $run = $checker->check($site);

        $this->assertSame(1, $run->chains_count);
        $this->assertSame(0, $run->broken_count, 'A resolves to 200 — a chain, not a break.');

        $result = LinkCheckResult::where('run_id', $run->id)
            ->where('url', self::BASE.'/a')
            ->firstOrFail();

        $this->assertIsArray($result->redirect_chain);
        $this->assertCount(3, $result->redirect_chain);
        $this->assertSame(self::BASE.'/a', $result->redirect_chain[0]['url']);
        $this->assertSame(301, $result->redirect_chain[0]['status_code']);
        $this->assertSame(self::BASE.'/c', $result->redirect_chain[2]['url']);
        $this->assertSame(200, $result->redirect_chain[2]['status_code']);
    }

    public function test_external_links_are_skipped_when_flag_off(): void
    {
        $site = $this->site();
        // Flag defaults to false; assert the default explicitly.
        $this->assertFalse((bool) $site->check_external_links);

        $checker = new BrokenLinkChecker;

        $this->fakeNetwork(
            contentUrls: [
                ['url' => self::BASE.'/internal'],
                ['url' => 'https://external.test/dead', 'is_internal' => false],
            ],
            statusMap: [
                self::BASE.'/internal' => 200,
                'https://external.test/dead' => 404,
            ],
        );

        $run = $checker->check($site);

        $this->assertSame(1, $run->total_urls, 'Only the internal URL is checked.');
        $this->assertSame(0, $run->broken_count, 'The dead external link is skipped, not counted.');
        $this->assertDatabaseMissing('link_check_results', [
            'run_id' => $run->id,
            'url' => 'https://external.test/dead',
        ]);

        // Guard: HEAD was never sent to the external host.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'external.test'));
    }

    public function test_external_links_are_checked_when_flag_on(): void
    {
        $site = $this->site();
        $site->forceFill(['check_external_links' => true])->saveQuietly();

        $checker = new BrokenLinkChecker;

        $this->fakeNetwork(
            contentUrls: [
                ['url' => self::BASE.'/internal'],
                ['url' => 'https://external.test/dead', 'is_internal' => false],
            ],
            statusMap: [
                self::BASE.'/internal' => 200,
                'https://external.test/dead' => 404,
            ],
        );

        $run = $checker->check($site);

        $this->assertSame(2, $run->total_urls);
        $this->assertSame(1, $run->broken_count);
        $this->assertDatabaseHas('link_check_results', [
            'run_id' => $run->id,
            'url' => 'https://external.test/dead',
            'is_broken' => true,
            'is_internal' => false,
        ]);
    }

    public function test_alert_only_fires_for_new_breakage_on_top_search_console_page(): void
    {
        $site = $this->site();
        $topPage = self::BASE.'/ranked-post';

        SearchConsoleCache::create([
            'site_id' => $site->id,
            'date_range' => '28d',
            'start_date' => now()->subDays(28),
            'end_date' => now(),
            'data_type' => 'pages',
            'data' => [
                ['page' => $topPage, 'clicks' => 120, 'impressions' => 3000, 'ctr' => 4.0, 'position' => 2.1],
            ],
            'fetched_at' => now(),
            'expires_at' => now()->addHours(6),
        ]);

        $checker = new BrokenLinkChecker;

        $this->fakeNetwork(
            contentUrls: [
                ['url' => self::BASE.'/dead', 'source_pages' => [$topPage]],
            ],
            statusMap: [
                self::BASE.'/dead' => 404,
            ],
        );

        $checker->check($site);

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $site->user_id,
            'type' => 'warning',
        ]);
    }
}
