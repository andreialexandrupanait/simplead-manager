<?php
declare(strict_types=1);
namespace App\Livewire\Sites\Detail;

use App\Enums\SeoIssueCategory;
use App\Enums\SeoIssueSeverity;
use App\Jobs\RunSeoAudit;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\SeoAudit;
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
    public bool $isRunning = false;
    public bool $settingsAutoAudit = false;
    public int $settingsInterval = 10080;
    public int $settingsMaxPages = 200;
    public string $settingsSitemapUrl = '';
    public string $settingsPreferredTime = '03:00';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->isRunning = app(SiteAuditService::class)->hasRunningAudit($site);
        $monitor = $site->seoMonitor;
        if ($monitor) { $this->settingsAutoAudit = $monitor->is_active; $this->settingsInterval = $monitor->interval_minutes; $this->settingsMaxPages = $monitor->max_pages ?? 200; $this->settingsSitemapUrl = $monitor->sitemap_url ?? ''; $this->settingsPreferredTime = $monitor->audit_config['preferred_time'] ?? '03:00'; }
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

    #[Computed] public function brokenLinks() { $a = $this->latestCompletedAudit; return $a ? $a->links()->broken()->with('page')->paginate(50, pageName: 'linksPage') : collect(); }
    #[Computed] public function brokenLinksCount(): int { $a = $this->latestCompletedAudit; return $a ? $a->links()->broken()->count() : 0; }
    #[Computed] public function categoryScores(): array { return $this->latestCompletedAudit?->category_scores ?? []; }
    #[Computed] public function auditDiff(): ?array { return $this->latestCompletedAudit?->data['diff'] ?? null; }
    #[Computed] public function severityOptions(): array { return array_map(fn ($s) => ['value' => $s->value, 'label' => $s->label()], SeoIssueSeverity::cases()); }
    #[Computed] public function categoryOptions(): array { return array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], SeoIssueCategory::cases()); }

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
        if (!$l || !$l->isRunning()) { $this->isRunning = false; unset($this->latestAudit, $this->latestCompletedAudit, $this->auditHistory, $this->groupedIssues, $this->pages, $this->categoryScores, $this->brokenLinks, $this->brokenLinksCount); }
    }

    public function updateSettings(): void
    {
        $m = $this->site->seoMonitor ?? \App\Models\SeoMonitor::create(['site_id' => $this->site->id, 'is_active' => true]);
        $config = $m->audit_config ?? [];
        $config['preferred_time'] = $this->settingsPreferredTime;
        $m->update(['is_active' => $this->settingsAutoAudit, 'interval_minutes' => max(1440, $this->settingsInterval), 'max_pages' => min((int) config('seo.crawler.max_pages_hard_limit'), max(10, $this->settingsMaxPages)), 'sitemap_url' => $this->settingsSitemapUrl ?: null, 'audit_config' => $config]);
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
                $this->dispatch('close-modal-seo-fix');
                $this->dispatch('notify', type: 'success', message: 'Meta updated on '.$this->site->name);
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
