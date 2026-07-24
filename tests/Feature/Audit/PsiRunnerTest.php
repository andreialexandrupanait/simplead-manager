<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Exceptions\Audit\PsiApiException;
use App\Exceptions\Audit\PsiQuotaException;
use App\Services\Audit\PsiRunner;
use App\Services\PageSpeedService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Faza D: the PSI collector (single-run extraction + median-of-3). Port of the
 * psi.test.ts suite. Reuses PageSpeedService for the HTTP call (Http::fake'd).
 */
class PsiRunnerTest extends TestCase
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed*';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.pagespeed.api_key' => 'test-key']);
    }

    private function runner(): PsiRunner
    {
        // Rebuild PageSpeedService so it picks up the test config key.
        return new PsiRunner(new PageSpeedService);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fixture(array $overrides = []): array
    {
        $base = [
            'lighthouseResult' => [
                'categories' => ['performance' => ['score' => 0.85]],
                'audits' => [
                    'largest-contentful-paint' => ['numericValue' => 2500],
                    'cumulative-layout-shift' => ['numericValue' => 0.05],
                    'total-blocking-time' => ['numericValue' => 150],
                    'first-contentful-paint' => ['numericValue' => 1200],
                    'speed-index' => ['numericValue' => 3000],
                ],
            ],
            'loadingExperience' => [
                'overall_category' => 'AVERAGE',
                'metrics' => [
                    'LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2800],
                    'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 250],
                    'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 8],
                ],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    public function test_extract_pulls_lab_and_field_metrics(): void
    {
        $result = PsiRunner::extract($this->fixture());

        $this->assertSame(
            ['performance' => 85.0, 'lcp' => 2500.0, 'cls' => 0.05, 'tbt' => 150.0, 'fcp' => 1200.0, 'si' => 3000.0],
            $result->lighthouse,
        );
        $this->assertSame(['lcp' => 2800.0, 'inp' => 250.0, 'cls' => 0.08, 'overall' => 'AVERAGE'], $result->crux);
    }

    public function test_extract_returns_null_crux_when_loading_experience_is_missing(): void
    {
        $raw = $this->fixture();
        unset($raw['loadingExperience']);

        $this->assertNull(PsiRunner::extract($raw)->crux);
    }

    public function test_extract_has_empty_opportunities_when_none_present(): void
    {
        $this->assertSame([], PsiRunner::extract($this->fixture())->opportunities);
    }

    public function test_extract_reads_legacy_opportunity_audits(): void
    {
        $raw = $this->fixture(['lighthouseResult' => ['audits' => [
            'modern-image-formats' => [
                'score' => 0.3,
                'numericValue' => 400,
                'details' => [
                    'overallSavingsBytes' => 60000,
                    'overallSavingsMs' => 400,
                    'items' => [['url' => 'https://x.ro/hero.jpg', 'wastedBytes' => 45000, 'totalBytes' => 70000]],
                ],
            ],
            'lcp-lazy-loaded' => ['score' => 0, 'details' => ['items' => []]],
        ]]]);

        $opps = PsiRunner::extract($raw)->opportunities;
        $img = $opps['modern-image-formats'];
        $this->assertSame('modern-image-formats', $img->id);
        $this->assertSame(0.3, $img->score);
        $this->assertSame(60000, $img->savingsBytes);
        $this->assertSame(400.0, $img->savingsMs);
        $this->assertSame([['url' => 'https://x.ro/hero.jpg', 'wastedBytes' => 45000, 'totalBytes' => 70000, 'reason' => null]], $img->items);
        $this->assertSame(0.0, $opps['lcp-lazy-loaded']->score);
        $this->assertArrayNotHasKey('offscreen-images', $opps);
    }

    public function test_extract_reads_lighthouse_12_insight_audits(): void
    {
        $raw = $this->fixture(['lighthouseResult' => ['audits' => [
            'image-delivery-insight' => [
                'score' => 0.5,
                'metricSavings' => ['LCP' => 300, 'FCP' => 0],
                'details' => ['items' => [[
                    'url' => 'https://x.ro/a.png',
                    'totalBytes' => 10200,
                    'wastedBytes' => 8613,
                    'subItems' => ['items' => [['wastedBytes' => 8613, 'reason' => 'Using a modern image format (WebP, AVIF)…']]],
                ]]],
            ],
            'lcp-discovery-insight' => [
                'score' => 0,
                'details' => ['items' => [[
                    'type' => 'checklist',
                    'items' => [
                        'requestDiscoverable' => ['value' => true],
                        'priorityHinted' => ['value' => false],
                        'eagerlyLoaded' => ['value' => true],
                    ],
                ]]],
            ],
        ]]]);

        $result = PsiRunner::extract($raw);
        $img = $result->opportunities['image-delivery-insight'];
        $this->assertSame(0.5, $img->score);
        $this->assertSame(8613, $img->savingsBytes); // sum of item wastedBytes
        $this->assertSame(300.0, $img->savingsMs); // metricSavings.LCP
        $this->assertSame([
            'url' => 'https://x.ro/a.png',
            'wastedBytes' => 8613,
            'totalBytes' => 10200,
            'reason' => 'Using a modern image format (WebP, AVIF)…',
        ], $img->items[0]);
        $this->assertSame(['eagerlyLoaded' => true, 'priorityHinted' => false, 'requestDiscoverable' => true], $result->lcpDiscovery);
    }

    public function test_extract_caps_the_affected_resource_list(): void
    {
        $items = [];
        for ($i = 0; $i < 40; $i++) {
            $items[] = ['url' => "https://x.ro/img{$i}.jpg", 'wastedBytes' => 1000];
        }
        $raw = $this->fixture(['lighthouseResult' => ['audits' => [
            'offscreen-images' => ['score' => 0.2, 'details' => ['items' => $items]],
        ]]]);

        $this->assertCount(PsiRunner::MAX_OPPORTUNITY_ITEMS, PsiRunner::extract($raw)->opportunities['offscreen-images']->items);
    }

    public function test_run_builds_the_request_with_url_strategy_category_and_key(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->fixture(), 200)]);

        $this->runner()->run('https://example.ro/', 'mobile');

        Http::assertSent(function ($request): bool {
            $this->assertStringStartsWith('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', $request->url());
            $this->assertSame('https://example.ro/', $request['url']);
            $this->assertSame('MOBILE', $request['strategy']);
            $this->assertSame(['PERFORMANCE'], $request['category']);
            $this->assertSame('test-key', $request['key']);

            return true;
        });
    }

    public function test_run_throws_quota_on_http_429(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['code' => 429, 'message' => 'Quota exceeded']], 429)]);

        $this->expectException(PsiQuotaException::class);
        $this->runner()->run('https://example.ro/', 'mobile');
    }

    public function test_run_throws_quota_on_quota_body_even_with_http_200(): void
    {
        Http::fake([self::ENDPOINT => Http::response(
            ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded for quota metric']],
            200,
        )]);

        $this->expectException(PsiQuotaException::class);
        $this->runner()->run('https://example.ro/', 'mobile');
    }

    public function test_run_throws_api_error_on_other_failures(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['code' => 400, 'message' => 'Invalid URL']], 400)]);

        $this->expectException(PsiApiException::class);
        $this->runner()->run('not-a-url', 'mobile');
    }

    public function test_run_throws_api_error_when_lighthouse_result_is_missing(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['loadingExperience' => []], 200)]);

        $this->expectException(PsiApiException::class);
        $this->runner()->run('https://example.ro/', 'mobile');
    }

    public function test_median_handles_odd_even_and_null_only_inputs(): void
    {
        $this->assertSame(2.0, PsiRunner::median([3.0, 1.0, 2.0]));
        $this->assertSame(2.5, PsiRunner::median([4.0, 1.0, 2.0, 3.0]));
        $this->assertSame(5.0, PsiRunner::median([null, 5.0, null]));
        $this->assertNull(PsiRunner::median([null, null]));
    }

    public function test_median_run_takes_the_per_metric_median_over_three_runs(): void
    {
        $mk = fn (float $score, int $lcp, float $cls, int $tbt): array => $this->fixture(['lighthouseResult' => [
            'categories' => ['performance' => ['score' => $score]],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => $lcp],
                'cumulative-layout-shift' => ['numericValue' => $cls],
                'total-blocking-time' => ['numericValue' => $tbt],
            ],
        ]]);

        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($mk(0.85, 2500, 0.05, 150), 200)
            ->push($mk(0.70, 3100, 0.01, 400), 200)
            ->push($mk(0.90, 2000, 0.09, 90), 200),
        ]);

        $result = $this->runner()->medianRun('https://example.ro/', 'mobile');

        $this->assertSame(85.0, $result->lighthouse['performance']);
        $this->assertSame(2500.0, $result->lighthouse['lcp']);
        $this->assertSame(0.05, $result->lighthouse['cls']);
        $this->assertSame(150.0, $result->lighthouse['tbt']);
        // Unchanged across runs.
        $this->assertSame(1200.0, $result->lighthouse['fcp']);
        $this->assertSame(3000.0, $result->lighthouse['si']);
        // CrUX field data comes from the first run with field data.
        $this->assertSame(2800.0, $result->crux['lcp']);
        $this->assertSame('AVERAGE', $result->crux['overall']);
    }
}
