<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * A PageSpeed Insights run (mobile, median-of-3) as consumed by the v2 PSI
 * evaluators (3.5 modern images, 3.6 lazy-load). Port of PsiRunResult.
 */
final readonly class PsiRunResult
{
    /**
     * @param  array{performance?: float|null, lcp?: float|null, cls?: float|null, tbt?: float|null, fcp?: float|null, si?: float|null}  $lighthouse  lab metrics (LCP/CLS in ms/score)
     * @param  array{lcp?: float|null, cls?: float|null, inp?: float|null, overall?: string|null}|null  $crux  field data (CrUX)
     * @param  array<string, PsiOpportunity>  $opportunities  keyed by audit id
     * @param  array{eagerlyLoaded?: bool|null, priorityHinted?: bool|null, requestDiscoverable?: bool|null}|null  $lcpDiscovery  the lcp-discovery-insight checklist
     */
    public function __construct(
        public array $lighthouse,
        public ?array $crux,
        public array $opportunities,
        public ?array $lcpDiscovery = null,
    ) {}
}
