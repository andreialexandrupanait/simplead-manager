<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Backlink;
use App\Models\BacklinkSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

class BacklinkService
{
    /**
     * Sync backlinks from Google Search Console Links API.
     */
    public function syncFromGsc(Site $site): int
    {
        $connection = $site->searchConsoleConnection;

        if (! $connection || ! $connection->is_active) {
            return 0;
        }

        $google = $connection->googleConnection;

        if (! $google || ! $google->is_active) {
            return 0;
        }

        try {
            $service = new GoogleSearchConsoleService($google);
            $linksData = $service->getExternalLinks($connection->property_url);
        } catch (\Exception $e) {
            Log::warning("Backlink sync from GSC failed for site {$site->id}: {$e->getMessage()}");

            return 0;
        }

        $externalLinks = $linksData['external_links'] ?? [];
        $synced = 0;
        $today = now()->toDateString();
        $siteHost = parse_url($site->url, PHP_URL_HOST) ?: '';

        foreach ($externalLinks as $link) {
            $targetUrl = $link['target_url'] ?? '';
            if ($targetUrl === '') {
                continue;
            }

            // GSC Links API returns target URLs on our site being linked to
            // We don't get source URLs from this API, so we create aggregate entries
            $domain = 'gsc-aggregate';

            Backlink::updateOrCreate(
                [
                    'site_id' => $site->id,
                    'source_url' => "gsc://external-links/{$targetUrl}",
                    'target_url' => $targetUrl,
                ],
                [
                    'source_domain' => $domain,
                    'anchor_text' => null,
                    'is_nofollow' => false,
                    'first_seen_at' => $today,
                    'last_seen_at' => $today,
                    'lost_at' => null,
                    'source_type' => 'gsc',
                ],
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * Import backlinks from a CSV file.
     *
     * Expected columns: source_url (required), target_url (required),
     * anchor_text (optional), nofollow (optional)
     */
    public function importFromCsv(Site $site, string $filePath): int
    {
        if (! file_exists($filePath)) {
            return 0;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return 0;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return 0;
        }

        $header = array_map(fn ($h) => mb_strtolower(trim($h)), $header);

        $sourceIdx = array_search('source_url', $header);
        $targetIdx = array_search('target_url', $header);

        if ($sourceIdx === false || $targetIdx === false) {
            fclose($handle);

            return 0;
        }

        $anchorIdx = array_search('anchor_text', $header);
        $nofollowIdx = array_search('nofollow', $header);

        $today = now()->toDateString();
        $imported = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $sourceUrl = trim($row[$sourceIdx] ?? '');
            $targetUrl = trim($row[$targetIdx] ?? '');

            if ($sourceUrl === '' || $targetUrl === '') {
                continue;
            }

            $sourceDomain = parse_url($sourceUrl, PHP_URL_HOST) ?: $sourceUrl;
            $anchorText = ($anchorIdx !== false && isset($row[$anchorIdx])) ? trim($row[$anchorIdx]) : null;
            $isNofollow = ($nofollowIdx !== false && isset($row[$nofollowIdx]))
                ? in_array(mb_strtolower(trim($row[$nofollowIdx])), ['1', 'true', 'yes'], true)
                : false;

            Backlink::updateOrCreate(
                [
                    'site_id' => $site->id,
                    'source_url' => $sourceUrl,
                    'target_url' => $targetUrl,
                ],
                [
                    'source_domain' => $sourceDomain,
                    'anchor_text' => $anchorText ?: null,
                    'is_nofollow' => $isNofollow,
                    'first_seen_at' => $today,
                    'last_seen_at' => $today,
                    'lost_at' => null,
                    'source_type' => 'csv_import',
                ],
            );

            $imported++;
        }

        fclose($handle);

        return $imported;
    }

    /**
     * Create a daily snapshot of backlink statistics.
     */
    public function createSnapshot(Site $site): BacklinkSnapshot
    {
        $today = now()->toDateString();

        $active = Backlink::where('site_id', $site->id)->active();
        $totalActive = $active->count();
        $referringDomains = Backlink::where('site_id', $site->id)->active()->distinct('source_domain')->count('source_domain');

        $newToday = Backlink::where('site_id', $site->id)
            ->where('first_seen_at', $today)
            ->count();

        $lostToday = Backlink::where('site_id', $site->id)
            ->where('lost_at', $today)
            ->count();

        $dofollowCount = Backlink::where('site_id', $site->id)
            ->active()
            ->where('is_nofollow', false)
            ->count();

        $nofollowCount = Backlink::where('site_id', $site->id)
            ->active()
            ->where('is_nofollow', true)
            ->count();

        $anchorDistribution = Backlink::where('site_id', $site->id)
            ->active()
            ->whereNotNull('anchor_text')
            ->where('anchor_text', '!=', '')
            ->selectRaw('anchor_text, count(*) as count')
            ->groupBy('anchor_text')
            ->orderByDesc('count')
            ->limit(20)
            ->pluck('count', 'anchor_text')
            ->all();

        $topPages = Backlink::where('site_id', $site->id)
            ->active()
            ->selectRaw('target_url, count(*) as count')
            ->groupBy('target_url')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'target_url')
            ->all();

        return BacklinkSnapshot::updateOrCreate(
            ['site_id' => $site->id, 'date' => $today],
            [
                'total_backlinks' => $totalActive,
                'referring_domains' => $referringDomains,
                'new_backlinks' => $newToday,
                'lost_backlinks' => $lostToday,
                'dofollow_count' => $dofollowCount,
                'nofollow_count' => $nofollowCount,
                'anchor_text_distribution' => $anchorDistribution,
                'top_pages' => $topPages,
            ],
        );
    }

    /**
     * Mark backlinks not seen in the latest sync as lost.
     */
    public function detectLostBacklinks(Site $site, int $staleDays = 30): int
    {
        $cutoff = now()->subDays($staleDays)->toDateString();

        return Backlink::where('site_id', $site->id)
            ->whereNull('lost_at')
            ->where('last_seen_at', '<', $cutoff)
            ->update(['lost_at' => now()->toDateString()]);
    }

    /**
     * Get aggregated backlink statistics for a site.
     */
    public function getStats(Site $site): array
    {
        $total = Backlink::where('site_id', $site->id)->active()->count();
        $referringDomains = Backlink::where('site_id', $site->id)->active()->distinct('source_domain')->count('source_domain');

        $newLast30 = Backlink::where('site_id', $site->id)
            ->where('first_seen_at', '>=', now()->subDays(30)->toDateString())
            ->count();

        $lostLast30 = Backlink::where('site_id', $site->id)
            ->whereNotNull('lost_at')
            ->where('lost_at', '>=', now()->subDays(30)->toDateString())
            ->count();

        return [
            'total' => $total,
            'referring_domains' => $referringDomains,
            'new_last_30_days' => $newLast30,
            'lost_last_30_days' => $lostLast30,
            'dofollow' => Backlink::where('site_id', $site->id)->active()->where('is_nofollow', false)->count(),
            'nofollow' => Backlink::where('site_id', $site->id)->active()->where('is_nofollow', true)->count(),
        ];
    }

    /**
     * Get anchor text distribution for a site.
     */
    public function getAnchorDistribution(Site $site, int $limit = 30): array
    {
        return Backlink::where('site_id', $site->id)
            ->active()
            ->whereNotNull('anchor_text')
            ->where('anchor_text', '!=', '')
            ->selectRaw('anchor_text, count(*) as count')
            ->groupBy('anchor_text')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'anchor_text' => $row->anchor_text,
                'count' => $row->count,
            ])
            ->all();
    }

    /**
     * Get top linked-to pages on the site.
     */
    public function getTopLinkedPages(Site $site, int $limit = 20): array
    {
        return Backlink::where('site_id', $site->id)
            ->active()
            ->selectRaw('target_url, count(*) as backlink_count, count(distinct source_domain) as domain_count')
            ->groupBy('target_url')
            ->orderByDesc('backlink_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'target_url' => $row->target_url,
                'backlink_count' => $row->backlink_count,
                'domain_count' => $row->domain_count,
            ])
            ->all();
    }
}
