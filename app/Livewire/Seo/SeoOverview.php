<?php
declare(strict_types=1);
namespace App\Livewire\Seo;

use App\Enums\SeoAuditStatus;
use App\Jobs\RunSeoAudit;
use App\Models\Site;
use App\Services\SeoAudit\SiteAuditService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class SeoOverview extends Component
{
    #[Url] public string $search = '';
    #[Url] public string $scoreFilter = '';
    #[Url] public string $sort = 'score_asc';

    #[Computed]
    public function sites()
    {
        $q = Site::query()->whereNull('deleted_at')->with(['seoMonitor','latestSeoAudit'])
            ->withCount(['seoAudits as running_audits_count' => fn($q) => $q->whereIn('status', [SeoAuditStatus::Pending, SeoAuditStatus::Crawling, SeoAuditStatus::Analyzing, SeoAuditStatus::Scoring])]);
        if ($this->search !== '') { $q->where(fn($q) => $q->where('name','ilike',"%{$this->search}%")->orWhere('url','ilike',"%{$this->search}%")); }
        $sites = $q->get();
        if ($this->scoreFilter !== '') { $sites = $sites->filter(function($s) { $sc = $s->latestSeoAudit?->score; if ($sc === null) return $this->scoreFilter === 'no_audit'; return match($this->scoreFilter) { 'good' => $sc >= 80, 'needs_work' => $sc >= 50 && $sc < 80, 'poor' => $sc < 50, default => true }; }); }
        return $sites->sortBy(fn($s) => match($this->sort) { 'score_asc' => $s->latestSeoAudit?->score ?? -1, 'score_desc' => -($s->latestSeoAudit?->score ?? -1), 'issues' => -($s->latestSeoAudit?->critical_count ?? 0), 'name' => $s->name, default => $s->latestSeoAudit?->score ?? -1 })->values();
    }

    #[Computed]
    public function stats(): array
    {
        $wa = $this->sites->filter(fn($s) => $s->latestSeoAudit?->score !== null);
        return ['total_sites' => $this->sites->count(), 'audited_sites' => $wa->count(), 'avg_score' => $wa->count() > 0 ? (int)round($wa->avg(fn($s) => $s->latestSeoAudit->score)) : 0, 'needs_attention' => $wa->filter(fn($s) => $s->latestSeoAudit->score < 60)->count(), 'total_critical' => $wa->sum(fn($s) => $s->latestSeoAudit->critical_count)];
    }

    public function runAudit(int $id): void
    {
        if (!RateLimiter::attempt('seo-audit-'.$id, 1, fn() => true, 60)) { $this->dispatch('notify', type: 'warning', message: 'Please wait.'); return; }
        $site = Site::findOrFail($id);
        if (app(SiteAuditService::class)->hasRunningAudit($site)) { $this->dispatch('notify', type: 'warning', message: 'Already running.'); return; }
        $audit = app(SiteAuditService::class)->startAudit($site, 'manual');
        RunSeoAudit::dispatch($site, $audit);
        unset($this->sites, $this->stats);
        $this->dispatch('notify', type: 'success', message: 'Audit started for '.$site->name.'.');
    }

    public function render() { return view('livewire.seo.seo-overview')->layout('components.layouts.app'); }
}
