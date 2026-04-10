<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\CrawledPage;
use App\Models\Site;
use App\Models\SiteCrawl;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeoCrawlResults extends Component
{
    use WithPagination, WithSiteAuthorization;

    public Site $site;

    public ?int $crawlId = null;

    public string $statusFilter = 'all';

    public string $search = '';

    public string $sortBy = 'url';

    public string $sortDir = 'asc';

    public function mount(Site $site, ?int $crawlId = null): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;

        // Redirect to global crawler results for consistent UX
        $crawl = $crawlId
            ? SiteCrawl::where('site_id', $site->id)->find($crawlId)
            : $site->latestSiteCrawl;

        if ($crawl) {
            $this->redirect(route('crawler.show', $crawl), navigate: true);

            return;
        }

        $this->crawlId = $crawlId;
    }

    #[Computed]
    public function crawl(): ?SiteCrawl
    {
        if ($this->crawlId !== null) {
            return SiteCrawl::where('site_id', $this->site->id)
                ->find($this->crawlId);
        }

        return $this->site->latestSiteCrawl;
    }

    #[Computed]
    public function summary(): ?array
    {
        return $this->crawl?->summary;
    }

    #[Computed]
    public function pages(): LengthAwarePaginator
    {
        if ($this->crawl === null) {
            return new LengthAwarePaginator([], 0, 50);
        }

        $query = CrawledPage::where('site_crawl_id', $this->crawl->id);

        // Status filter
        match ($this->statusFilter) {
            '2xx' => $query->whereBetween('status_code', [200, 299]),
            '3xx' => $query->whereBetween('status_code', [300, 399]),
            '4xx' => $query->whereBetween('status_code', [400, 499]),
            '5xx' => $query->whereBetween('status_code', [500, 599]),
            'issues' => $query->whereNotNull('issues')->whereJsonLength('issues', '>', 0),
            default => null,
        };

        // Search by URL
        if ($this->search !== '') {
            $query->where('url', 'ilike', '%'.$this->search.'%');
        }

        // Sorting — only allow known columns
        $allowedColumns = ['url', 'status_code', 'response_time_ms', 'word_count', 'title'];
        $column = in_array($this->sortBy, $allowedColumns, true) ? $this->sortBy : 'url';
        $direction = $this->sortDir === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $direction);

        return $query->paginate(50);
    }

    public function setStatusFilter(string $filter): void
    {
        $this->statusFilter = $filter;
        $this->resetPage();
        unset($this->pages);
    }

    public function setSort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
        unset($this->pages);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->pages);
    }

    public function exportCsv(): StreamedResponse
    {
        $crawl = $this->crawl;

        return response()->streamDownload(function () use ($crawl) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'URL',
                'Status Code',
                'Title',
                'Meta Description',
                'Word Count',
                'Response Time (ms)',
                'H1 Count',
                'Internal Links',
                'External Links',
                'Images',
                'Images Without Alt',
                'Issues',
            ]);

            if ($crawl !== null) {
                CrawledPage::where('site_crawl_id', $crawl->id)
                    ->orderBy('url')
                    ->chunk(500, function ($rows) use ($handle) {
                        foreach ($rows as $page) {
                            $issueCount = count($page->issues ?? []);
                            fputcsv($handle, [
                                $page->url,
                                $page->status_code ?? '',
                                $page->title ?? '',
                                $page->meta_description ?? '',
                                $page->word_count,
                                $page->response_time_ms ?? '',
                                $page->h1_count,
                                $page->internal_links_count,
                                $page->external_links_count,
                                $page->images_count,
                                $page->images_without_alt,
                                $issueCount,
                            ]);
                        }
                    });
            }

            fclose($handle);
        }, 'crawl-results-'.$this->site->id.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-crawl-results')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Crawl Results',
            ]);
    }
}
