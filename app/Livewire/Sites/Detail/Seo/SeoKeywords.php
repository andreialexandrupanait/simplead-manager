<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Jobs\TrackKeywordPositions;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\TrackedKeyword;
use App\Services\KeywordTrackingService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class SeoKeywords extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    #[Validate('required|string|min:2|max:255')]
    public string $newKeyword = '';

    public string $chartPeriod = '90d';

    public ?int $selectedKeywordId = null;

    protected function jobTrackingKeys(): array
    {
        return [
            'sync' => 'keyword-tracking-'.$this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();
    }

    #[Computed]
    public function keywords(): Collection
    {
        return app(KeywordTrackingService::class)->getKeywordsWithLatestPosition($this->site);
    }

    #[Computed]
    public function chartData(): array
    {
        if ($this->selectedKeywordId === null) {
            return [];
        }

        $keyword = TrackedKeyword::where('site_id', $this->site->id)
            ->find($this->selectedKeywordId);

        if ($keyword === null) {
            return [];
        }

        $days = match ($this->chartPeriod) {
            '30d' => 30,
            '1y' => 365,
            default => 90,
        };

        $history = app(KeywordTrackingService::class)->getPositionHistory($keyword, $days);

        if ($history->isEmpty()) {
            return [];
        }

        return [
            'labels' => $history->map(fn ($p) => $p->date->format('M d'))->values()->all(),
            'datasets' => [
                [
                    'label' => 'Position',
                    'data' => $history->map(fn ($p) => $p->position)->values()->all(),
                    'color' => '#8D5CF5',
                ],
            ],
        ];
    }

    #[Computed]
    public function hasSearchConsole(): bool
    {
        $connection = $this->site->searchConsoleConnection;

        return $connection !== null && $connection->is_active;
    }

    public function addKeyword(): void
    {
        $this->validate();

        app(KeywordTrackingService::class)->addKeyword($this->site, $this->newKeyword);

        $this->newKeyword = '';
        unset($this->keywords);
    }

    public function removeKeyword(int $id): void
    {
        $keyword = TrackedKeyword::where('site_id', $this->site->id)->find($id);

        if ($keyword === null) {
            return;
        }

        if ($this->selectedKeywordId === $id) {
            $this->selectedKeywordId = null;
            unset($this->chartData);
        }

        app(KeywordTrackingService::class)->removeKeyword($keyword);
        unset($this->keywords);
    }

    public function importFromSearchConsole(): void
    {
        app(KeywordTrackingService::class)->syncFromSearchConsole($this->site);
        unset($this->keywords);
    }

    public function selectKeyword(int $id): void
    {
        $this->selectedKeywordId = $this->selectedKeywordId === $id ? null : $id;
        unset($this->chartData);
    }

    public function setChartPeriod(string $period): void
    {
        if (! in_array($period, ['30d', '90d', '1y'], true)) {
            return;
        }

        $this->chartPeriod = $period;
        unset($this->chartData);
    }

    public function syncFromSearchConsole(): void
    {
        $this->dispatchTrackedJob(
            'sync',
            new TrackKeywordPositions($this->site),
            'Syncing keyword positions from Search Console...'
        );
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->keywords, $this->chartData);
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-keywords')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — SEO',
            ]);
    }
}
