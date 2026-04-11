<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

use App\Models\BacklinkSnapshot;

class BacklinksCheck
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

        $snapshot = BacklinkSnapshot::where('site_id', $this->siteId)
            ->latest('date')
            ->first();

        if (! $snapshot) {
            return [[
                'category' => 'backlinks',
                'severity' => 'info',
                'title' => 'No backlink data available',
                'description' => 'Backlink tracking has not been configured or synced yet.',
                'url' => null,
                'recommendation' => 'Enable backlink syncing from Google Search Console or import backlink data via CSV.',
                'meta' => null,
            ]];
        }

        $issues = [];

        if ($snapshot->referring_domains < 5) {
            $issues[] = [
                'category' => 'backlinks',
                'severity' => $snapshot->referring_domains === 0 ? 'high' : 'medium',
                'title' => "Low referring domain count ({$snapshot->referring_domains})",
                'description' => "Only {$snapshot->referring_domains} unique domain(s) link to the site. More diverse backlinks improve authority.",
                'url' => null,
                'recommendation' => 'Focus on building relationships with relevant sites for quality backlinks.',
                'meta' => ['referring_domains' => $snapshot->referring_domains],
            ];
        }

        if ($snapshot->total_backlinks > 0 && $snapshot->lost_backlinks > 0) {
            $lostRate = ($snapshot->lost_backlinks / $snapshot->total_backlinks) * 100;

            if ($lostRate > 20) {
                $issues[] = [
                    'category' => 'backlinks',
                    'severity' => 'high',
                    'title' => 'High backlink loss rate ('.round($lostRate, 1).'%)',
                    'description' => "{$snapshot->lost_backlinks} backlink(s) lost out of {$snapshot->total_backlinks} total. Investigate why links are being removed.",
                    'url' => null,
                    'recommendation' => 'Check lost backlinks for broken pages or changed content. Reach out to webmasters if links were removed.',
                    'meta' => ['lost' => $snapshot->lost_backlinks, 'total' => $snapshot->total_backlinks, 'rate' => round($lostRate, 1)],
                ];
            }
        }

        $nofollowRate = $snapshot->total_backlinks > 0
            ? ($snapshot->nofollow_count / $snapshot->total_backlinks) * 100
            : 0;

        if ($nofollowRate > 80 && $snapshot->total_backlinks >= 10) {
            $issues[] = [
                'category' => 'backlinks',
                'severity' => 'low',
                'title' => 'High nofollow ratio ('.round($nofollowRate, 1).'%)',
                'description' => 'Most backlinks are nofollow, which pass limited SEO value.',
                'url' => null,
                'recommendation' => 'Seek dofollow backlinks from authoritative sites to improve link equity.',
                'meta' => ['nofollow_count' => $snapshot->nofollow_count, 'dofollow_count' => $snapshot->dofollow_count],
            ];
        }

        return $issues;
    }
}
