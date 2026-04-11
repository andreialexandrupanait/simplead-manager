<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Jobs\RunSeoAudit;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\SeoAudit;
use App\Models\SeoIssue;
use App\Models\Site;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SeoAuditResults extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    public string $filterSeverity = 'all';

    public ?int $auditId = null;

    protected function jobTrackingKeys(): array
    {
        return [
            'audit' => 'seo-audit-'.$this->site->id,
        ];
    }

    public function mount(Site $site, ?int $auditId = null): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->auditId = $auditId;
        $this->initJobTracking();
    }

    #[Computed]
    public function audit(): ?SeoAudit
    {
        if ($this->auditId !== null) {
            return SeoAudit::where('site_id', $this->site->id)
                ->find($this->auditId);
        }

        return $this->site->latestSeoAudit;
    }

    #[Computed]
    public function issues(): Collection
    {
        if ($this->audit === null) {
            return collect();
        }

        $query = SeoIssue::where('seo_audit_id', $this->audit->id);

        if ($this->filterSeverity !== 'all') {
            $query->severity($this->filterSeverity);
        }

        return $query->orderBySeverity()->get();
    }

    #[Computed]
    public function issuesByCategory(): Collection
    {
        return $this->issues->groupBy('category');
    }

    #[Computed]
    public function auditHistory(): Collection
    {
        return $this->site->seoAudits()
            ->orderByDesc('scanned_at')
            ->limit(20)
            ->get(['id', 'score', 'scanned_at', 'critical_count', 'high_count']);
    }

    public function selectAudit(int $id): void
    {
        $this->auditId = $id;
        unset($this->audit, $this->issues, $this->issuesByCategory);
    }

    public function setFilterSeverity(string $severity): void
    {
        $this->filterSeverity = $severity;
        unset($this->issues, $this->issuesByCategory);
    }

    public function rerunAudit(): void
    {
        $this->dispatchTrackedJob('audit', new RunSeoAudit($this->site), 'Starting SEO audit...');
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        $this->auditId = null;
        unset($this->audit, $this->issues, $this->issuesByCategory);
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-audit-results')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — SEO',
            ]);
    }
}
