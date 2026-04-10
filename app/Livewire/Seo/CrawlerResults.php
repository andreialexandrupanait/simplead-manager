<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\CrawledPage;
use App\Models\SiteCrawl;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class CrawlerResults extends Component
{
    use WithPagination;

    public SiteCrawl $siteCrawl;

    public string $tab = 'overview';

    // Pages tab state
    public string $quickFilter = '';

    public string $search = '';

    public string $sortBy = 'url';

    public string $sortDir = 'asc';

    public array $visibleColumns = [
        'url', 'status_code', 'title', 'title_length', 'meta_desc_length',
        'h1_count', 'word_count', 'response_time_ms', 'depth', 'images_count',
    ];

    // Page detail drawer
    public ?int $selectedPageId = null;

    /** All available columns for toggle */
    public const COLUMNS = [
        'url' => 'URL',
        'status_code' => 'Status',
        'title' => 'Title',
        'title_length' => 'Title Len',
        'meta_description' => 'Meta Desc',
        'meta_desc_length' => 'Desc Len',
        'h1_count' => 'H1',
        'h2_count' => 'H2',
        'h3_count' => 'H3',
        'word_count' => 'Words',
        'canonical_url' => 'Canonical',
        'canonical_self_ref' => 'Self Canonical',
        'meta_robots' => 'Meta Robots',
        'response_time_ms' => 'Time (ms)',
        'content_length' => 'Size',
        'internal_links_count' => 'Int Links',
        'external_links_count' => 'Ext Links',
        'images_count' => 'Images',
        'images_without_alt' => 'Imgs No Alt',
        'depth' => 'Depth',
        'readability_score' => 'Readability',
    ];

    /** Quick filter definitions */
    public const QUICK_FILTERS = [
        '' => 'All',
        '2xx' => '2xx',
        '3xx' => '3xx',
        '4xx' => '4xx',
        '5xx' => '5xx',
        'noindex' => 'Noindex',
        'no_title' => 'No Title',
        'title_long' => 'Title >60',
        'no_desc' => 'No Meta Desc',
        'no_h1' => 'No H1',
        'multi_h1' => 'Multiple H1',
        'slow' => 'Slow >2s',
        'deep' => 'Depth >5',
        'has_issues' => 'Has Issues',
        'no_canonical' => 'No Canonical',
    ];

    public function mount(SiteCrawl $siteCrawl): void
    {
        $this->siteCrawl = $siteCrawl;
    }

    // ── Overview ─────────────────────────────────────────────────────

    #[Computed]
    public function summary(): array
    {
        return $this->siteCrawl->summary ?? [];
    }

    #[Computed]
    public function overviewStats(): array
    {
        $pages = $this->siteCrawl->pages();
        $total = $pages->count();

        // Response time distribution
        $fast = (clone $pages)->where('response_time_ms', '<', 200)->count();
        $medium = (clone $pages)->whereBetween('response_time_ms', [200, 500])->count();
        $slow = (clone $pages)->whereBetween('response_time_ms', [500, 2000])->count();
        $verySlow = (clone $pages)->where('response_time_ms', '>', 2000)->count();

        // Depth distribution
        $depths = [];
        for ($d = 0; $d <= 5; $d++) {
            $depths[$d] = (clone $pages)->where('depth', $d)->count();
        }
        $depths['6+'] = (clone $pages)->where('depth', '>', 5)->count();

        // Indexable pages (2xx, not noindex, has canonical self-ref or no canonical)
        $indexable = (clone $pages)
            ->whereBetween('status_code', [200, 299])
            ->where(function ($q) {
                $q->whereNull('meta_robots')
                    ->orWhere('meta_robots', 'not ilike', '%noindex%');
            })
            ->count();

        return [
            'total' => $total,
            'indexable' => $indexable,
            'response_time' => compact('fast', 'medium', 'slow', 'verySlow'),
            'depths' => $depths,
        ];
    }

    // ── Pages Tab ────────────────────────────────────────────────────

    #[Computed]
    public function pages()
    {
        $query = $this->siteCrawl->pages();

        $this->applyQuickFilter($query);

        if ($this->search) {
            $query->where('url', 'ilike', "%{$this->search}%");
        }

        $sortable = array_keys(self::COLUMNS);
        $sort = in_array($this->sortBy, $sortable) ? $this->sortBy : 'url';

        return $query->orderBy($sort, $this->sortDir)->paginate(50);
    }

    private function applyQuickFilter($query): void
    {
        match ($this->quickFilter) {
            '2xx' => $query->whereBetween('status_code', [200, 299]),
            '3xx' => $query->whereBetween('status_code', [300, 399]),
            '4xx' => $query->whereBetween('status_code', [400, 499]),
            '5xx' => $query->whereBetween('status_code', [500, 599]),
            'noindex' => $query->where(fn ($q) => $q->where('meta_robots', 'ilike', '%noindex%')->orWhere('x_robots_tag', 'ilike', '%noindex%')),
            'no_title' => $query->where(fn ($q) => $q->whereNull('title')->orWhere('title', '')),
            'title_long' => $query->where('title_length', '>', 60),
            'no_desc' => $query->where(fn ($q) => $q->whereNull('meta_description')->orWhere('meta_description', '')),
            'no_h1' => $query->where('h1_count', 0),
            'multi_h1' => $query->where('h1_count', '>', 1),
            'slow' => $query->where('response_time_ms', '>', 2000),
            'deep' => $query->where('depth', '>', 5),
            'has_issues' => $query->whereRaw("jsonb_array_length(COALESCE(issues, '[]'::jsonb)) > 0"),
            'no_canonical' => $query->where(fn ($q) => $q->whereNull('canonical_url')->orWhere('canonical_url', '')),
            default => null,
        };
    }

    // ── Issues Tab ───────────────────────────────────────────────────

    #[Computed]
    public function issuesGrouped(): array
    {
        if ($this->tab !== 'issues') {
            return [];
        }

        $pages = $this->siteCrawl->pages()
            ->whereRaw("jsonb_array_length(COALESCE(issues, '[]'::jsonb)) > 0")
            ->get(['id', 'url', 'issues']);

        $grouped = []; // severity => type => [{url, message}]

        foreach ($pages as $page) {
            foreach ($page->issues ?? [] as $issue) {
                $severity = $issue['severity'] ?? 'info';
                $type = $issue['type'] ?? 'unknown';
                $grouped[$severity][$type][] = [
                    'page_id' => $page->id,
                    'url' => $page->url,
                    'message' => $issue['message'] ?? '',
                ];
            }
        }

        // Sort severity order
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];
        uksort($grouped, fn ($a, $b) => ($order[$a] ?? 9) - ($order[$b] ?? 9));

        return $grouped;
    }

    // ── Links Tab ────────────────────────────────────────────────────

    #[Computed]
    public function linksData(): array
    {
        if ($this->tab !== 'links') {
            return [];
        }

        $pages = $this->siteCrawl->pages()
            ->whereNotNull('internal_links')
            ->limit(200)
            ->get(['url', 'internal_links', 'external_links']);

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

    // ── Images Tab ───────────────────────────────────────────────────

    #[Computed]
    public function imagesData(): array
    {
        if ($this->tab !== 'images') {
            return [];
        }

        return $this->siteCrawl->pages()
            ->where('images_count', '>', 0)
            ->get(['url', 'images_count', 'images_without_alt', 'images'])
            ->flatMap(function ($page) {
                return collect($page->images ?? [])->map(fn ($img) => [
                    'page_url' => $page->url,
                    'url' => $img['url'] ?? '',
                    'alt' => $img['alt'] ?? '',
                    'width' => $img['width'] ?? null,
                    'height' => $img['height'] ?? null,
                ]);
            })
            ->take(500)
            ->all();
    }

    // ── Page Detail Drawer ───────────────────────────────────────────

    #[Computed]
    public function selectedPage(): ?CrawledPage
    {
        if (! $this->selectedPageId) {
            return null;
        }

        return $this->siteCrawl->pages()->find($this->selectedPageId);
    }

    #[Computed]
    public function selectedPageInlinks(): array
    {
        if (! $this->selectedPage) {
            return [];
        }

        $targetUrl = $this->selectedPage->url;

        // Find pages that link to this page
        return $this->siteCrawl->pages()
            ->whereRaw("internal_links::text ILIKE ?", ["%{$targetUrl}%"])
            ->limit(50)
            ->pluck('url')
            ->all();
    }

    // ── Comparison Tab ───────────────────────────────────────────────

    #[Computed]
    public function previousCrawls()
    {
        $siteId = $this->siteCrawl->site_id;
        if (! $siteId) {
            return collect();
        }

        return SiteCrawl::where('site_id', $siteId)
            ->where('id', '!=', $this->siteCrawl->id)
            ->where('status', SiteCrawl::STATUS_COMPLETED)
            ->latest()
            ->limit(10)
            ->get(['id', 'created_at', 'pages_crawled']);
    }

    // ── UI Actions ───────────────────────────────────────────────────

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function setQuickFilter(string $filter): void
    {
        $this->quickFilter = $filter;
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

    public function selectPage(?int $id): void
    {
        $this->selectedPageId = $this->selectedPageId === $id ? null : $id;
        unset($this->selectedPage, $this->selectedPageInlinks);
    }

    public function toggleColumn(string $col): void
    {
        if (in_array($col, $this->visibleColumns)) {
            $this->visibleColumns = array_values(array_diff($this->visibleColumns, [$col]));
        } else {
            $this->visibleColumns[] = $col;
        }
    }

    public function exportCsv()
    {
        $pages = $this->siteCrawl->pages()->orderBy('url')->get();
        $filename = 'crawl-'.$this->siteCrawl->id.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($pages) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['URL', 'Status', 'Title', 'Title Length', 'Meta Description', 'Meta Desc Length', 'H1 Count', 'H2 Count', 'Word Count', 'Canonical', 'Meta Robots', 'Response Time (ms)', 'Content Length', 'Internal Links', 'External Links', 'Images', 'Images No Alt', 'Depth', 'Readability']);

            foreach ($pages as $page) {
                fputcsv($handle, [
                    $page->url, $page->status_code, $page->title, $page->title_length,
                    $page->meta_description, $page->meta_desc_length, $page->h1_count,
                    $page->h2_count, $page->word_count, $page->canonical_url, $page->meta_robots,
                    $page->response_time_ms, $page->content_length, $page->internal_links_count,
                    $page->external_links_count, $page->images_count, $page->images_without_alt,
                    $page->depth, $page->readability_score,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        $crawlLabel = $this->siteCrawl->site?->name ?? $this->siteCrawl->start_url ?? 'Crawl #'.$this->siteCrawl->id;

        return view('livewire.seo.crawler-results', ['crawlLabel' => $crawlLabel])
            ->layout('components.layouts.app', ['title' => 'Crawl: '.$crawlLabel]);
    }
}
