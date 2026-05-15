<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Enums\SeoAuditStatus;
use App\Jobs\RunSeoAudit;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\SeoIssue;
use App\Models\Site;
use App\Services\SeoAudit\SiteAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class SeoOverview extends Component
{
    use WithSiteAuthorization;

    #[Url]
    public string $search = '';

    #[Url]
    public string $scoreFilter = '';

    #[Url]
    public string $sort = 'manual';

    #[Url]
    public string $activeTab = 'portfolio';

    #[Computed]
    public function sites()
    {
        $q = Site::query()->portfolio()->whereNull('deleted_at')
            ->with(['seoMonitor', 'latestSeoAudit'])
            ->withCount(['seoAudits as running_audits_count' => fn ($q) => $q->whereIn('status', [SeoAuditStatus::Pending, SeoAuditStatus::Crawling, SeoAuditStatus::Analyzing, SeoAuditStatus::Scoring])]);
        if ($this->search !== '') {
            $escaped = '%'.$this->escapeLike($this->search).'%';
            $q->where(fn ($q) => $q->where('name', 'ilike', $escaped)->orWhere('url', 'ilike', $escaped));
        }
        $sites = $q->get();
        if ($this->scoreFilter !== '') {
            $sites = $sites->filter(function ($s) {
                $sc = $s->latestSeoAudit?->score;
                if ($sc === null) {
                    return $this->scoreFilter === 'no_audit';
                }

                return match ($this->scoreFilter) {
                    'good' => $sc >= 80,
                    'needs_work' => $sc >= 50 && $sc < 80,
                    'poor' => $sc < 50,
                    default => true,
                };
            });
        }

        if ($this->sort === 'manual') {
            return $sites->values();
        }

        return $sites->sortBy(fn ($s) => match ($this->sort) {
            'score_asc' => $s->latestSeoAudit?->score ?? -1,
            'score_desc' => -($s->latestSeoAudit?->score ?? -1),
            'issues' => -($s->latestSeoAudit?->critical_count ?? 0),
            'name' => $s->name,
            default => $s->latestSeoAudit?->score ?? -1,
        })->values();
    }

    #[Computed]
    public function stats(): array
    {
        $wa = $this->sites->filter(fn ($s) => $s->latestSeoAudit?->score !== null);

        return [
            'total_sites' => $this->sites->count(),
            'audited_sites' => $wa->count(),
            'avg_score' => $wa->count() > 0 ? (int) round($wa->avg(fn ($s) => $s->latestSeoAudit->score)) : 0,
            'needs_attention' => $wa->filter(fn ($s) => $s->latestSeoAudit->score < 60)->count(),
            'total_critical' => $wa->sum(fn ($s) => $s->latestSeoAudit->critical_count),
            'total_broken_links' => $wa->sum(fn ($s) => $s->latestSeoAudit->broken_links_count ?? 0),
            'total_broken_images' => $wa->sum(fn ($s) => $s->latestSeoAudit->broken_images_count ?? 0),
        ];
    }

    #[Computed]
    public function scoreDistribution(): array
    {
        $dist = ['90-100' => 0, '70-89' => 0, '50-69' => 0, '30-49' => 0, '0-29' => 0, 'No Audit' => 0];
        foreach ($this->sites as $s) {
            $sc = $s->latestSeoAudit?->score;
            if ($sc === null) {
                $dist['No Audit']++;
            } elseif ($sc >= 90) {
                $dist['90-100']++;
            } elseif ($sc >= 70) {
                $dist['70-89']++;
            } elseif ($sc >= 50) {
                $dist['50-69']++;
            } elseif ($sc >= 30) {
                $dist['30-49']++;
            } else {
                $dist['0-29']++;
            }
        }

        return $dist;
    }

    #[Computed]
    public function categoryAverages(): array
    {
        $audited = $this->sites->filter(fn ($s) => $s->latestSeoAudit?->category_scores !== null);
        if ($audited->isEmpty()) {
            return ['technical' => 0, 'on_page' => 0, 'performance' => 0, 'other' => 0];
        }

        return [
            'technical' => (int) round($audited->avg(fn ($s) => $s->latestSeoAudit->category_scores['technical'] ?? 0)),
            'on_page' => (int) round($audited->avg(fn ($s) => $s->latestSeoAudit->category_scores['on_page'] ?? 0)),
            'performance' => (int) round($audited->avg(fn ($s) => $s->latestSeoAudit->category_scores['performance'] ?? 0)),
            'other' => (int) round($audited->avg(fn ($s) => $s->latestSeoAudit->category_scores['other'] ?? 0)),
        ];
    }

    #[Computed]
    public function topIssues(): \Illuminate\Support\Collection
    {
        $latestAuditIds = $this->sites
            ->map(fn ($s) => $s->latestSeoAudit?->id)
            ->filter()
            ->values()
            ->toArray();

        if (empty($latestAuditIds)) {
            return collect();
        }

        return SeoIssue::whereIn('seo_audit_id', $latestAuditIds)
            ->select('title', 'severity', DB::raw('count(*) as total_count'), DB::raw('count(distinct seo_audit_id) as sites_affected'))
            ->groupBy('title', 'severity')
            ->orderByDesc('sites_affected')
            ->orderByDesc('total_count')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function prospectSites(): \Illuminate\Support\Collection
    {
        return Site::where('is_prospect', true)
            ->with('latestSeoAudit')
            ->latest('id')
            ->limit(20)
            ->get();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    public function runAudit(int $id): void
    {
        if (! RateLimiter::attempt('seo-audit-'.$id, 1, fn () => true, 60)) {
            $this->dispatch('notify', type: 'warning', message: 'Please wait.');

            return;
        }
        $site = Site::findOrFail($id);
        $this->authorizeSiteModification($site);
        if (app(SiteAuditService::class)->hasRunningAudit($site)) {
            $this->dispatch('notify', type: 'warning', message: 'Already running.');

            return;
        }
        $audit = app(SiteAuditService::class)->startAudit($site, 'manual');
        RunSeoAudit::dispatch($site, $audit);
        unset($this->sites, $this->stats);
        $this->dispatch('notify', type: 'success', message: 'Audit started for '.$site->name.'.');
    }

    public function render()
    {
        return view('livewire.seo.seo-overview')->layout('components.layouts.app');
    }
}
