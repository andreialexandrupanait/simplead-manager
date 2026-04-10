<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class IndexCoverageCheck
{
    private const NON_INDEXABLE_VERDICTS = ['VERDICT_UNSPECIFIED', 'FAIL'];

    public function check(array $connectorData, ?array $gscData = null): array
    {
        if (! $gscData) {
            return [];
        }

        $coverage = $gscData['index_coverage'] ?? [];

        if (empty($coverage)) {
            return [];
        }

        $issues = [];
        $homepageUrl = ($connectorData['homepage'] ?? [])['url'] ?? null;

        foreach ($coverage as $entry) {
            $entryUrl = $entry['url'] ?? null;
            $verdict = $entry['verdict'] ?? 'VERDICT_UNSPECIFIED';
            $coverageState = $entry['coverage_state'] ?? '';

            if (! in_array($verdict, self::NON_INDEXABLE_VERDICTS, true)) {
                continue;
            }

            $isHomepage = $homepageUrl && $entryUrl && rtrim($entryUrl, '/') === rtrim($homepageUrl, '/');

            $severity = $isHomepage ? 'critical' : 'high';
            $pageLabel = $isHomepage ? 'Homepage' : ($entryUrl ?? 'Page');

            $issues[] = [
                'category' => 'indexing',
                'severity' => $severity,
                'title' => "{$pageLabel} is not indexable in Google Search Console",
                'description' => "Google Search Console reports this page as non-indexable. Coverage state: \"{$coverageState}\".",
                'url' => $entryUrl,
                'recommendation' => 'Investigate why Google cannot index this page. Common causes: noindex tag, robots.txt block, 4xx/5xx status, or redirect issues.',
                'meta' => [
                    'url' => $entryUrl,
                    'verdict' => $verdict,
                    'coverage_state' => $coverageState,
                ],
            ];
        }

        return $issues;
    }
}
