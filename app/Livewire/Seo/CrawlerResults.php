<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\SiteCrawl;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class CrawlerResults extends Component
{
    use WithPagination;

    public SiteCrawl $siteCrawl;

    public string $statusFilter = '';

    public string $search = '';

    public string $sortBy = 'url';

    public string $sortDir = 'asc';

    public string $tab = 'overview';

    public function mount(SiteCrawl $siteCrawl): void
    {
        $this->siteCrawl = $siteCrawl;
    }

    #[Computed]
    public function summary(): array
    {
        return $this->siteCrawl->summary ?? [];
    }

    #[Computed]
    public function pages()
    {
        $query = $this->siteCrawl->crawledPages();

        if ($this->statusFilter === '2xx') {
            $query->whereBetween('status_code', [200, 299]);
        } elseif ($this->statusFilter === '3xx') {
            $query->whereBetween('status_code', [300, 399]);
        } elseif ($this->statusFilter === '4xx') {
            $query->whereBetween('status_code', [400, 499]);
        } elseif ($this->statusFilter === '5xx') {
            $query->whereBetween('status_code', [500, 599]);
        } elseif ($this->statusFilter === 'issues') {
            $query->whereRaw("jsonb_array_length(COALESCE(issues, '[]'::jsonb)) > 0");
        }

        if ($this->search) {
            $query->where('url', 'ilike', "%{$this->search}%");
        }

        $allowed = ['url', 'status_code', 'response_time_ms', 'word_count', 'title'];
        $sort = in_array($this->sortBy, $allowed) ? $this->sortBy : 'url';

        return $query->orderBy($sort, $this->sortDir)->paginate(50);
    }

    #[Computed]
    public function links(): array
    {
        if ($this->tab !== 'links') {
            return [];
        }

        $pages = $this->siteCrawl->crawledPages()
            ->whereNotNull('internal_links')
            ->limit(100)
            ->get(['url', 'internal_links', 'external_links', 'status_code']);

        $allLinks = [];
        foreach ($pages as $page) {
            foreach ($page->internal_links ?? [] as $link) {
                $allLinks[] = array_merge($link, ['source' => $page->url, 'type' => 'internal']);
            }
            foreach ($page->external_links ?? [] as $link) {
                $allLinks[] = array_merge($link, ['source' => $page->url, 'type' => 'external']);
            }
        }

        return array_slice($allLinks, 0, 500);
    }

    #[Computed]
    public function imageStats(): array
    {
        if ($this->tab !== 'images') {
            return [];
        }

        return $this->siteCrawl->crawledPages()
            ->where('images_count', '>', 0)
            ->get(['url', 'images_count', 'images_without_alt'])
            ->map(fn ($p) => [
                'url' => $p->url,
                'total' => $p->images_count,
                'without_alt' => $p->images_without_alt,
            ])
            ->all();
    }

    #[Computed]
    public function previousCrawls()
    {
        return SiteCrawl::where('site_id', $this->siteCrawl->site_id)
            ->where('id', '!=', $this->siteCrawl->id)
            ->where('status', SiteCrawl::STATUS_COMPLETED)
            ->latest()
            ->limit(10)
            ->get(['id', 'created_at', 'pages_crawled']);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function setStatusFilter(string $filter): void
    {
        $this->statusFilter = $filter;
        $this->resetPage();
        unset($this->pages);
    }

    public function setSort(string $col): void
    {
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $col;
            $this->sortDir = 'asc';
        }
        unset($this->pages);
    }

    public function updatedSearch(): void
    {
        $this->search = substr(trim($this->search), 0, 200);
        $this->resetPage();
        unset($this->pages);
    }

    public function exportCsv()
    {
        $pages = $this->siteCrawl->crawledPages()->orderBy('url')->get();
        $filename = 'crawl-'.$this->siteCrawl->id.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($pages) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['URL', 'Status', 'Title', 'Meta Description', 'Response Time (ms)', 'Word Count', 'H1 Count', 'Images', 'Images No Alt', 'Depth']);

            foreach ($pages as $page) {
                fputcsv($handle, [
                    $page->url,
                    $page->status_code,
                    $page->title,
                    $page->meta_description,
                    $page->response_time_ms,
                    $page->word_count,
                    $page->h1_count,
                    $page->images_count,
                    $page->images_without_alt,
                    $page->depth,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        return view('livewire.seo.crawler-results')
            ->layout('components.layouts.app', ['title' => 'Crawl Results']);
    }
}
