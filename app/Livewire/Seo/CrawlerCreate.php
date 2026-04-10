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

    public ?int $siteId = null;

    #[Validate('integer|min:10|max:2000')]
    public int $maxPages = 500;

    #[Validate('integer|min:100|max:5000')]
    public int $rateLimit = 1000;

    #[Validate('integer|min:1|max:50')]
    public int $maxDepth = 50;

    public bool $respectRobots = true;

    #[Validate('required|url|max:2048')]
    public string $urlInput = '';

    private ?int $crawlId = null;

    protected function jobTrackingKeys(): array
    {
        return ['crawl' => $this->crawlId ? 'crawl-'.$this->crawlId : ''];
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
        } else {
            $this->siteId = null;
        }
    }

    public function startCrawl(): void
    {
        $this->validate();

        $site = $this->siteId ? Site::find($this->siteId) : null;

        // Check if there's already a running crawl for this site
        if ($site) {
            $running = SiteCrawl::where('site_id', $site->id)
                ->whereIn('status', [SiteCrawl::STATUS_PENDING, SiteCrawl::STATUS_RUNNING])
                ->exists();

            if ($running) {
                $this->dispatch('notify', type: 'error', message: __('A crawl is already running for this site.'));

                return;
            }
        }

        $startUrl = rtrim(trim($this->urlInput), '/');

        $crawl = SiteCrawl::create([
            'site_id' => $site?->id,
            'start_url' => $startUrl,
            'status' => SiteCrawl::STATUS_PENDING,
            'config' => [
                'max_pages' => $this->maxPages,
                'rate_limit_ms' => $this->rateLimit,
                'max_depth' => $this->maxDepth,
                'respect_robots_txt' => $this->respectRobots,
            ],
        ]);

        $this->crawlId = $crawl->id;

        $trackerKey = $site ? 'site-crawl-'.$site->id : 'standalone-crawl-'.$crawl->id;

        dispatch(new RunSiteCrawl($crawl));

        $this->dispatch('notify', type: 'success', message: __('Crawl started.'));
    }

    public function onJobFinished(string $jobName, array $data): void
    {
        $crawlId = $this->crawlId;
        if ($crawlId) {
            $this->redirect(route('seo.crawler.show', $crawlId), navigate: true);
        }
    }

    public function render()
    {
        return view('livewire.seo.crawler-create')
            ->layout('components.layouts.app', ['title' => 'New Crawl']);
    }
}
