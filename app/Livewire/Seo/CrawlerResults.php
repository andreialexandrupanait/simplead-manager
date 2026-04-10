<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\CrawledPage;
use App\Models\SiteCrawl;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class CrawlerResults extends Component
{
    use WithPagination;

    public SiteCrawl $siteCrawl;

    // Bottom tab bar: data view
    public string $dataTab = 'internal';

    // Right panel: analysis view
    public string $analysisTab = 'overview';

    // Table state
    public string $search = '';

    public string $sortBy = 'url';

    public string $sortDir = 'asc';

    // Page detail
    public ?int $selectedPageId = null;

    /** Data tab definitions: key => [label, icon_hint] */
    public const DATA_TABS = [
        'internal' => 'Internal',
        'external' => 'External',
        'security' => 'Security',
        'response_codes' => 'Response Codes',
        'page_titles' => 'Page Titles',
        'meta_desc' => 'Meta Description',
        'h1' => 'H1',
        'h2' => 'H2',
        'content' => 'Content',
        'images' => 'Images',
        'canonicals' => 'Canonicals',
        'directives' => 'Directives',
        'hreflang' => 'Hreflang',
        'javascript' => 'JavaScript',
        'css' => 'CSS',
        'links' => 'Links',
        'structured_data' => 'Structured Data',
        'sitemaps' => 'Sitemaps',
    ];

    public const ANALYSIS_TABS = [
        'overview' => 'Overview',
        'issues' => 'Issues',
        'structure' => 'Site Structure',
        'response_times' => 'Response Times',
        'segments' => 'Segments',
    ];

    public function mount(SiteCrawl $siteCrawl): void
    {
        $this->siteCrawl = $siteCrawl;
    }

    // ═══════════════════════════════════════════════════════════════════
    // DATA TAB QUERIES — each tab returns paginated or collected data
    // ═══════════════════════════════════════════════════════════════════

    #[Computed]
    public function tableData()
    {
        return match ($this->dataTab) {
            'external' => $this->queryExternal(),
            'images' => $this->queryImages(),
            'javascript' => $this->queryScripts(),
            'css' => $this->queryStylesheets(),
            'links' => $this->queryLinks(),
            default => $this->queryPages(),
        };
    }

    #[Computed]
    public function tableColumns(): array
    {
        return match ($this->dataTab) {
            'internal' => ['url', 'status_code', 'title', 'word_count', 'internal_links_count', 'response_time_ms', 'depth'],
            'response_codes' => ['url', 'status_code', 'content_type', 'redirect_url', 'redirect_status_code', 'response_time_ms'],
            'page_titles' => ['url', 'title', 'title_length', 'h1_count', 'status_code'],
            'meta_desc' => ['url', 'meta_description', 'meta_desc_length', 'status_code'],
            'h1' => ['url', 'h1_tags', 'h1_count', 'title', 'status_code'],
            'h2' => ['url', 'h2_count', 'h3_count', 'word_count', 'status_code'],
            'content' => ['url', 'word_count', 'readability_score', 'h1_count', 'h2_count', 'images_count', 'status_code'],
            'images' => ['page_url', 'url', 'alt', 'width', 'height'],
            'canonicals' => ['url', 'canonical_url', 'canonical_self_ref', 'status_code'],
            'directives' => ['url', 'meta_robots', 'x_robots_tag', 'canonical_url', 'status_code'],
            'hreflang' => ['url', 'hreflang', 'status_code'],
            'structured_data' => ['url', 'structured_data_types', 'status_code'],
            'security' => ['url', 'is_https', 'has_mixed_content', 'status_code'],
            'javascript' => ['page_url', 'url', 'type'],
            'css' => ['page_url', 'url', 'media'],
            'links' => ['source', 'url', 'anchor', 'type', 'nofollow'],
            'external' => ['source', 'url', 'anchor', 'nofollow'],
            'sitemaps' => ['url', 'status_code', 'title', 'depth'],
            default => ['url', 'status_code', 'title', 'response_time_ms'],
        };
    }

    /** Column display labels */
    public const COLUMN_LABELS = [
        'url' => 'URL',
        'page_url' => 'Page',
        'source' => 'Source',
        'status_code' => 'Status',
        'title' => 'Title',
        'title_length' => 'Title Len',
        'meta_description' => 'Meta Description',
        'meta_desc_length' => 'Desc Len',
        'h1_tags' => 'H1',
        'h1_count' => 'H1 Count',
        'h2_count' => 'H2',
        'h3_count' => 'H3',
        'word_count' => 'Words',
        'readability_score' => 'Readability',
        'canonical_url' => 'Canonical',
        'canonical_self_ref' => 'Self Ref',
        'meta_robots' => 'Meta Robots',
        'x_robots_tag' => 'X-Robots',
        'hreflang' => 'Hreflang',
        'structured_data_types' => 'Schema Types',
        'response_time_ms' => 'Time (ms)',
        'content_type' => 'Content Type',
        'content_length' => 'Size',
        'internal_links_count' => 'Inlinks',
        'external_links_count' => 'Outlinks',
        'images_count' => 'Images',
        'images_without_alt' => 'No Alt',
        'depth' => 'Depth',
        'redirect_url' => 'Redirect To',
        'redirect_status_code' => 'Redir Code',
        'is_https' => 'HTTPS',
        'has_mixed_content' => 'Mixed Content',
        'anchor' => 'Anchor',
        'type' => 'Type',
        'nofollow' => 'Nofollow',
        'alt' => 'Alt Text',
        'width' => 'W',
        'height' => 'H',
        'media' => 'Media',
        'og_title' => 'OG Title',
        'og_description' => 'OG Desc',
    ];

    private function queryPages()
    {
        $query = $this->siteCrawl->pages();

        // Apply tab-specific filters
        match ($this->dataTab) {
            'internal' => $query->where('content_type', 'ilike', '%text/html%'),
            'response_codes' => null,
            'page_titles' => $query->where('content_type', 'ilike', '%text/html%'),
            'meta_desc' => $query->where('content_type', 'ilike', '%text/html%'),
            'h1' => $query->where('content_type', 'ilike', '%text/html%'),
            'h2' => $query->where('content_type', 'ilike', '%text/html%'),
            'content' => $query->where('content_type', 'ilike', '%text/html%'),
            'canonicals' => $query->where('content_type', 'ilike', '%text/html%'),
            'directives' => $query->where('content_type', 'ilike', '%text/html%'),
            'hreflang' => $query->where('content_type', 'ilike', '%text/html%')->whereRaw("jsonb_array_length(COALESCE(hreflang, '[]'::jsonb)) > 0"),
            'structured_data' => $query->where('content_type', 'ilike', '%text/html%')->whereRaw("jsonb_array_length(COALESCE(structured_data_types, '[]'::jsonb)) > 0"),
            'security' => null,
            'sitemaps' => $query->where('depth', 0), // placeholder — show homepage for now
            default => null,
        };

        if ($this->search) {
            $query->where('url', 'ilike', "%{$this->search}%");
        }

        $sort = in_array($this->sortBy, ['url', 'status_code', 'title', 'title_length', 'meta_desc_length', 'h1_count', 'h2_count', 'word_count', 'response_time_ms', 'depth', 'readability_score', 'content_length', 'internal_links_count', 'external_links_count', 'images_count'])
            ? $this->sortBy : 'url';

        return $query->orderBy($sort, $this->sortDir)->paginate(100);
    }

    private function queryExternal(): array
    {
        $pages = $this->siteCrawl->pages()
            ->whereNotNull('external_links')
            ->whereRaw("jsonb_array_length(COALESCE(external_links, '[]'::jsonb)) > 0")
            ->limit(200)
            ->get(['url', 'external_links']);

        $links = [];
        foreach ($pages as $page) {
            foreach ($page->external_links ?? [] as $link) {
                $links[] = [
                    'source' => $page->url,
                    'url' => $link['url'] ?? '',
                    'anchor' => $link['anchor'] ?? '',
                    'nofollow' => $link['nofollow'] ?? false,
                ];
            }
        }

        return array_slice($links, 0, 1000);
    }

    private function queryImages(): array
    {
        $pages = $this->siteCrawl->pages()
            ->where('images_count', '>', 0)
            ->limit(200)
            ->get(['url', 'images']);

        $images = [];
        foreach ($pages as $page) {
            foreach ($page->images ?? [] as $img) {
                $images[] = [
                    'page_url' => $page->url,
                    'url' => $img['url'] ?? '',
                    'alt' => $img['alt'] ?? '',
                    'width' => $img['width'] ?? null,
                    'height' => $img['height'] ?? null,
                ];
            }
        }

        return array_slice($images, 0, 1000);
    }

    private function queryScripts(): array
    {
        $pages = $this->siteCrawl->pages()
            ->whereRaw("jsonb_array_length(COALESCE(scripts, '[]'::jsonb)) > 0")
            ->limit(200)
            ->get(['url', 'scripts']);

        $scripts = [];
        foreach ($pages as $page) {
            foreach ($page->scripts ?? [] as $s) {
                $scripts[] = [
                    'page_url' => $page->url,
                    'url' => $s['url'] ?? '',
                    'type' => $s['type'] ?? 'text/javascript',
                ];
            }
        }

        return array_slice($scripts, 0, 500);
    }

    private function queryStylesheets(): array
    {
        $pages = $this->siteCrawl->pages()
            ->whereRaw("jsonb_array_length(COALESCE(stylesheets, '[]'::jsonb)) > 0")
            ->limit(200)
            ->get(['url', 'stylesheets']);

        $sheets = [];
        foreach ($pages as $page) {
            foreach ($page->stylesheets ?? [] as $s) {
                $sheets[] = [
                    'page_url' => $page->url,
                    'url' => $s['url'] ?? '',
                    'media' => $s['media'] ?? 'all',
                ];
            }
        }

        return array_slice($sheets, 0, 500);
    }

    private function queryLinks(): array
    {
        $pages = $this->siteCrawl->pages()
            ->limit(150)
            ->get(['url', 'internal_links', 'external_links']);

        $links = [];
        foreach ($pages as $page) {
            foreach ($page->internal_links ?? [] as $link) {
                $links[] = ['source' => $page->url, 'url' => $link['url'] ?? '', 'anchor' => $link['anchor'] ?? '', 'type' => 'internal', 'nofollow' => $link['nofollow'] ?? false];
            }
            foreach ($page->external_links ?? [] as $link) {
                $links[] = ['source' => $page->url, 'url' => $link['url'] ?? '', 'anchor' => $link['anchor'] ?? '', 'type' => 'external', 'nofollow' => $link['nofollow'] ?? false];
            }
        }

        return array_slice($links, 0, 1000);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ANALYSIS PANEL DATA
    // ═══════════════════════════════════════════════════════════════════

    #[Computed]
    public function summary(): array
    {
        return $this->siteCrawl->summary ?? [];
    }

    #[Computed]
    public function overviewStats(): array
    {
        $query = $this->siteCrawl->pages();
        $total = $query->count();

        $indexable = (clone $query)
            ->whereBetween('status_code', [200, 299])
            ->where(fn ($q) => $q->whereNull('meta_robots')->orWhere('meta_robots', 'not ilike', '%noindex%'))
            ->count();

        return ['total' => $total, 'indexable' => $indexable];
    }

    #[Computed]
    public function issuesGrouped(): array
    {
        $pages = $this->siteCrawl->pages()
            ->whereRaw("jsonb_array_length(COALESCE(issues, '[]'::jsonb)) > 0")
            ->get(['id', 'url', 'issues']);

        $grouped = [];
        foreach ($pages as $page) {
            foreach ($page->issues ?? [] as $issue) {
                $severity = $issue['severity'] ?? 'info';
                $type = $issue['type'] ?? 'unknown';
                $grouped[$severity][$type][] = [
                    'url' => $page->url,
                    'message' => $issue['message'] ?? '',
                    'recommendation' => $issue['recommendation'] ?? null,
                ];
            }
        }

        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];
        uksort($grouped, fn ($a, $b) => ($order[$a] ?? 9) - ($order[$b] ?? 9));

        return $grouped;
    }

    #[Computed]
    public function siteStructure(): array
    {
        $depths = [];
        for ($d = 0; $d <= 10; $d++) {
            $count = $this->siteCrawl->pages()->where('depth', $d)->count();
            if ($count === 0 && $d > 5) {
                break;
            }
            $depths[$d] = $count;
        }
        $deep = $this->siteCrawl->pages()->where('depth', '>', 10)->count();
        if ($deep > 0) {
            $depths['10+'] = $deep;
        }

        return $depths;
    }

    #[Computed]
    public function responseTimeStats(): array
    {
        $q = $this->siteCrawl->pages();

        return [
            'fast' => (clone $q)->where('response_time_ms', '<', 200)->count(),
            'medium' => (clone $q)->whereBetween('response_time_ms', [200, 500])->count(),
            'slow' => (clone $q)->whereBetween('response_time_ms', [500, 2000])->count(),
            'very_slow' => (clone $q)->where('response_time_ms', '>', 2000)->count(),
            'avg' => round((float) (clone $q)->avg('response_time_ms'), 0),
            'p90' => $this->percentile(90),
            'slowest' => (clone $q)->orderByDesc('response_time_ms')->limit(10)->get(['url', 'response_time_ms'])->all(),
        ];
    }

    private function percentile(int $p): int
    {
        $total = $this->siteCrawl->pages()->count();
        if ($total === 0) {
            return 0;
        }

        $offset = (int) floor($total * $p / 100);

        return (int) ($this->siteCrawl->pages()
            ->orderBy('response_time_ms')
            ->offset(min($offset, $total - 1))
            ->limit(1)
            ->value('response_time_ms') ?? 0);
    }

    #[Computed]
    public function segments(): array
    {
        $patterns = [
            'Homepage' => '^[^/]*://[^/]+/?$',
            '/blog/*' => '/blog/',
            '/product/*' => '/product/',
            '/category/*' => '/categor',
            '/tag/*' => '/tag/',
            '/page/*' => '/page/',
        ];

        $result = [];
        foreach ($patterns as $label => $pattern) {
            $count = $this->siteCrawl->pages()
                ->where('url', 'similar to', '%'.$pattern.'%')
                ->count();
            if ($count > 0) {
                $result[$label] = $count;
            }
        }

        // Catch uncategorized
        $total = $this->siteCrawl->pages()->count();
        $categorized = array_sum($result);
        if ($total > $categorized) {
            $result['Other'] = $total - $categorized;
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════
    // PAGE DETAIL
    // ═══════════════════════════════════════════════════════════════════

    #[Computed]
    public function selectedPage(): ?CrawledPage
    {
        return $this->selectedPageId
            ? $this->siteCrawl->pages()->find($this->selectedPageId)
            : null;
    }

    #[Computed]
    public function selectedPageInlinks(): array
    {
        if (! $this->selectedPage) {
            return [];
        }

        return $this->siteCrawl->pages()
            ->whereRaw("internal_links::text ILIKE ?", ["%{$this->selectedPage->url}%"])
            ->limit(50)
            ->pluck('url')
            ->all();
    }

    // ═══════════════════════════════════════════════════════════════════
    // UI ACTIONS
    // ═══════════════════════════════════════════════════════════════════

    public function setDataTab(string $tab): void
    {
        $this->dataTab = $tab;
        $this->analysisTab = 'overview'; // reset to table view
        $this->resetPage();
        $this->selectedPageId = null;
        unset($this->tableData, $this->tableColumns, $this->selectedPage, $this->selectedPageInlinks);
    }

    public function setAnalysisTab(string $tab): void
    {
        $this->analysisTab = $tab;
        // Keep dataTab as-is — analysis views are independent
    }

    public function setSort(string $col): void
    {
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $col;
            $this->sortDir = 'asc';
        }
        unset($this->tableData);
    }

    public function updatedSearch(): void
    {
        $this->search = substr(trim($this->search), 0, 200);
        $this->resetPage();
        unset($this->tableData);
    }

    public function selectPage(?int $id): void
    {
        $this->selectedPageId = $this->selectedPageId === $id ? null : $id;
        unset($this->selectedPage, $this->selectedPageInlinks);
    }

    public function exportCsv()
    {
        $pages = $this->siteCrawl->pages()->orderBy('url')->get();
        $filename = 'crawl-'.$this->siteCrawl->id.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($pages) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['URL', 'Status', 'Title', 'Title Length', 'Meta Description', 'Meta Desc Length', 'H1 Count', 'H2 Count', 'Word Count', 'Canonical', 'Meta Robots', 'Response Time (ms)', 'Content Length', 'Internal Links', 'External Links', 'Images', 'Depth']);

            foreach ($pages as $page) {
                fputcsv($handle, [
                    $page->url, $page->status_code, $page->title, $page->title_length,
                    $page->meta_description, $page->meta_desc_length, $page->h1_count,
                    $page->h2_count, $page->word_count, $page->canonical_url, $page->meta_robots,
                    $page->response_time_ms, $page->content_length, $page->internal_links_count,
                    $page->external_links_count, $page->images_count, $page->depth,
                ]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        $crawlLabel = $this->siteCrawl->site?->name ?? $this->siteCrawl->start_url ?? 'Crawl #'.$this->siteCrawl->id;

        return view('livewire.seo.crawler-results', ['crawlLabel' => $crawlLabel])
            ->layout('components.layouts.app', ['title' => 'Crawl: '.$crawlLabel, 'maxWidth' => 'max-w-full']);
    }
}
