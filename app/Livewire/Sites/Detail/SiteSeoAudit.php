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
    public int $settingsInterval = 10080;
    public int $settingsMaxPages = 200;
    public string $settingsSitemapUrl = '';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->isRunning = app(SiteAuditService::class)->hasRunningAudit($site);
        $monitor = $site->seoMonitor;
        if ($monitor) { $this->settingsInterval = $monitor->interval_minutes; $this->settingsMaxPages = $monitor->max_pages ?? 200; $this->settingsSitemapUrl = $monitor->sitemap_url ?? ''; }
    }

    #[Computed] public function monitor() { return $this->site->seoMonitor; }
    #[Computed] public function latestAudit(): ?SeoAudit { return $this->site->seoAudits()->latest('id')->first(); }
    #[Computed] public function latestCompletedAudit(): ?SeoAudit { return $this->site->seoAudits()->completed()->latest('scanned_at')->first(); }
    #[Computed] public function auditHistory() { return $this->site->seoAudits()->completed()->latest('scanned_at')->limit(20)->get(['id','score','critical_count','high_count','medium_count','low_count','info_count','pages_crawled','scan_duration','scanned_at','category_scores']); }

    #[Computed]
    public function issues()
    {
        $audit = $this->latestCompletedAudit; if (!$audit) return collect();
        $q = $audit->issues();
        if ($this->severityFilter !== '') $q->where('severity', $this->severityFilter);
        if ($this->categoryFilter !== '') $q->where('category', $this->categoryFilter);
        return $q->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")->paginate(25);
    }

    #[Computed]
    public function pages()
    {
        $audit = $this->latestCompletedAudit; if (!$audit) return collect();
        $q = $audit->pages()->whereNotNull('status_code');
        if ($this->pageSearch !== '') $q->where('url', 'ilike', '%'.$this->pageSearch.'%');
        return $q->orderBy('status_code')->paginate(50, pageName: 'pagesPage');
    }

    #[Computed] public function brokenLinks() { $a = $this->latestCompletedAudit; return $a ? $a->links()->broken()->with('page')->limit(50)->get() : collect(); }
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
        if (!$l || !$l->isRunning()) { $this->isRunning = false; unset($this->latestAudit, $this->latestCompletedAudit, $this->auditHistory, $this->issues, $this->pages, $this->categoryScores); }
    }

    public function updateSettings(): void
    {
        $m = $this->site->seoMonitor ?? \App\Models\SeoMonitor::create(['site_id' => $this->site->id, 'is_active' => true]);
        $m->update(['interval_minutes' => max(1440, $this->settingsInterval), 'max_pages' => min((int)config('seo.crawler.max_pages_hard_limit'), max(10, $this->settingsMaxPages)), 'sitemap_url' => $this->settingsSitemapUrl ?: null]);
        $this->dispatch('close-modal-seo-settings');
        $this->dispatch('notify', type: 'success', message: 'Settings updated.');
    }

    public function deleteAudit(int $id): void
    {
        $a = SeoAudit::where('site_id', $this->site->id)->findOrFail($id);
        if ($a->isRunning()) { $this->dispatch('notify', type: 'warning', message: 'Cannot delete running audit.'); return; }
        $a->delete(); unset($this->latestAudit, $this->latestCompletedAudit, $this->auditHistory, $this->issues, $this->pages);
        $this->dispatch('notify', type: 'success', message: 'Audit deleted.');
    }

    public function render() { return view('livewire.sites.detail.site-seo-audit')->layout('components.layouts.app', ['siteContext' => $this->site]); }
}
