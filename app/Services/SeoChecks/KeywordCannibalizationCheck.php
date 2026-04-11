<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

use App\Services\ContentIntelligenceService;

class KeywordCannibalizationCheck
{
    private ?int $siteId = null;

    public function withSiteId(int $siteId): self
    {
        $this->siteId = $siteId;

        return $this;
    }

    public function check(array $connectorData, ?array $gscData = null): array
    {
        if (! $this->siteId) {
            return [];
        }

        $site = \App\Models\Site::find($this->siteId);
        if (! $site) {
            return [];
        }

        $cannibalized = app(ContentIntelligenceService::class)->detectCannibalization($site);

        if (empty($cannibalized)) {
            return [];
        }

        $issues = [];

        foreach ($cannibalized as $keyword => $data) {
            $pageCount = count($data['pages']);
            $pageUrls = implode(', ', array_slice(array_column($data['pages'], 'url'), 0, 5));

            $issues[] = [
                'category' => 'content',
                'severity' => $pageCount >= 3 ? 'high' : 'medium',
                'title' => "Keyword cannibalization: \"{$keyword}\" ({$pageCount} pages)",
                'description' => "The keyword \"{$keyword}\" targets {$pageCount} pages: {$pageUrls}. Multiple pages competing for the same keyword dilute ranking power.",
                'url' => $data['pages'][0]['url'] ?? null,
                'recommendation' => 'Consolidate content to a single authoritative page for this keyword, or differentiate the keyword targeting across pages.',
                'meta' => ['keyword' => $keyword, 'pages' => array_slice($data['pages'], 0, 10), 'page_count' => $pageCount],
            ];
        }

        return $issues;
    }
}
