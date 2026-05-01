<?php
declare(strict_types=1);
namespace App\Livewire\Sites\Detail;

use App\Enums\SeoIssueCategory;
use App\Enums\SeoIssueSeverity;
use App\Jobs\FetchKeywordRankings;
use App\Jobs\RunSeoAudit;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\SeoAudit;
use App\Models\SeoKeywordRanking;
use App\Models\Site;
use App\Services\SeoAudit\SiteAuditService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SiteSeoAudit extends Component
{
    use WithPagination, WithSiteAuthorization;

    public Site $site;
    #[Url] public string $activeTab = 'issues';
    public string $severityFilter = '';
    public string $categoryFilter = '';
    public string $pageSearch = '';
    public string $linkTypeFilter = '';
    public bool $isRunning = false;
    public bool $settingsAutoAudit = false;
    public int $settingsInterval = 10080;
    public int $settingsMaxPages = 200;
    public string $settingsSitemapUrl = '';
    public string $settingsPreferredTime = '03:00';
    public bool $settingsCrawlEnabled = false;
    public string $newKeyword = '';
    public string $keywordSort = 'position';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->isRunning = app(SiteAuditService::class)->hasRunningAudit($site);
        $monitor = $site->seoMonitor;
        if ($monitor) { $this->settingsAutoAudit = $monitor->is_active; $this->settingsInterval = $monitor->interval_minutes; $this->settingsMaxPages = $monitor->max_pages ?? 200; $this->settingsSitemapUrl = $monitor->sitemap_url ?? ''; $this->settingsPreferredTime = $monitor->audit_config['preferred_time'] ?? '03:00'; $this->settingsCrawlEnabled = $monitor->crawl_enabled ?? false; }
    }

    #[Computed] public function monitor() { return $this->site->seoMonitor; }
    #[Computed] public function latestAudit(): ?SeoAudit { return $this->site->seoAudits()->latest('id')->first(); }
    #[Computed] public function latestCompletedAudit(): ?SeoAudit { return $this->site->seoAudits()->completed()->latest('scanned_at')->first(); }
    #[Computed] public function auditHistory() { return $this->site->seoAudits()->completed()->latest('scanned_at')->limit(20)->get(['id','score','critical_count','high_count','medium_count','low_count','info_count','pages_crawled','scan_duration','scanned_at','category_scores']); }

    #[Computed]
    public function trendData(): array
    {
        $audits = $this->auditHistory->reverse()->values();
        if ($audits->count() < 2) {
            return [];
        }

        return [
            'labels' => $audits->pluck('scanned_at')->map(fn ($d) => $d?->format('M d'))->toArray(),
            'overall' => $audits->pluck('score')->toArray(),
            'technical' => $audits->map(fn ($a) => $a->category_scores['technical'] ?? 0)->toArray(),
            'on_page' => $audits->map(fn ($a) => $a->category_scores['on_page'] ?? 0)->toArray(),
            'performance' => $audits->map(fn ($a) => $a->category_scores['performance'] ?? 0)->toArray(),
            'issues' => $audits->map(fn ($a) => $a->critical_count + $a->high_count + $a->medium_count)->toArray(),
        ];
    }

    #[Computed]
    public function ttfbInsights(): array
    {
        $audit = $this->latestCompletedAudit;
        if (! $audit) {
            return [];
        }
        $pages = $audit->pages()->whereNotNull('ttfb_seconds')->where('status_code', 200)->get(['url', 'ttfb_seconds', 'page_size_bytes']);

        return [
            'fastest' => $pages->sortBy('ttfb_seconds')->take(3)->map(fn ($p) => ['url' => $p->url, 'ttfb' => $p->ttfb_seconds])->values()->toArray(),
            'slowest' => $pages->sortByDesc('ttfb_seconds')->take(3)->map(fn ($p) => ['url' => $p->url, 'ttfb' => $p->ttfb_seconds])->values()->toArray(),
            'largest' => $pages->sortByDesc('page_size_bytes')->take(3)->map(fn ($p) => ['url' => $p->url, 'size_kb' => round(($p->page_size_bytes ?? 0) / 1024, 1)])->values()->toArray(),
            'avg_ttfb' => $pages->count() > 0 ? round($pages->avg('ttfb_seconds'), 3) : 0,
        ];
    }

    #[Computed]
    public function groupedIssues(): \Illuminate\Support\Collection
    {
        $audit = $this->latestCompletedAudit;
        if (! $audit) {
            return collect();
        }

        $q = $audit->issues();
        if ($this->severityFilter !== '') {
            $q->where('severity', $this->severityFilter);
        }
        if ($this->categoryFilter !== '') {
            $q->where('category', $this->categoryFilter);
        }

        $all = $q->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('category')
            ->orderBy('title')
            ->limit(500)
            ->get();

        return $all->groupBy(fn ($i) => $i->title.'||'.$i->severity->value.'||'.$i->category->value)
            ->map(fn ($group) => (object) [
                'title' => $group->first()->title,
                'severity' => $group->first()->severity,
                'category' => $group->first()->category,
                'recommendation' => $group->first()->recommendation,
                'description' => $group->first()->description,
                'urls' => $group->pluck('url')->filter()->unique()->values(),
                'affected_count' => $group->count(),
            ])
            ->values();
    }

    #[Computed]
    public function pages()
    {
        $audit = $this->latestCompletedAudit; if (!$audit) return collect();
        $q = $audit->pages()->whereNotNull('status_code');
        if ($this->pageSearch !== '') $q->where('url', 'ilike', '%'.$this->pageSearch.'%');
        return $q->orderBy('status_code')->paginate(50, pageName: 'pagesPage');
    }

    #[Computed]
    public function brokenLinks()
    {
        $a = $this->latestCompletedAudit;
        if (! $a) {
            return collect();
        }
        $q = $a->links()->broken()->with('page');
        if ($this->linkTypeFilter !== '') {
            $q->where('type', $this->linkTypeFilter);
        }

        return $q->paginate(50, pageName: 'linksPage');
    }

    #[Computed] public function brokenLinksCount(): int { $a = $this->latestCompletedAudit; return $a ? $a->links()->broken()->count() : 0; }

    #[Computed]
    public function brokenLinksStats(): array
    {
        $a = $this->latestCompletedAudit;
        if (! $a) {
            return ['total' => 0, 'internal' => 0, 'external' => 0];
        }

        return [
            'total' => $a->links()->broken()->count(),
            'internal' => $a->links()->broken()->where('type', 'internal')->count(),
            'external' => $a->links()->broken()->where('type', 'external')->count(),
        ];
    }
    #[Computed]
    public function brokenImages()
    {
        $a = $this->latestCompletedAudit;

        return $a ? $a->images()->where('is_broken', true)->with('page')->paginate(50, pageName: 'imagesPage') : collect();
    }

    #[Computed]
    public function brokenImagesCount(): int
    {
        $a = $this->latestCompletedAudit;

        return $a ? $a->images()->where('is_broken', true)->count() : 0;
    }

    #[Computed]
    public function redirectPages()
    {
        $a = $this->latestCompletedAudit;

        return $a ? $a->pages()->whereNotNull('redirect_target')->orderByDesc('redirect_chain_length')->paginate(50, pageName: 'redirectsPage') : collect();
    }

    #[Computed]
    public function redirectPagesCount(): int
    {
        $a = $this->latestCompletedAudit;

        return $a ? $a->pages()->whereNotNull('redirect_target')->count() : 0;
    }

    #[Computed]
    public function keywordRankings()
    {
        $q = SeoKeywordRanking::where('site_id', $this->site->id)
            ->where('recorded_date', function ($sub) {
                $sub->selectRaw('MAX(recorded_date)')
                    ->from('seo_keyword_rankings')
                    ->where('site_id', $this->site->id);
            });

        return match ($this->keywordSort) {
            'clicks' => $q->orderByDesc('clicks'),
            'impressions' => $q->orderByDesc('impressions'),
            'ctr' => $q->orderByDesc('ctr'),
            default => $q->orderBy('position'),
        };
    }

    #[Computed]
    public function trackedKeywords()
    {
        return SeoKeywordRanking::where('site_id', $this->site->id)
            ->where('is_tracked', true)
            ->select('keyword', 'keyword_hash')
            ->distinct('keyword_hash')
            ->get();
    }

    #[Computed]
    public function keywordTrends(): array
    {
        $tracked = $this->trackedKeywords;
        if ($tracked->isEmpty()) {
            return [];
        }

        $hashes = $tracked->pluck('keyword_hash')->toArray();
        $history = SeoKeywordRanking::where('site_id', $this->site->id)
            ->whereIn('keyword_hash', $hashes)
            ->where('recorded_date', '>=', now()->subDays(30))
            ->orderBy('recorded_date')
            ->get()
            ->groupBy('keyword_hash');

        $trends = [];
        foreach ($tracked as $kw) {
            $records = $history->get($kw->keyword_hash, collect());
            if ($records->count() < 2) {
                continue;
            }
            $first = $records->first()->position;
            $last = $records->last()->position;
            $trends[] = [
                'keyword' => $kw->keyword,
                'current' => $last,
                'previous' => $first,
                'change' => round($first - $last, 1), // positive = improved
                'history' => $records->pluck('position')->toArray(),
                'dates' => $records->pluck('recorded_date')->map(fn ($d) => $d->format('M d'))->toArray(),
            ];
        }

        return $trends;
    }

    public function trackKeyword(): void
    {
        $keyword = trim($this->newKeyword);
        if ($keyword === '') {
            return;
        }

        $hash = md5(mb_strtolower($keyword));

        // Mark existing records as tracked
        SeoKeywordRanking::where('site_id', $this->site->id)
            ->where('keyword_hash', $hash)
            ->update(['is_tracked' => true]);

        // If no existing records, create a placeholder
        $exists = SeoKeywordRanking::where('site_id', $this->site->id)->where('keyword_hash', $hash)->exists();
        if (! $exists) {
            SeoKeywordRanking::create([
                'site_id' => $this->site->id,
                'keyword' => mb_substr($keyword, 0, 500),
                'keyword_hash' => $hash,
                'recorded_date' => now()->format('Y-m-d'),
                'is_tracked' => true,
            ]);
        }

        $this->newKeyword = '';
        unset($this->trackedKeywords, $this->keywordTrends);
        $this->dispatch('notify', type: 'success', message: "Keyword '{$keyword}' is now tracked.");
    }

    public function untrackKeyword(string $hash): void
    {
        SeoKeywordRanking::where('site_id', $this->site->id)
            ->where('keyword_hash', $hash)
            ->update(['is_tracked' => false]);

        unset($this->trackedKeywords, $this->keywordTrends);
        $this->dispatch('notify', type: 'success', message: 'Keyword untracked.');
    }

    public function fetchKeywords(): void
    {
        if (! $this->site->searchConsoleConnection?->is_active) {
            $this->dispatch('notify', type: 'warning', message: 'Search Console not connected.');

            return;
        }

        FetchKeywordRankings::dispatch($this->site);
        $this->dispatch('notify', type: 'success', message: 'Fetching keyword rankings...');
    }

    #[Computed] public function categoryScores(): array { return $this->latestCompletedAudit?->category_scores ?? []; }
    #[Computed] public function auditDiff(): ?array { return $this->latestCompletedAudit?->data['diff'] ?? null; }
    #[Computed] public function severityOptions(): array { return array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], SeoIssueSeverity::cases()); }
    #[Computed] public function categoryOptions(): array { return array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], SeoIssueCategory::cases()); }

    #[Computed]
    public function infrastructureData(): array
    {
        $audit = $this->latestCompletedAudit;
        if (! $audit) {
            return [];
        }

        return [
            'sitemap' => $audit->data['sitemap'] ?? null,
            'sitemap_urls_count' => $audit->sitemap_urls_count,
            'robots' => $audit->robots_txt_data,
            'security_headers' => $audit->security_headers,
            'ssl' => $audit->ssl_info,
            'seo_plugin' => $audit->seo_plugin,
            'seo_plugin_version' => $audit->seo_plugin_version,
            'redirect' => $audit->redirect_info ?? $audit->data['redirects'] ?? null,
            'search_visibility' => $audit->data['search_visibility'] ?? null,
        ];
    }

    #[Computed]
    public function internalLinkingStats(): array
    {
        $audit = $this->latestCompletedAudit;
        if (! $audit) {
            return [];
        }

        $pages = $audit->pages()->where('status_code', 200);
        $total = $pages->count();

        return [
            'avg_internal_links' => $total > 0 ? round((float) $pages->avg('internal_link_count'), 1) : 0,
            'orphan_count' => $pages->clone()->where('inbound_internal_links', 0)->where('depth', '>', 0)->count(),
            'deep_pages_count' => $pages->clone()->where('depth', '>', 3)->count(),
            'total_pages' => $total,
        ];
    }

    #[Computed]
    public function fixableIssueTitles(): array
    {
        return [
            'Missing title tag' => 'meta',
            'Title too short' => 'meta',
            'Title too long' => 'meta',
            'Missing meta description' => 'meta',
            'Meta description too short' => 'meta',
            'Meta description too long' => 'meta',
            'Page set to noindex' => 'robots',
            'Noindex page in sitemap' => 'robots',
            'Canonical mismatch' => 'canonical',
            'Missing canonical' => 'canonical',
            'Missing Open Graph tags' => 'og',
        ];
    }

    public function bulkFix(string $issueTitle): void
    {
        if (! $this->site->is_connected || ($this->site->is_prospect ?? false)) {
            $this->dispatch('notify', type: 'warning', message: 'Site not connected.');

            return;
        }

        $fixType = $this->fixableIssueTitles[$issueTitle] ?? null;
        if (! $fixType) {
            $this->dispatch('notify', type: 'warning', message: 'This issue type cannot be auto-fixed.');

            return;
        }

        $audit = $this->latestCompletedAudit;
        if (! $audit) {
            return;
        }

        $issues = $audit->issues()->where('title', $issueTitle)->whereNotNull('url')->get();
        $success = 0;
        $failed = 0;

        foreach ($issues as $issue) {
            $page = $audit->pages()->where('url', $issue->url)->first();
            if (! $page) {
                $failed++;

                continue;
            }

            try {
                $payload = match ($fixType) {
                    'meta' => ['url' => $issue->url, 'meta_title' => $page->title ?? '', 'meta_description' => $page->meta_description ?? ''],
                    'robots' => ['url' => $issue->url, 'action' => 'index'],
                    'canonical' => ['url' => $issue->url, 'canonical_url' => $issue->url],
                    'og' => ['url' => $issue->url, 'og_title' => $page->title ?? '', 'og_description' => $page->meta_description ?? '', 'og_image' => ''],
                    default => null,
                };

                if (! $payload) {
                    continue;
                }

                $endpoint = match ($fixType) {
                    'meta' => '/wp-json/simplead/v1/seo/update-meta',
                    'robots' => '/wp-json/simplead/v1/seo/update-robots',
                    'canonical' => '/wp-json/simplead/v1/seo/update-canonical',
                    'og' => '/wp-json/simplead/v1/seo/update-og',
                };

                $response = \Illuminate\Support\Facades\Http::timeout(15)
                    ->withHeaders(['X-SAM-API-Key' => $this->site->api_key ?? ''])
                    ->post(rtrim($this->site->url, '/') . $endpoint, $payload);

                if ($response->successful()) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Throwable) {
                $failed++;
            }

            usleep(200_000); // 200ms between requests
        }

        $this->dispatch('notify', type: $failed > 0 ? 'warning' : 'success', message: "Bulk fix: {$success} applied" . ($failed > 0 ? ", {$failed} failed" : '') . '.');
    }

    public function runAudit(): void
    {
        if (!RateLimiter::attempt('seo-audit-'.$this->site->id, 1, fn() => true, 60)) { $this->dispatch('notify', type: 'warning', message: 'Please wait.'); return; }
        if ($this->isRunning) { $this->dispatch('notify', type: 'warning', message: 'Already running.'); return; }
        $audit = app(SiteAuditService::class)->startAudit($this->site, 'manual');
        RunSeoAudit::dispatch($this->site, $audit);
        $this->isRunning = true;
        $this->dispatch('notify', type: 'success', message: 'SEO audit started.');
    }

    public function checkProgress(): void
    {
        $l = $this->site->seoAudits()->latest('id')->first();
        if (!$l || !$l->isRunning()) { $this->isRunning = false; unset($this->latestAudit, $this->latestCompletedAudit, $this->auditHistory, $this->groupedIssues, $this->pages, $this->categoryScores, $this->brokenLinks, $this->brokenLinksCount, $this->brokenLinksStats, $this->brokenImages, $this->brokenImagesCount, $this->redirectPages, $this->redirectPagesCount); }
    }

    public function updateSettings(): void
    {
        $m = $this->site->seoMonitor ?? \App\Models\SeoMonitor::create(['site_id' => $this->site->id, 'is_active' => true]);
        $config = $m->audit_config ?? [];
        $config['preferred_time'] = $this->settingsPreferredTime;
        $m->update(['is_active' => $this->settingsAutoAudit, 'interval_minutes' => max(1440, $this->settingsInterval), 'max_pages' => min((int) config('seo.crawler.max_pages_hard_limit'), max(10, $this->settingsMaxPages)), 'sitemap_url' => $this->settingsSitemapUrl ?: null, 'audit_config' => $config, 'crawl_enabled' => $this->settingsCrawlEnabled]);
        $this->dispatch('close-modal-seo-settings');
        $this->dispatch('notify', type: 'success', message: 'Settings updated.');
    }

    public function exportXls()
    {
        $audit = $this->latestCompletedAudit;
        if (! $audit) {
            $this->dispatch('notify', type: 'warning', message: 'No completed audit to export.');

            return;
        }

        $path = app(\App\Services\SeoAudit\ExcelExportService::class)->export($audit);

        return response()->download($path, 'seo-audit-'.$this->site->domain.'-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    public string $fixUrl = '';
    public string $fixTitle = '';
    public string $fixDescription = '';

    public string $fixRobotsUrl = '';
    public string $fixRobotsAction = 'index';

    public string $fixCanonicalUrl = '';
    public string $fixCanonicalTarget = '';

    public string $fixOgUrl = '';
    public string $fixOgTitle = '';
    public string $fixOgDescription = '';
    public string $fixOgImage = '';

    public function openFixModal(string $url): void
    {
        $this->fixUrl = $url;
        $page = $this->latestCompletedAudit?->pages()->where('url', $url)->first();
        $this->fixTitle = $page?->title ?? '';
        $this->fixDescription = $page?->meta_description ?? '';
        $this->dispatch('open-modal-seo-fix');
    }

    public function pushMetaFix(): void
    {
        if (! $this->site->is_connected || $this->site->is_prospect) {
            $this->dispatch('notify', type: 'warning', message: 'Site not connected.');

            return;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders(['X-SAM-API-Key' => $this->site->api_key ?? ''])
                ->post(rtrim($this->site->url, '/').'/wp-json/simplead/v1/seo/update-meta', [
                    'url' => $this->fixUrl,
                    'meta_title' => $this->fixTitle,
                    'meta_description' => $this->fixDescription,
                ]);

            if ($response->successful()) {
                \App\Services\ActivityLogger::log('seo_fix', 'info', 'Meta fix applied', $this->fixUrl, $this->site, ['fix_type' => 'meta', 'url' => $this->fixUrl, 'title' => $this->fixTitle]);
                $this->dispatch('close-modal-seo-fix');
                $this->dispatch('notify', type: 'success', message: 'Meta updated on '.$this->site->name);
            } else {
                $this->dispatch('notify', type: 'error', message: 'Failed: '.($response->json('message') ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Connection failed: '.$e->getMessage());
        }
    }

    public function openRobotsFix(string $url): void
    {
        $this->fixRobotsUrl = $url;
        $page = $this->latestCompletedAudit?->pages()->where('url', $url)->first();
        $this->fixRobotsAction = ($page?->is_indexable === false) ? 'index' : 'noindex';
        $this->dispatch('open-modal-seo-fix-robots');
    }

    public function pushRobotsFix(): void
    {
        if (! $this->site->is_connected || $this->site->is_prospect) {
            $this->dispatch('notify', type: 'warning', message: 'Site not connected.');

            return;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders(['X-SAM-API-Key' => $this->site->api_key ?? ''])
                ->post(rtrim($this->site->url, '/').'/wp-json/simplead/v1/seo/update-robots', [
                    'url' => $this->fixRobotsUrl,
                    'action' => $this->fixRobotsAction,
                ]);

            if ($response->successful()) {
                \App\Services\ActivityLogger::log('seo_fix', 'info', 'Indexing fix: '.$this->fixRobotsAction, $this->fixRobotsUrl, $this->site, ['fix_type' => 'robots', 'action' => $this->fixRobotsAction, 'url' => $this->fixRobotsUrl]);
                $this->dispatch('close-modal-seo-fix-robots');
                $this->dispatch('notify', type: 'success', message: 'Indexing updated on '.$this->site->name);
            } else {
                $this->dispatch('notify', type: 'error', message: 'Failed: '.($response->json('message') ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Connection failed: '.$e->getMessage());
        }
    }

    public function openCanonicalFix(string $url): void
    {
        $this->fixCanonicalUrl = $url;
        $this->fixCanonicalTarget = $url;
        $this->dispatch('open-modal-seo-fix-canonical');
    }

    public function pushCanonicalFix(): void
    {
        if (! $this->site->is_connected || $this->site->is_prospect) {
            $this->dispatch('notify', type: 'warning', message: 'Site not connected.');

            return;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders(['X-SAM-API-Key' => $this->site->api_key ?? ''])
                ->post(rtrim($this->site->url, '/').'/wp-json/simplead/v1/seo/update-canonical', [
                    'url' => $this->fixCanonicalUrl,
                    'canonical_url' => $this->fixCanonicalTarget,
                ]);

            if ($response->successful()) {
                \App\Services\ActivityLogger::log('seo_fix', 'info', 'Canonical fix applied', $this->fixCanonicalUrl, $this->site, ['fix_type' => 'canonical', 'url' => $this->fixCanonicalUrl, 'canonical' => $this->fixCanonicalTarget]);
                $this->dispatch('close-modal-seo-fix-canonical');
                $this->dispatch('notify', type: 'success', message: 'Canonical updated on '.$this->site->name);
            } else {
                $this->dispatch('notify', type: 'error', message: 'Failed: '.($response->json('message') ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Connection failed: '.$e->getMessage());
        }
    }

    public function openOgFix(string $url): void
    {
        $this->fixOgUrl = $url;
        $page = $this->latestCompletedAudit?->pages()->where('url', $url)->first();
        $ogTags = $page?->og_tags ?? [];
        $this->fixOgTitle = $ogTags['og:title'] ?? $page?->title ?? '';
        $this->fixOgDescription = $ogTags['og:description'] ?? $page?->meta_description ?? '';
        $this->fixOgImage = $ogTags['og:image'] ?? '';
        $this->dispatch('open-modal-seo-fix-og');
    }

    public function pushOgFix(): void
    {
        if (! $this->site->is_connected || $this->site->is_prospect) {
            $this->dispatch('notify', type: 'warning', message: 'Site not connected.');

            return;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders(['X-SAM-API-Key' => $this->site->api_key ?? ''])
                ->post(rtrim($this->site->url, '/').'/wp-json/simplead/v1/seo/update-og', [
                    'url' => $this->fixOgUrl,
                    'og_title' => $this->fixOgTitle,
                    'og_description' => $this->fixOgDescription,
                    'og_image' => $this->fixOgImage,
                ]);

            if ($response->successful()) {
                \App\Services\ActivityLogger::log('seo_fix', 'info', 'Open Graph fix applied', $this->fixOgUrl, $this->site, ['fix_type' => 'og', 'url' => $this->fixOgUrl]);
                $this->dispatch('close-modal-seo-fix-og');
                $this->dispatch('notify', type: 'success', message: 'OG tags updated on '.$this->site->name);
            } else {
                $this->dispatch('notify', type: 'error', message: 'Failed: '.($response->json('message') ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Connection failed: '.$e->getMessage());
        }
    }

    public function toggleSearchVisibility(): void
    {
        if (! $this->site->is_connected || $this->site->is_prospect) {
            $this->dispatch('notify', type: 'warning', message: 'Site not connected.');

            return;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(15)
                ->withHeaders(['X-SAM-API-Key' => $this->site->api_key ?? ''])
                ->post(rtrim($this->site->url, '/').'/wp-json/simplead/v1/seo/toggle-search-visibility', [
                    'visible' => true,
                ]);

            if ($response->successful()) {
                \App\Services\ActivityLogger::log('seo_fix', 'info', 'Search visibility enabled', null, $this->site, ['fix_type' => 'search_visibility']);
                $this->dispatch('notify', type: 'success', message: 'Search visibility enabled on '.$this->site->name);
            } else {
                $this->dispatch('notify', type: 'error', message: 'Failed: '.($response->json('message') ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Connection failed: '.$e->getMessage());
        }
    }

    public function deleteAudit(int $id): void
    {
        $a = SeoAudit::where('site_id', $this->site->id)->findOrFail($id);
        if ($a->isRunning()) { $this->dispatch('notify', type: 'warning', message: 'Cannot delete running audit.'); return; }
        $a->delete(); unset($this->latestAudit, $this->latestCompletedAudit, $this->auditHistory, $this->groupedIssues, $this->pages, $this->brokenLinks, $this->brokenLinksCount);
        $this->dispatch('notify', type: 'success', message: 'Audit deleted.');
    }

    public function render() { return view('livewire.sites.detail.site-seo-audit')->layout('components.layouts.app', ['siteContext' => $this->site]); }
}
