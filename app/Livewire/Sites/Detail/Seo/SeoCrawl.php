<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Jobs\RunSiteCrawl;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\SiteCrawl;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SeoCrawl extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    public int $maxPages = 500;

    public int $rateLimit = 1000;

    public int $maxDepth = 50;

    protected function jobTrackingKeys(): array
    {
        return [
            'crawl' => 'site-crawl-'.$this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();
    }

    #[Computed]
    public function latestCrawl(): ?SiteCrawl
    {
        return $this->site->latestSiteCrawl;
    }

    #[Computed]
    public function recentCrawls(): Collection
    {
        return $this->site->siteCrawls()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function isRunning(): bool
    {
        return $this->latestCrawl?->status === SiteCrawl::STATUS_RUNNING;
    }

    public function startCrawl(): void
    {
        $crawl = SiteCrawl::create([
            'site_id' => $this->site->id,
            'status' => SiteCrawl::STATUS_PENDING,
            'config' => [
                'max_pages' => $this->maxPages,
                'rate_limit_ms' => $this->rateLimit,
                'max_depth' => $this->maxDepth,
            ],
            'pages_found' => 0,
            'pages_crawled' => 0,
            'pages_with_issues' => 0,
            'errors_count' => 0,
        ]);

        $this->dispatchTrackedJob(
            'crawl',
            new RunSiteCrawl($crawl),
            'Starting site crawl...'
        );

        unset($this->latestCrawl, $this->recentCrawls, $this->isRunning);
    }

    public function cancelCrawl(): void
    {
        $crawl = $this->latestCrawl;

        if ($crawl && in_array($crawl->status, [SiteCrawl::STATUS_RUNNING, SiteCrawl::STATUS_PENDING])) {
            $crawl->update([
                'status' => SiteCrawl::STATUS_CANCELLED,
                'completed_at' => now(),
            ]);

            unset($this->latestCrawl, $this->recentCrawls, $this->isRunning);
        }
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->latestCrawl, $this->recentCrawls, $this->isRunning);
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-crawl')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — SEO Crawl',
            ]);
    }
}
