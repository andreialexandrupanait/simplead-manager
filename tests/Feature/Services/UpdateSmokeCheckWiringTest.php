<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Site;
use App\Services\UpdateSmokeCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SPEC §7.4 etapa 1 — the smoke check runs on the site's KEY URLs (§7.5), not
 * only the homepage, and each URL is judged against its own DOM reference.
 */
class UpdateSmokeCheckWiringTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://smoke-target.test';

    private function site(array $overrides = []): Site
    {
        return Site::factory()->create(array_merge([
            'url' => self::BASE,
            'key_urls' => [self::BASE.'/', self::BASE.'/contact', self::BASE.'/shop'],
        ], $overrides));
    }

    public function test_every_key_url_is_probed(): void
    {
        Http::fake(['*' => Http::response('<html><body><footer>ok</footer></body></html>', 200)]);

        $result = app(UpdateSmokeCheckService::class)->runKeyUrls($this->site());

        $this->assertTrue($result['passed']);
        $this->assertSame(
            [self::BASE.'/', self::BASE.'/contact', self::BASE.'/shop'],
            array_column($result['urls'], 'url'),
        );

        // Counted per key URL rather than with assertSentCount — creating a site
        // also dispatches a favicon fetch, which sends its own request.
        foreach ([self::BASE.'/', self::BASE.'/contact', self::BASE.'/shop'] as $url) {
            Http::assertSent(fn (Request $r): bool => $r->url() === $url);
        }
    }

    public function test_one_broken_key_url_fails_the_whole_run(): void
    {
        Http::fake([
            self::BASE.'/contact' => Http::response('<html><body>Fatal error: boom</body></html>', 200),
            '*' => Http::response('<html><body>fine</body></html>', 200),
        ]);

        $result = app(UpdateSmokeCheckService::class)->runKeyUrls($this->site());

        $this->assertFalse($result['passed'], 'A fatal on any key URL fails the run.');

        $failed = array_column(array_filter($result['urls'], fn (array $u): bool => ! $u['passed']), 'url');
        $this->assertSame([self::BASE.'/contact'], $failed);
    }

    public function test_each_url_is_compared_against_its_own_dom_reference(): void
    {
        // /shop legitimately has far more markup than the homepage. Judged against
        // the homepage's reference it would look like an explosion; against its own
        // it is nominal.
        // References match what each page actually renders below: 6 tags for the
        // small pages, ~406 for the shop.
        $site = $this->site([
            'smoke_dom_references' => [
                self::BASE.'/' => 6,
                self::BASE.'/contact' => 6,
                self::BASE.'/shop' => 400,
            ],
        ]);

        Http::fake(function (Request $request) {
            $body = str_contains($request->url(), '/shop')
                ? '<html><body>'.str_repeat('<div>x</div>', 200).'</body></html>'
                : '<html><body><p>small</p></body></html>';

            return Http::response($body, 200);
        });

        $result = app(UpdateSmokeCheckService::class)->runKeyUrls($site);

        $this->assertTrue($result['passed']);
    }

    public function test_a_site_with_no_key_urls_still_gets_its_homepage_checked(): void
    {
        Http::fake(['*' => Http::response('<html><body>fine</body></html>', 200)]);

        $result = app(UpdateSmokeCheckService::class)->runKeyUrls($this->site(['key_urls' => null]));

        $this->assertCount(1, $result['urls']);
        $this->assertSame(self::BASE, $result['urls'][0]['url']);
    }

    public function test_the_legacy_single_reference_seeds_the_homepage(): void
    {
        $site = $this->site([
            'key_urls' => [self::BASE.'/'],
            'smoke_dom_reference' => 1000,
            'smoke_dom_references' => null,
        ]);

        // Far fewer nodes than the seeded reference of 1000 — the page collapsed.
        Http::fake(['*' => Http::response('<html><body>gone</body></html>', 200)]);

        $result = app(UpdateSmokeCheckService::class)->runKeyUrls($site);

        // The key URL carries a trailing slash while the legacy column was measured
        // against $site->url without one. Both spellings are seeded, so the carried
        // baseline still catches the collapse instead of silently re-baselining.
        $this->assertFalse($result['passed']);

        $domSignal = collect($result['urls'][0]['signals'])->firstWhere('name', 'dom_nodes');
        $this->assertFalse($domSignal['passed']);
    }
}
