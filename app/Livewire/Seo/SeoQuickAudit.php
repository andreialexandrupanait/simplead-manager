<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Enums\SeoAuditStatus;
use App\Jobs\RunSeoAudit;
use App\Models\SeoAudit;
use App\Models\SeoMonitor;
use App\Models\Site;
use App\Services\SeoAudit\SiteAuditService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SeoQuickAudit extends Component
{
    public string $url = '';

    public bool $isRunning = false;

    public ?int $prospectSiteId = null;

    public function mount(): void
    {
        $lastProspect = Site::where('is_prospect', true)
            ->whereHas('seoAudits', fn ($q) => $q->running())
            ->first();

        if ($lastProspect) {
            $this->prospectSiteId = $lastProspect->id;
            $this->url = $lastProspect->url;
            $this->isRunning = true;
        }
    }

    #[Computed]
    public function currentAudit(): ?SeoAudit
    {
        if (! $this->prospectSiteId) {
            return null;
        }

        return SeoAudit::where('site_id', $this->prospectSiteId)
            ->latest('id')
            ->first();
    }

    #[Computed]
    public function completedAudit(): ?SeoAudit
    {
        if (! $this->prospectSiteId) {
            return null;
        }

        return SeoAudit::where('site_id', $this->prospectSiteId)
            ->completed()
            ->latest('scanned_at')
            ->first();
    }

    #[Computed]
    public function groupedIssues(): \Illuminate\Support\Collection
    {
        $audit = $this->completedAudit;
        if (! $audit) {
            return collect();
        }

        return $audit->issues()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->limit(500)
            ->get()
            ->groupBy(fn ($i) => $i->title.'||'.$i->severity->value.'||'.$i->category->value)
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
    public function categoryScores(): array
    {
        return $this->completedAudit?->category_scores ?? [];
    }

    #[Computed]
    public function pastAudits(): \Illuminate\Support\Collection
    {
        return Site::where('is_prospect', true)
            ->whereHas('seoAudits', fn ($q) => $q->completed())
            ->with(['latestSeoAudit'])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function runQuickAudit(): void
    {
        abort_if((bool) auth()->user()?->isViewer(), 403, 'Viewers cannot run audits.');

        $this->validate(['url' => 'required|url|max:2048']);

        if (! RateLimiter::attempt('seo-quick-audit', 1, fn () => true, 30)) {
            $this->dispatch('notify', type: 'warning', message: 'Please wait before running another audit.');

            return;
        }

        $parsed = parse_url($this->url);
        $domain = $parsed['host'] ?? $this->url;
        $normalizedUrl = ($parsed['scheme'] ?? 'https').'://'.$domain;

        $site = Site::create([
            'name' => $domain,
            'url' => $normalizedUrl,
            'is_prospect' => true,
            'is_connected' => false,
        ]);

        SeoMonitor::create([
            'site_id' => $site->id,
            'is_active' => false,
            'interval_minutes' => 10080,
            'max_pages' => 50,
        ]);

        $audit = SeoAudit::create([
            'site_id' => $site->id,
            'status' => SeoAuditStatus::Pending,
            'data' => ['trigger' => 'quick_audit'],
        ]);

        RunSeoAudit::dispatch($site, $audit);

        $this->prospectSiteId = $site->id;
        $this->isRunning = true;
        $this->dispatch('notify', type: 'success', message: 'Quick audit started for '.$domain);
    }

    public function checkProgress(): void
    {
        $audit = $this->currentAudit;
        if (! $audit || ! $audit->isRunning()) {
            $this->isRunning = false;
            unset($this->currentAudit, $this->completedAudit, $this->groupedIssues, $this->categoryScores, $this->pastAudits);
        }
    }

    public function exportXls()
    {
        $audit = $this->completedAudit;
        if (! $audit) {
            $this->dispatch('notify', type: 'warning', message: 'No completed audit to export.');

            return;
        }

        $site = \App\Models\Site::find($this->prospectSiteId);
        $domain = $site?->domain ?? 'prospect';
        $path = app(\App\Services\SeoAudit\ExcelExportService::class)->export($audit);

        return response()->download($path, 'seo-audit-'.$domain.'-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    public function viewAudit(int $siteId): void
    {
        $this->prospectSiteId = $siteId;
        $site = Site::where('is_prospect', true)->findOrFail($siteId);
        $this->url = $site->url;
        $this->isRunning = app(SiteAuditService::class)->hasRunningAudit($site);
        unset($this->currentAudit, $this->completedAudit, $this->groupedIssues, $this->categoryScores);
    }

    public function deleteProspect(int $siteId): void
    {
        abort_if((bool) auth()->user()?->isViewer(), 403, 'Viewers cannot delete prospects.');

        $site = Site::where('is_prospect', true)->findOrFail($siteId);
        if (app(SiteAuditService::class)->hasRunningAudit($site)) {
            $this->dispatch('notify', type: 'warning', message: 'Cannot delete while audit is running.');

            return;
        }
        $site->seoAudits()->each(function ($audit) {
            $audit->issues()->delete();
            $audit->pages()->each(fn ($p) => $p->links()->delete());
            $audit->pages()->delete();
            $audit->links()->delete();
            $audit->delete();
        });
        $site->seoMonitor?->delete();
        $site->forceDelete();

        if ($this->prospectSiteId === $siteId) {
            $this->prospectSiteId = null;
        }
        unset($this->pastAudits, $this->currentAudit, $this->completedAudit, $this->groupedIssues, $this->categoryScores);
        $this->dispatch('notify', type: 'success', message: 'Prospect audit deleted.');
    }

    public function render()
    {
        return view('livewire.seo.seo-quick-audit')->layout('components.layouts.app');
    }
}
