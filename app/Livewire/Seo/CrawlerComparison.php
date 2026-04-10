<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\SiteCrawl;
use App\Services\Crawler\CrawlComparisonService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CrawlerComparison extends Component
{
    public SiteCrawl $siteCrawl;

    public SiteCrawl $compareTo;

    public function mount(SiteCrawl $siteCrawl, SiteCrawl $compareTo): void
    {
        $this->siteCrawl = $siteCrawl;
        $this->compareTo = $compareTo;
    }

    #[Computed]
    public function comparison(): array
    {
        return app(CrawlComparisonService::class)->compare($this->compareTo, $this->siteCrawl);
    }

    public function render()
    {
        return view('livewire.seo.crawler-comparison')
            ->layout('components.layouts.app', ['title' => 'Crawl Comparison']);
    }
}
