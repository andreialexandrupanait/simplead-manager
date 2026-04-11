<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

use App\Services\ContentIntelligenceService;

class ZeroTrafficCheck
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

        $zeroTrafficPages = app(ContentIntelligenceService::class)->findPagesWithoutTraffic($site);

        if (empty($zeroTrafficPages)) {
            return [];
        }

        $count = count($zeroTrafficPages);
        $urlList = implode(', ', array_slice($zeroTrafficPages, 0, 5));
        $suffix = $count > 5 ? " and ".($count - 5)." more" : '';

        return [[
            'category' => 'content',
            'severity' => $count >= 10 ? 'medium' : 'low',
            'title' => "{$count} page(s) receiving zero organic traffic",
            'description' => "The following pages were found in the crawl but receive no organic search traffic: {$urlList}{$suffix}.",
            'url' => $zeroTrafficPages[0] ?? null,
            'recommendation' => 'Review these pages — consider improving their content, adding internal links, or removing/consolidating thin pages that add no SEO value.',
            'meta' => ['pages' => array_slice($zeroTrafficPages, 0, 50), 'count' => $count],
        ]];
    }
}
