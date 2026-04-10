<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Jobs\RunSiteCrawl;
use App\Livewire\Traits\WithJobTracking;
use App\Models\Site;
use App\Models\SiteCrawl;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class CrawlerCreate extends Component
{
    use WithJobTracking;

    #[Validate('required|exists:sites,id')]
    public ?int $siteId = null;

    #[Validate('integer|min:10|max:2000')]
    public int $maxPages = 500;

    #[Validate('integer|min:100|max:5000')]
    public int $rateLimit = 1000;

    #[Validate('integer|min:1|max:50')]
    public int $maxDepth = 50;

    public bool $respectRobots = true;

    public string $urlInput = '';

    protected function jobTrackingKeys(): array
    {
        return ['crawl' => $this->siteId ? 'site-crawl-'.$this->siteId : ''];
    }

    #[Computed]
    public function sites()
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get(['id', 'name', 'url']);
    }

    #[Computed]
    public function detectedSite(): ?Site
    {
        if (! $this->urlInput) {
            return null;
        }

        $host = parse_url(trim($this->urlInput), PHP_URL_HOST);
        if (! $host) {
            return null;
        }

        $normalised = preg_replace('/^www\./', '', strtolower($host));

        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->where('url', 'ilike', "%{$normalised}%")
            ->first();
    }

    public function updatedUrlInput(): void
    {
        unset($this->detectedSite);

        $detected = $this->detectedSite;
        if ($detected) {
            $this->siteId = $detected->id;
        }
    }

    public function startCrawl(): void
    {
        $this->validate();

        $site = Site::findOrFail($this->siteId);
        $this->authorize('update', $site);

        $crawl = SiteCrawl::create([
            'site_id' => $site->id,
            'status' => SiteCrawl::STATUS_PENDING,
            'config' => [
                'max_pages' => $this->maxPages,
                'rate_limit_ms' => $this->rateLimit,
                'max_depth' => $this->maxDepth,
                'respect_robots_txt' => $this->respectRobots,
            ],
        ]);

        $this->dispatchTrackedJob('crawl', new RunSiteCrawl($site, $crawl), 'Starting crawl...');

        session()->flash('success', __('Crawl started.'));
    }

    public function onJobFinished(string $jobName, array $data): void
    {
        $site = $this->siteId ? Site::find($this->siteId) : null;
        if ($site) {
            $latestCrawl = $site->latestSiteCrawl;
            if ($latestCrawl) {
                $this->redirect(route('seo.crawler.show', $latestCrawl), navigate: true);
            }
        }
    }

    public function render()
    {
        return view('livewire.seo.crawler-create')
            ->layout('components.layouts.app', ['title' => 'New Crawl']);
    }
}
