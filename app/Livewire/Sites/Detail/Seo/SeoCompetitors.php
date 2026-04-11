<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\CompetitorSite;
use App\Models\Site;
use App\Services\CompetitorAnalysisService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

class SeoCompetitors extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    #[Validate('required|url|max:500')]
    public string $competitorUrl = '';

    public string $competitorName = '';

    #[Url]
    public string $activeTab = 'overview';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function hasSearchConsole(): bool
    {
        $connection = $this->site->searchConsoleConnection;

        return $connection !== null && $connection->is_active;
    }

    #[Computed]
    public function hasTrackedKeywords(): bool
    {
        return $this->site->trackedKeywords()->exists();
    }

    #[Computed]
    public function competitors(): Collection
    {
        return CompetitorSite::where('site_id', $this->site->id)
            ->orderBy('competitor_name')
            ->get();
    }

    #[Computed]
    public function comparisonSummary(): array
    {
        return app(CompetitorAnalysisService::class)->getComparisonSummary($this->site);
    }

    #[Computed]
    public function gapAnalysis(): array
    {
        return app(CompetitorAnalysisService::class)->getGapAnalysis($this->site);
    }

    #[Computed]
    public function overlapAnalysis(): array
    {
        return app(CompetitorAnalysisService::class)->getOverlapAnalysis($this->site);
    }

    public function addCompetitor(): void
    {
        $this->validate();

        app(CompetitorAnalysisService::class)->addCompetitor(
            $this->site,
            $this->competitorUrl,
            $this->competitorName ?: null,
        );

        $this->competitorUrl = '';
        $this->competitorName = '';
        unset($this->competitors, $this->comparisonSummary, $this->gapAnalysis, $this->overlapAnalysis);

        session()->flash('message', 'Competitor added successfully.');
    }

    public function removeCompetitor(int $id): void
    {
        $competitor = CompetitorSite::where('site_id', $this->site->id)->find($id);

        if (! $competitor) {
            return;
        }

        app(CompetitorAnalysisService::class)->removeCompetitor($competitor);
        unset($this->competitors, $this->comparisonSummary, $this->gapAnalysis, $this->overlapAnalysis);

        session()->flash('message', 'Competitor removed.');
    }

    public function trackKeywords(int $id): void
    {
        $competitor = CompetitorSite::where('site_id', $this->site->id)->find($id);

        if (! $competitor) {
            return;
        }

        $tracked = app(CompetitorAnalysisService::class)->trackCompetitorKeywords($this->site, $competitor);
        unset($this->comparisonSummary, $this->gapAnalysis, $this->overlapAnalysis);

        session()->flash('message', "Tracked {$tracked} keyword(s) for {$competitor->display_name}.");
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'gap', 'overlap'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-competitors')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — SEO',
            ]);
    }
}
