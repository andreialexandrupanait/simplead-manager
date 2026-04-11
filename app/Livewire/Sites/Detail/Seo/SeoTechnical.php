<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\PerformanceTest;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Services\ContentIntelligenceService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SeoTechnical extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function latestAudit(): ?SeoAudit
    {
        return $this->site->latestSeoAudit;
    }

    #[Computed]
    public function robotsTxt(): ?array
    {
        return $this->latestAudit?->data['robots_txt'] ?? null;
    }

    #[Computed]
    public function sitemaps(): ?array
    {
        $fromData = $this->latestAudit?->data['sitemaps'] ?? null;

        if ($fromData !== null) {
            return $fromData;
        }

        $gsc = $this->latestAudit?->data['gsc_sitemaps'] ?? null;

        return $gsc;
    }

    #[Computed]
    public function structuredData(): array
    {
        return $this->latestAudit?->data['structured_data'] ?? [];
    }

    #[Computed]
    public function redirects(): ?array
    {
        return $this->latestAudit?->data['redirects'] ?? null;
    }

    #[Computed]
    public function brokenLinks(): array
    {
        return $this->latestAudit?->data['broken_links'] ?? [];
    }

    #[Computed]
    public function searchVisibility(): ?array
    {
        return $this->latestAudit?->data['search_visibility'] ?? null;
    }

    #[Computed]
    public function cwvSummary(): ?array
    {
        $test = PerformanceTest::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->where('device', 'mobile')
            ->latest('tested_at')
            ->first();

        if (! $test) {
            return null;
        }

        return [
            'lcp' => $test->field_lcp ?? $test->lcp,
            'cls' => $test->field_cls ?? $test->cls,
            'inp' => $test->field_inp,
            'performance_score' => $test->performance_score,
        ];
    }

    #[Computed]
    public function cannibalization(): array
    {
        return app(ContentIntelligenceService::class)->detectCannibalization($this->site);
    }

    #[Computed]
    public function zeroTrafficPages(): array
    {
        return app(ContentIntelligenceService::class)->findPagesWithoutTraffic($this->site);
    }

    #[Computed]
    public function consolidationSuggestions(): array
    {
        return app(ContentIntelligenceService::class)->suggestConsolidation($this->site);
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-technical')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — SEO',
            ]);
    }
}
