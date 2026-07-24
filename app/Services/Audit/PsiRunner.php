<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\PsiOpportunity;
use App\DTOs\Audit\PsiRunResult;
use App\Exceptions\Audit\PsiApiException;
use App\Exceptions\Audit\PsiQuotaException;
use App\Services\PageSpeedService;
use Illuminate\Http\Client\ConnectionException;

/**
 * PageSpeed Insights collector — single-run extraction + median-of-3. Port of
 * src/lib/collectors/psi.ts. Reuses PageSpeedService for the HTTP call (endpoint
 * + API key + params) and classifies the response here (quota vs. other errors).
 *
 * The extraction is tolerant of BOTH PSI shapes (validated live 2026-07):
 *  - Lighthouse 12+ insights (`*-insight`): byte savings summed from
 *    details.items[].wastedBytes, ms savings from audit.metricSavings.LCP;
 *  - classic (legacy) audits: details.overallSavingsBytes/Ms + flat items.
 */
final class PsiRunner
{
    public const DEFAULT_MEDIAN_RUNS = 3;

    /** The opportunity/insight audits extracted for perf v2 (3.5 images, 3.6 lazy-load). */
    public const OPPORTUNITY_AUDITS = [
        'image-delivery-insight', // Lighthouse 12+: modern format, compression, sizing
        'modern-image-formats',   // legacy
        'uses-webp-images',       // legacy
        'offscreen-images',       // legacy: below-the-fold images not deferred
        'lcp-discovery-insight',  // Lighthouse 12+: LCP eager/preload/discoverable
        'lcp-lazy-loaded',        // legacy: LCP loaded lazily
    ];

    /** Cap on the affected-resource list per opportunity audit. */
    public const MAX_OPPORTUNITY_ITEMS = 25;

    public function __construct(
        private readonly PageSpeedService $pageSpeed = new PageSpeedService,
    ) {}

    /**
     * Run PSI $runs times (sequentially) and take the per-metric median of the lab
     * metrics. CrUX field data is origin/page-level (identical across runs), so the
     * first non-null crux is used; opportunities/lcpDiscovery come from the first
     * run that has them (they depend on HTML/resources, not network variance).
     *
     * @throws PsiQuotaException on 429 / quota exhaustion
     * @throws PsiApiException on any other API failure
     */
    public function medianRun(string $url, string $strategy = 'mobile', int $runs = self::DEFAULT_MEDIAN_RUNS): PsiRunResult
    {
        /** @var list<PsiRunResult> $results */
        $results = [];
        for ($i = 0; $i < $runs; $i++) {
            $results[] = $this->run($url, $strategy);
        }

        $lighthouse = [];
        foreach (['performance', 'lcp', 'cls', 'tbt', 'fcp', 'si'] as $key) {
            $lighthouse[$key] = self::median(
                array_map(static fn (PsiRunResult $r): ?float => $r->lighthouse[$key] ?? null, $results),
            );
        }

        $crux = null;
        $opportunities = [];
        $lcpDiscovery = null;
        foreach ($results as $r) {
            if ($crux === null && $r->crux !== null) {
                $crux = $r->crux;
            }
            if ($opportunities === [] && $r->opportunities !== []) {
                $opportunities = $r->opportunities;
            }
            if ($lcpDiscovery === null && $r->lcpDiscovery !== null) {
                $lcpDiscovery = $r->lcpDiscovery;
            }
        }

        return new PsiRunResult($lighthouse, $crux, $opportunities, $lcpDiscovery);
    }

    /**
     * A single PSI run: fetch, classify the response, extract the result.
     *
     * @throws PsiQuotaException
     * @throws PsiApiException
     */
    public function run(string $url, string $strategy = 'mobile'): PsiRunResult
    {
        try {
            $response = $this->pageSpeed->fetchRaw($url, $strategy);
        } catch (ConnectionException $e) {
            throw new PsiApiException('PSI request failed: '.$e->getMessage(), null, $e);
        }

        if ($response->status() === 429) {
            throw new PsiQuotaException;
        }

        $raw = $response->json();
        if (! is_array($raw)) {
            throw new PsiApiException("PSI returned a non-JSON response (HTTP {$response->status()}).", $response->status());
        }

        $error = $raw['error'] ?? null;
        if (is_array($error)) {
            $code = $error['code'] ?? null;
            $status = $error['status'] ?? null;
            $message = is_string($error['message'] ?? null) ? $error['message'] : null;
            if ($code === 429 || $status === 'RESOURCE_EXHAUSTED' || ($message !== null && preg_match('/quota|rate limit/i', $message) === 1)) {
                throw new PsiQuotaException($message ?? 'PageSpeed Insights quota exceeded.');
            }
            throw new PsiApiException($message ?? "PSI error (HTTP {$response->status()}).", is_int($code) ? $code : $response->status());
        }

        if (! $response->successful()) {
            throw new PsiApiException("PSI request failed (HTTP {$response->status()}).", $response->status());
        }
        if (! is_array($raw['lighthouseResult'] ?? null)) {
            throw new PsiApiException('PSI response missing lighthouseResult.', $response->status());
        }

        return self::extract($raw);
    }

    /**
     * Extract a PsiRunResult from a raw PSI response. Port of extractPsi.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function extract(array $raw): PsiRunResult
    {
        $lhr = is_array($raw['lighthouseResult'] ?? null) ? $raw['lighthouseResult'] : [];
        $audits = is_array($lhr['audits'] ?? null) ? $lhr['audits'] : [];

        $score = self::num($lhr['categories']['performance']['score'] ?? null);
        $lighthouse = [
            'performance' => $score === null ? null : (float) round($score * 100),
            'lcp' => self::auditValue($audits, 'largest-contentful-paint'),
            'cls' => self::auditValue($audits, 'cumulative-layout-shift'),
            'tbt' => self::auditValue($audits, 'total-blocking-time'),
            'fcp' => self::auditValue($audits, 'first-contentful-paint'),
            'si' => self::auditValue($audits, 'speed-index'),
        ];

        $crux = null;
        $le = is_array($raw['loadingExperience'] ?? null) ? $raw['loadingExperience'] : null;
        $metrics = is_array($le['metrics'] ?? null) ? $le['metrics'] : [];
        if ($le !== null && $metrics !== []) {
            $clsPercentile = self::num($metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ?? null);
            $crux = [
                'lcp' => self::num($metrics['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ?? null),
                'inp' => self::num($metrics['INTERACTION_TO_NEXT_PAINT']['percentile'] ?? null),
                // The API reports CLS p75 multiplied by 100.
                'cls' => $clsPercentile === null ? null : $clsPercentile / 100,
                'overall' => is_string($le['overall_category'] ?? null) ? $le['overall_category'] : null,
            ];
        }

        $opportunities = [];
        foreach (self::OPPORTUNITY_AUDITS as $key) {
            $opp = self::extractOpportunity($audits, $key);
            if ($opp !== null) {
                $opportunities[$key] = $opp;
            }
        }

        return new PsiRunResult($lighthouse, $crux, $opportunities, self::extractLcpDiscovery($audits));
    }

    /**
     * Median of the non-null values (null when none). Port of median().
     *
     * @param  list<float|null>  $values
     */
    public static function median(array $values): ?float
    {
        $nums = array_values(array_filter($values, static fn (?float $v): bool => $v !== null));
        sort($nums);
        $count = count($nums);
        if ($count === 0) {
            return null;
        }
        $mid = intdiv($count, 2);

        return $count % 2 === 1 ? $nums[$mid] : ($nums[$mid - 1] + $nums[$mid]) / 2;
    }

    /**
     * Extract one opportunity audit (score + savings + affected resources),
     * tolerant of both insight and legacy shapes. Null when the audit is absent.
     *
     * @param  array<string, mixed>  $audits
     */
    private static function extractOpportunity(array $audits, string $key): ?PsiOpportunity
    {
        $a = $audits[$key] ?? null;
        if (! is_array($a)) {
            return null;
        }
        $details = is_array($a['details'] ?? null) ? $a['details'] : [];
        $rawItems = is_array($details['items'] ?? null) ? $details['items'] : [];
        // Only resource items carry a URL (insight checklists do not).
        $resourceItems = array_values(array_filter(
            $rawItems,
            static fn ($it): bool => is_array($it) && is_string($it['url'] ?? null),
        ));

        // Byte savings: overallSavingsBytes (classic) or the sum of wastedBytes (insight).
        $savingsBytes = self::intOrNull(self::num($details['overallSavingsBytes'] ?? null));
        if ($savingsBytes === null) {
            $sum = 0;
            foreach ($resourceItems as $it) {
                $w = $it['wastedBytes'] ?? null;
                if (is_int($w) || is_float($w)) {
                    $sum += (int) $w;
                }
            }
            $savingsBytes = $sum > 0 ? $sum : null;
        }

        // Ms savings: overallSavingsMs (classic) or metricSavings.LCP (insight).
        $metricSavings = is_array($a['metricSavings'] ?? null) ? $a['metricSavings'] : [];
        $savingsMs = self::num($details['overallSavingsMs'] ?? null) ?? self::num($metricSavings['LCP'] ?? null);

        $items = [];
        foreach ($resourceItems as $it) {
            if (count($items) >= self::MAX_OPPORTUNITY_ITEMS) {
                break;
            }
            $items[] = [
                'url' => (string) $it['url'],
                'wastedBytes' => self::intOrNull(self::num($it['wastedBytes'] ?? null)),
                'totalBytes' => self::intOrNull(self::num($it['totalBytes'] ?? null)),
                'reason' => self::firstReason($it),
            ];
        }

        return new PsiOpportunity(
            id: $key,
            score: self::num($a['score'] ?? null),
            savingsBytes: $savingsBytes,
            savingsMs: $savingsMs,
            items: $items,
        );
    }

    /**
     * Parse the `lcp-discovery-insight` checklist (Lighthouse 12+).
     *
     * @param  array<string, mixed>  $audits
     * @return array{eagerlyLoaded: bool|null, priorityHinted: bool|null, requestDiscoverable: bool|null}|null
     */
    private static function extractLcpDiscovery(array $audits): ?array
    {
        $a = $audits['lcp-discovery-insight'] ?? null;
        if (! is_array($a)) {
            return null;
        }
        $details = is_array($a['details'] ?? null) ? $a['details'] : [];
        $items = is_array($details['items'] ?? null) ? $details['items'] : [];
        $checklist = null;
        foreach ($items as $it) {
            if (is_array($it) && ($it['type'] ?? null) === 'checklist' && is_array($it['items'] ?? null)) {
                $checklist = $it['items'];
                break;
            }
        }
        if ($checklist === null) {
            return null;
        }
        $boolOf = static function (string $k) use ($checklist): ?bool {
            $entry = $checklist[$k] ?? null;
            $value = is_array($entry) ? ($entry['value'] ?? null) : null;

            return is_bool($value) ? $value : null;
        };

        return [
            'eagerlyLoaded' => $boolOf('eagerlyLoaded'),
            'priorityHinted' => $boolOf('priorityHinted'),
            'requestDiscoverable' => $boolOf('requestDiscoverable'),
        ];
    }

    /**
     * The first non-empty reason on an item — its own or a subItem's.
     *
     * @param  array<string, mixed>  $it
     */
    private static function firstReason(array $it): ?string
    {
        $reason = $it['reason'] ?? null;
        if (is_string($reason) && $reason !== '') {
            return $reason;
        }
        $subItems = $it['subItems'] ?? null;
        $items = is_array($subItems) && is_array($subItems['items'] ?? null) ? $subItems['items'] : [];
        foreach ($items as $s) {
            if (is_array($s) && is_string($s['reason'] ?? null) && $s['reason'] !== '') {
                return $s['reason'];
            }
        }

        return null;
    }

    /**
     * A lab metric's numericValue (ms/score), or null when the audit is absent.
     *
     * @param  array<string, mixed>  $audits
     */
    private static function auditValue(array $audits, string $key): ?float
    {
        $audit = $audits[$key] ?? null;

        return is_array($audit) ? self::num($audit['numericValue'] ?? null) : null;
    }

    private static function num(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null;
    }

    private static function intOrNull(?float $value): ?int
    {
        return $value === null ? null : (int) round($value);
    }
}
