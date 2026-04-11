<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Backlink;
use App\Models\BacklinkSnapshot;
use App\Models\CrawledPage;
use App\Models\Site;
use App\Models\SiteCrawl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BacklinkService
{
    /**
     * Known spam TLD patterns and suspicious domain indicators.
     */
    private const SPAM_TLDS = [
        '.xyz', '.top', '.tk', '.ml', '.ga', '.cf', '.gq', '.buzz', '.club',
        '.icu', '.work', '.site', '.online', '.fun', '.space', '.pw',
    ];

    private const GENERIC_ANCHORS = [
        'click here', 'read more', 'here', 'website', 'link', 'visit',
        'this site', 'more info', 'learn more', 'go here', 'see more',
    ];

    /**
     * Full sync: GSC → targeted crawl → verify existing → spam scores → snapshot.
     */
    public function fullSync(Site $site): array
    {
        $crawler = app(BacklinkCrawlerService::class);

        // 1. Get referring pages from GSC (top pages by clicks)
        $gscSynced = $this->syncFromGsc($site);

        // 2. Discover new referring pages via Google Search
        $discoveredUrls = $crawler->discoverViaSearch($site);
        $discovered = $crawler->crawlReferringPages($site, $discoveredUrls);

        // 3. Targeted crawl: visit unverified existing backlink URLs
        $unverified = $this->getUnverifiedUrls($site);
        $crawled = $crawler->crawlReferringPages($site, $unverified);

        // 4. Verify existing backlinks (check if old links still exist)
        $verification = $crawler->verifyExistingBacklinks($site);

        // 5. Recalculate spam scores
        $this->recalculateSpamScores($site);

        // 6. Create daily snapshot
        $this->createSnapshot($site);

        return [
            'gsc_synced' => $gscSynced,
            'discovered' => $discovered,
            'crawled' => $crawled,
            'verified' => $verification['verified'],
            'lost' => $verification['lost'],
        ];
    }

    /**
     * Get source URLs that haven't been verified by our crawler yet.
     */
    public function getUnverifiedUrls(Site $site): array
    {
        return Backlink::where('site_id', $site->id)
            ->active()
            ->where('source_type', '!=', 'gsc')
            ->where(function ($q) {
                $q->whereNull('last_verified_at')
                    ->orWhere('last_verified_at', '<', now()->subDays(14));
            })
            ->pluck('source_url')
            ->all();
    }

    /**
     * Classify anchor text into type categories.
     */
    public function classifyAnchorText(string $anchorText, string $siteDomain, string $siteName, ?Site $site = null): string
    {
        $anchor = mb_strtolower(trim($anchorText));

        if ($anchor === '' || $anchor === ' ') {
            return 'image';
        }

        // URL anchor — the anchor text is a URL
        if (str_starts_with($anchor, 'http://') || str_starts_with($anchor, 'https://') || str_starts_with($anchor, 'www.')) {
            return 'url';
        }

        // Brand anchor — contains site name or domain
        $domainLower = strtolower($siteDomain);
        $nameLower = mb_strtolower($siteName);
        if (str_contains($anchor, $domainLower) || str_contains($anchor, $nameLower)) {
            return 'brand';
        }

        // Generic anchor
        if (in_array($anchor, self::GENERIC_ANCHORS, true)) {
            return 'generic';
        }

        // Exact match — check against tracked keywords
        if ($site) {
            $keywords = $site->trackedKeywords()->pluck('keyword')->map(fn ($k) => mb_strtolower($k))->all();
            if (in_array($anchor, $keywords, true)) {
                return 'exact_match';
            }

            // Partial match — anchor contains a tracked keyword
            foreach ($keywords as $kw) {
                if (str_contains($anchor, $kw)) {
                    return 'partial_match';
                }
            }
        }

        return 'other';
    }

    /**
     * Discover backlinks from the site's latest crawl data.
     *
     * Extracts external pages that link TO our site by checking
     * referring pages found during crawling (pages that our crawler
     * found linking to external sites — but reversed: what external
     * sites link to us).
     *
     * This works WITHOUT Google Search Console.
     */
    public function discoverFromCrawl(Site $site): int
    {
        $latestCrawl = SiteCrawl::where('site_id', $site->id)
            ->where('status', SiteCrawl::STATUS_COMPLETED)
            ->latest()
            ->first();

        if (! $latestCrawl) {
            return 0;
        }

        $siteHost = parse_url($site->url, PHP_URL_HOST) ?: '';
        $siteHostNormalized = preg_replace('/^www\./', '', strtolower($siteHost));
        $today = now()->toDateString();
        $discovered = 0;

        // Find all crawled pages and check their external links
        // External links pointing TO other sites aren't backlinks,
        // but we can discover pages on OUR site that receive internal links
        // For actual external backlink discovery, we check external_links
        // that point BACK to our own domain (circular references from external)

        // The real value: discover referring pages by crawling the web
        // For now, use a lightweight approach: check if any external pages
        // discovered during crawl link back to us
        $pages = $latestCrawl->pages()
            ->whereRaw("jsonb_array_length(COALESCE(external_links, '[]'::jsonb)) > 0")
            ->get(['url', 'external_links']);

        foreach ($pages as $page) {
            $pageHost = parse_url($page->url, PHP_URL_HOST) ?: '';
            $pageHostNormalized = preg_replace('/^www\./', '', strtolower($pageHost));

            // Skip pages on our own site
            if ($pageHostNormalized === $siteHostNormalized) {
                continue;
            }

            // This external page links to URLs — check if any point to our site
            foreach ($page->external_links ?? [] as $link) {
                $linkUrl = $link['url'] ?? '';
                $linkHost = parse_url($linkUrl, PHP_URL_HOST) ?: '';
                $linkHostNormalized = preg_replace('/^www\./', '', strtolower($linkHost));

                if ($linkHostNormalized === $siteHostNormalized) {
                    $sourceDomain = $pageHostNormalized;
                    $spamScore = $this->calculateSpamScore($sourceDomain, $link['text'] ?? null, false);

                    Backlink::updateOrCreate(
                        [
                            'site_id' => $site->id,
                            'source_url' => $page->url,
                            'target_url' => $linkUrl,
                        ],
                        [
                            'source_domain' => $sourceDomain,
                            'anchor_text' => ! empty($link['text']) ? mb_substr($link['text'], 0, 500) : null,
                            'is_nofollow' => (bool) ($link['nofollow'] ?? false),
                            'link_type' => $link['nofollow'] ?? false ? 'nofollow' : 'dofollow',
                            'first_seen_at' => $today,
                            'last_seen_at' => $today,
                            'lost_at' => null,
                            'source_type' => 'crawl',
                            'spam_score' => $spamScore,
                        ],
                    );

                    $discovered++;
                }
            }
        }

        return $discovered;
    }

    /**
     * Sync backlinks from Google Search Console Links API.
     * Returns 0 gracefully if GSC is not connected (not required).
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

        foreach ($externalLinks as $link) {
            $targetUrl = $link['target_url'] ?? '';
            if ($targetUrl === '') {
                continue;
            }

            Backlink::updateOrCreate(
                [
                    'site_id' => $site->id,
                    'source_url' => "gsc://external-links/{$targetUrl}",
                    'target_url' => $targetUrl,
                ],
                [
                    'source_domain' => 'gsc-aggregate',
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

            $spamScore = $this->calculateSpamScore($sourceDomain, $anchorText, $isNofollow);

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
                    'link_type' => $isNofollow ? 'nofollow' : 'dofollow',
                    'first_seen_at' => $today,
                    'last_seen_at' => $today,
                    'lost_at' => null,
                    'source_type' => 'csv_import',
                    'spam_score' => $spamScore,
                ],
            );

            $imported++;
        }

        fclose($handle);

        return $imported;
    }

    /**
     * Calculate spam score for a backlink (0-100, higher = more spam).
     *
     * Factors: TLD reputation, anchor text patterns, nofollow status,
     * domain length, suspicious patterns.
     */
    public function calculateSpamScore(string $domain, ?string $anchorText, bool $isNofollow, array $pageAnalysis = []): int
    {
        $score = 0;
        $domain = strtolower($domain);

        // 1. Suspicious TLD (+25)
        foreach (self::SPAM_TLDS as $tld) {
            if (str_ends_with($domain, $tld)) {
                $score += 25;

                break;
            }
        }

        // 2. Very long domain name (+15)
        $domainWithoutTld = explode('.', $domain)[0] ?? '';
        if (mb_strlen($domainWithoutTld) > 20) {
            $score += 15;
        }

        // 3. Domain with many hyphens (+10)
        if (substr_count($domain, '-') >= 3) {
            $score += 10;
        }

        // 4. Domain with numbers (+5)
        if (preg_match('/\d{3,}/', $domainWithoutTld)) {
            $score += 5;
        }

        // 5. Generic anchor text (+10)
        if ($anchorText) {
            $normalizedAnchor = mb_strtolower(trim($anchorText));
            if (in_array($normalizedAnchor, self::GENERIC_ANCHORS, true)) {
                $score += 10;
            }

            // Exact match keyword-rich anchor (suspicious if very long) (+10)
            if (mb_strlen($normalizedAnchor) > 50) {
                $score += 10;
            }
        }

        // 6. Nofollow links from suspicious domains are extra suspicious (+5)
        if ($isNofollow && $score > 20) {
            $score += 5;
        }

        // 7. Subdomain depth >3 (+10)
        $parts = explode('.', $domain);
        if (count($parts) > 4) {
            $score += 10;
        }

        // 8. Page quality signals (from targeted crawl)
        if (! empty($pageAnalysis)) {
            // Too many outbound links = link farm indicator (+15)
            if (($pageAnalysis['outbound_links'] ?? 0) > 100) {
                $score += 15;
            } elseif (($pageAnalysis['outbound_links'] ?? 0) > 50) {
                $score += 5;
            }

            // Thin content page (+10)
            if ($pageAnalysis['is_thin'] ?? false) {
                $score += 10;
            }

            // Excessive links on page (+10)
            if ($pageAnalysis['has_excessive_links'] ?? false) {
                $score += 10;
            }
        }

        return min(100, max(0, $score));
    }

    /**
     * Recalculate spam scores for all backlinks of a site.
     */
    public function recalculateSpamScores(Site $site): int
    {
        $backlinks = Backlink::where('site_id', $site->id)->get();
        $updated = 0;

        foreach ($backlinks as $backlink) {
            $spamScore = $this->calculateSpamScore(
                $backlink->source_domain,
                $backlink->anchor_text,
                $backlink->is_nofollow,
            );

            if ($backlink->spam_score !== $spamScore) {
                $backlink->update(['spam_score' => $spamScore]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Create a daily snapshot of backlink statistics.
     */
    public function createSnapshot(Site $site): BacklinkSnapshot
    {
        $today = now()->toDateString();

        $totalActive = Backlink::where('site_id', $site->id)->active()->count();
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

        $spamCount = Backlink::where('site_id', $site->id)
            ->active()
            ->where('spam_score', '>=', 40)
            ->count();

        return [
            'total' => $total,
            'referring_domains' => $referringDomains,
            'new_last_30_days' => $newLast30,
            'lost_last_30_days' => $lostLast30,
            'dofollow' => Backlink::where('site_id', $site->id)->active()->where('is_nofollow', false)->count(),
            'nofollow' => Backlink::where('site_id', $site->id)->active()->where('is_nofollow', true)->count(),
            'spam' => $spamCount,
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
