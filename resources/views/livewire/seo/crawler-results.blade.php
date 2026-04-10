<div x-data="{ showColumns: false }">
    <x-ui.page-header title="{{ $crawlLabel }}" subtitle="{{ __('Crawl') }} #{{ $siteCrawl->id }} — {{ $siteCrawl->created_at->format('M d, Y H:i') }} — {{ ucfirst($siteCrawl->status) }}">
        <div class="flex items-center gap-2">
            <button wire:click="exportCsv" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                {{ __('Export CSV') }}
            </button>
            <a href="{{ route('seo.crawler.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                {{ __('All Crawls') }}
            </a>
        </div>
    </x-ui.page-header>

    {{-- Summary cards --}}
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
        <x-ui.stat-card label="{{ __('Pages') }}" :value="$siteCrawl->pages_crawled ?? 0" />
        <x-ui.stat-card label="{{ __('Indexable') }}" :value="$this->overviewStats['indexable'] ?? 0" />
        <x-ui.stat-card label="{{ __('Errors') }}" :value="($this->summary['status_4xx'] ?? 0) + ($this->summary['status_5xx'] ?? 0)" />
        <x-ui.stat-card label="{{ __('Issues') }}" :value="$this->summary['pages_with_issues'] ?? 0" />
        <x-ui.stat-card label="{{ __('Avg Time') }}" :value="($this->summary['avg_response_time'] ?? 0) . 'ms'" />
    </div>

    {{-- Tabs --}}
    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-gray-200">
        @foreach(['overview' => 'Overview', 'pages' => 'Pages (' . ($siteCrawl->pages_crawled ?? 0) . ')', 'issues' => 'Issues', 'links' => 'Links', 'images' => 'Images', 'comparison' => 'Compare'] as $key => $label)
            <button wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap px-4 py-2 text-sm font-medium border-b-2 transition',
                'border-purple-600 text-purple-600' => $tab === $key,
                'border-transparent text-gray-500 hover:text-gray-700' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    {{-- ═══════════ TAB: Overview ═══════════ --}}
    @if($tab === 'overview')
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {{-- Status Codes --}}
            <x-ui.card>
                <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Status Codes') }}</h3>
                <div class="grid grid-cols-4 gap-3">
                    @foreach([
                        ['2xx', $this->summary['status_2xx'] ?? 0, 'green'],
                        ['3xx', $this->summary['status_3xx'] ?? 0, 'blue'],
                        ['4xx', $this->summary['status_4xx'] ?? 0, 'orange'],
                        ['5xx', $this->summary['status_5xx'] ?? 0, 'red'],
                    ] as [$code, $count, $color])
                        <div class="rounded-lg border border-{{ $color }}-200 bg-{{ $color }}-50 p-3 text-center">
                            <div class="text-xl font-bold text-{{ $color }}-700">{{ $count }}</div>
                            <div class="text-xs text-{{ $color }}-600">{{ $code }}</div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- Response Time Distribution --}}
            <x-ui.card>
                <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Response Time') }}</h3>
                @php $rt = $this->overviewStats['response_time'] ?? []; @endphp
                <div class="space-y-2">
                    @foreach([
                        ['< 200ms', $rt['fast'] ?? 0, 'bg-green-500'],
                        ['200–500ms', $rt['medium'] ?? 0, 'bg-blue-500'],
                        ['500ms–2s', $rt['slow'] ?? 0, 'bg-yellow-500'],
                        ['> 2s', $rt['verySlow'] ?? 0, 'bg-red-500'],
                    ] as [$label, $count, $barColor])
                        <div class="flex items-center gap-3 text-sm">
                            <span class="w-20 text-gray-600">{{ $label }}</span>
                            <div class="flex-1 rounded-full bg-gray-100 h-4">
                                @if(($siteCrawl->pages_crawled ?? 1) > 0)
                                    <div class="{{ $barColor }} h-4 rounded-full" style="width: {{ min(100, ($count / max(1, $siteCrawl->pages_crawled)) * 100) }}%"></div>
                                @endif
                            </div>
                            <span class="w-10 text-right text-gray-700 font-medium">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- Depth Distribution --}}
            <x-ui.card>
                <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Crawl Depth') }}</h3>
                @php $depths = $this->overviewStats['depths'] ?? []; @endphp
                <div class="space-y-1.5">
                    @foreach($depths as $level => $count)
                        <div class="flex items-center gap-3 text-sm">
                            <span class="w-10 text-gray-600">{{ $level }}</span>
                            <div class="flex-1 rounded-full bg-gray-100 h-3">
                                @if(($siteCrawl->pages_crawled ?? 1) > 0)
                                    <div class="bg-purple-500 h-3 rounded-full" style="width: {{ min(100, ($count / max(1, $siteCrawl->pages_crawled)) * 100) }}%"></div>
                                @endif
                            </div>
                            <span class="w-10 text-right text-gray-700">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- Key SEO Issues Summary --}}
            <x-ui.card>
                <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Issues Summary') }}</h3>
                <div class="space-y-2">
                    @foreach([
                        ['Missing Title', $this->summary['missing_titles'] ?? 0, 'red'],
                        ['Missing Meta Desc', $this->summary['missing_descriptions'] ?? 0, 'orange'],
                        ['Missing H1', $this->summary['missing_h1'] ?? 0, 'orange'],
                        ['Multiple H1', $this->summary['multiple_h1'] ?? 0, 'yellow'],
                        ['Duplicate Titles', $this->summary['duplicate_titles'] ?? 0, 'orange'],
                        ['Duplicate Descriptions', $this->summary['duplicate_descriptions'] ?? 0, 'yellow'],
                        ['Thin Content', $this->summary['thin_content'] ?? 0, 'yellow'],
                        ['Broken Links', $this->summary['broken_links'] ?? 0, 'red'],
                        ['Orphan Pages', $this->summary['orphan_pages'] ?? 0, 'yellow'],
                        ['Noindex Pages', $this->summary['noindex_pages'] ?? 0, 'gray'],
                    ] as [$label, $count, $color])
                        @if($count > 0)
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-700">{{ $label }}</span>
                                <span class="rounded-full bg-{{ $color }}-100 px-2.5 py-0.5 text-xs font-semibold text-{{ $color }}-800">{{ $count }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- ═══════════ TAB: Pages ═══════════ --}}
    @if($tab === 'pages')
        {{-- Quick filters --}}
        <div class="mb-3 flex flex-wrap items-center gap-1.5">
            @foreach(\App\Livewire\Seo\CrawlerResults::QUICK_FILTERS as $key => $label)
                <button wire:click="setQuickFilter('{{ $key }}')" @class([
                    'rounded-full px-3 py-1 text-xs font-medium transition',
                    'bg-purple-600 text-white' => $quickFilter === $key,
                    'bg-gray-100 text-gray-600 hover:bg-gray-200' => $quickFilter !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>

        {{-- Search + Column toggle --}}
        <div class="mb-3 flex items-center gap-3">
            <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search URLs...') }}" class="w-72" />
            <div class="relative ml-auto">
                <button @click="showColumns = !showColumns" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">
                    <x-dynamic-component component="icons.sliders" class="h-3.5 w-3.5" />
                    {{ __('Columns') }}
                </button>
                <div x-show="showColumns" @click.outside="showColumns = false" x-cloak class="absolute right-0 z-20 mt-1 w-56 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                    <div class="max-h-64 space-y-1.5 overflow-y-auto">
                        @foreach(\App\Livewire\Seo\CrawlerResults::COLUMNS as $col => $label)
                            <label class="flex items-center gap-2 text-xs">
                                <input type="checkbox" wire:click="toggleColumn('{{ $col }}')" {{ in_array($col, $visibleColumns) ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Pages table --}}
        <x-ui.card class="!p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-xs">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach(\App\Livewire\Seo\CrawlerResults::COLUMNS as $col => $label)
                                @if(in_array($col, $visibleColumns))
                                    <th wire:click="setSort('{{ $col }}')" class="cursor-pointer whitespace-nowrap px-3 py-2 text-left font-medium text-gray-500 hover:text-gray-700">
                                        {{ $label }}
                                        @if($sortBy === $col)
                                            <span class="text-purple-600">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                                        @endif
                                    </th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($this->pages as $page)
                            <tr wire:click="selectPage({{ $page->id }})" class="cursor-pointer hover:bg-purple-50/50 {{ $selectedPageId === $page->id ? 'bg-purple-50' : '' }}">
                                @if(in_array('url', $visibleColumns))
                                    <td class="max-w-[250px] truncate px-3 py-1.5 text-gray-900" title="{{ $page->url }}">{{ \Illuminate\Support\Str::limit($page->url, 60) }}</td>
                                @endif
                                @if(in_array('status_code', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center">
                                        <span @class([
                                            'inline-flex rounded px-1.5 py-0.5 text-xs font-semibold',
                                            'bg-green-100 text-green-800' => $page->status_code >= 200 && $page->status_code < 300,
                                            'bg-blue-100 text-blue-800' => $page->status_code >= 300 && $page->status_code < 400,
                                            'bg-red-100 text-red-800' => $page->status_code >= 400 || $page->status_code === 0,
                                        ])>{{ $page->status_code ?: 'ERR' }}</span>
                                    </td>
                                @endif
                                @if(in_array('title', $visibleColumns))
                                    <td class="max-w-[200px] truncate px-3 py-1.5 text-gray-600">{{ \Illuminate\Support\Str::limit($page->title, 40) }}</td>
                                @endif
                                @if(in_array('title_length', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center {{ $page->title_length > 60 ? 'text-red-600 font-semibold' : ($page->title_length < 30 && $page->title_length > 0 ? 'text-orange-600' : 'text-gray-500') }}">{{ $page->title_length }}</td>
                                @endif
                                @if(in_array('meta_description', $visibleColumns))
                                    <td class="max-w-[200px] truncate px-3 py-1.5 text-gray-500">{{ \Illuminate\Support\Str::limit($page->meta_description, 40) }}</td>
                                @endif
                                @if(in_array('meta_desc_length', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center {{ $page->meta_desc_length > 160 ? 'text-red-600 font-semibold' : ($page->meta_desc_length < 80 && $page->meta_desc_length > 0 ? 'text-orange-600' : 'text-gray-500') }}">{{ $page->meta_desc_length }}</td>
                                @endif
                                @if(in_array('h1_count', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center {{ $page->h1_count > 1 ? 'text-red-600 font-semibold' : ($page->h1_count === 0 ? 'text-orange-600' : 'text-gray-500') }}">{{ $page->h1_count }}</td>
                                @endif
                                @if(in_array('h2_count', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $page->h2_count }}</td>
                                @endif
                                @if(in_array('h3_count', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $page->h3_count }}</td>
                                @endif
                                @if(in_array('word_count', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center {{ $page->word_count < 300 ? 'text-orange-600' : 'text-gray-500' }}">{{ $page->word_count }}</td>
                                @endif
                                @if(in_array('canonical_url', $visibleColumns))
                                    <td class="max-w-[150px] truncate px-3 py-1.5 text-gray-400">{{ $page->canonical_url ? '✓' : '—' }}</td>
                                @endif
                                @if(in_array('canonical_self_ref', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center">{{ $page->canonical_self_ref ? '✓' : '—' }}</td>
                                @endif
                                @if(in_array('meta_robots', $visibleColumns))
                                    <td class="px-3 py-1.5 text-gray-500">{{ \Illuminate\Support\Str::limit($page->meta_robots, 20) }}</td>
                                @endif
                                @if(in_array('response_time_ms', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center {{ ($page->response_time_ms ?? 0) > 2000 ? 'text-red-600 font-semibold' : 'text-gray-500' }}">{{ $page->response_time_ms }}</td>
                                @endif
                                @if(in_array('content_length', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $page->content_length ? number_format($page->content_length / 1024, 0) . 'KB' : '—' }}</td>
                                @endif
                                @if(in_array('internal_links_count', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $page->internal_links_count }}</td>
                                @endif
                                @if(in_array('external_links_count', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $page->external_links_count }}</td>
                                @endif
                                @if(in_array('images_count', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $page->images_count }}</td>
                                @endif
                                @if(in_array('images_without_alt', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center {{ $page->images_without_alt > 0 ? 'text-orange-600' : 'text-gray-400' }}">{{ $page->images_without_alt }}</td>
                                @endif
                                @if(in_array('depth', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $page->depth }}</td>
                                @endif
                                @if(in_array('readability_score', $visibleColumns))
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $page->readability_score ? number_format($page->readability_score, 1) : '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-200 px-4 py-3">
                {{ $this->pages->links() }}
            </div>
        </x-ui.card>

        {{-- Page Detail Drawer --}}
        @if($this->selectedPage)
            @php $sp = $this->selectedPage; @endphp
            <div class="fixed inset-y-0 right-0 z-30 w-full max-w-lg overflow-y-auto border-l border-gray-200 bg-white shadow-xl sm:w-[480px]">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-5 py-3">
                    <h3 class="text-sm font-semibold text-gray-900 truncate">{{ \Illuminate\Support\Str::limit($sp->url, 50) }}</h3>
                    <button wire:click="selectPage(null)" class="rounded p-1 text-gray-400 hover:text-gray-600">
                        <x-dynamic-component component="icons.x" class="h-5 w-5" />
                    </button>
                </div>
                <div class="divide-y divide-gray-100 px-5 py-4 text-sm">
                    {{-- Status + Core --}}
                    <div class="pb-4 grid grid-cols-3 gap-3">
                        <div><span class="text-gray-500 text-xs">Status</span><div class="font-semibold">{{ $sp->status_code ?: 'ERR' }}</div></div>
                        <div><span class="text-gray-500 text-xs">Time</span><div>{{ $sp->response_time_ms }}ms</div></div>
                        <div><span class="text-gray-500 text-xs">Depth</span><div>{{ $sp->depth }}</div></div>
                    </div>
                    {{-- Title --}}
                    <div class="py-3">
                        <span class="text-gray-500 text-xs">Title ({{ $sp->title_length }} chars)</span>
                        <div class="text-gray-900">{{ $sp->title ?? '—' }}</div>
                    </div>
                    {{-- Meta Description --}}
                    <div class="py-3">
                        <span class="text-gray-500 text-xs">Meta Description ({{ $sp->meta_desc_length }} chars)</span>
                        <div class="text-gray-700">{{ $sp->meta_description ?? '—' }}</div>
                    </div>
                    {{-- H1 --}}
                    <div class="py-3">
                        <span class="text-gray-500 text-xs">H1 Tags ({{ $sp->h1_count }})</span>
                        @foreach($sp->h1_tags ?? [] as $h1)
                            <div class="text-gray-700">{{ $h1 }}</div>
                        @endforeach
                    </div>
                    {{-- Content --}}
                    <div class="py-3 grid grid-cols-3 gap-3">
                        <div><span class="text-gray-500 text-xs">Words</span><div>{{ $sp->word_count }}</div></div>
                        <div><span class="text-gray-500 text-xs">H2s</span><div>{{ $sp->h2_count }}</div></div>
                        <div><span class="text-gray-500 text-xs">Readability</span><div>{{ $sp->readability_score ? number_format($sp->readability_score, 1) : '—' }}</div></div>
                    </div>
                    {{-- Technical --}}
                    <div class="py-3 space-y-1.5">
                        <div><span class="text-gray-500 text-xs">Canonical</span> <span class="text-gray-700">{{ $sp->canonical_url ?? '—' }}</span> @if($sp->canonical_self_ref) <span class="text-green-600 text-xs">(self)</span> @endif</div>
                        <div><span class="text-gray-500 text-xs">Meta Robots</span> <span class="text-gray-700">{{ $sp->meta_robots ?? '—' }}</span></div>
                        <div><span class="text-gray-500 text-xs">OG Title</span> <span class="text-gray-600">{{ \Illuminate\Support\Str::limit($sp->og_title, 50) ?? '—' }}</span></div>
                    </div>
                    {{-- Links --}}
                    <div class="py-3">
                        <span class="text-gray-500 text-xs">Internal Links: {{ $sp->internal_links_count }} · External: {{ $sp->external_links_count }}</span>
                    </div>
                    {{-- Inlinks --}}
                    @if(!empty($this->selectedPageInlinks))
                        <div class="py-3">
                            <span class="text-gray-500 text-xs">{{ __('Inlinks') }} ({{ count($this->selectedPageInlinks) }} pages link here)</span>
                            <ul class="mt-1 max-h-32 overflow-y-auto space-y-0.5">
                                @foreach($this->selectedPageInlinks as $inlink)
                                    <li class="truncate text-xs text-purple-600">{{ $inlink }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    {{-- Images --}}
                    @if($sp->images_count > 0)
                        <div class="py-3">
                            <span class="text-gray-500 text-xs">Images: {{ $sp->images_count }} ({{ $sp->images_without_alt }} missing alt)</span>
                            @if(!empty($sp->images))
                                <ul class="mt-1 max-h-32 overflow-y-auto space-y-0.5">
                                    @foreach(array_slice($sp->images, 0, 20) as $img)
                                        <li class="flex items-center gap-2 text-xs">
                                            <span class="truncate text-gray-600 flex-1">{{ \Illuminate\Support\Str::limit($img['url'] ?? '', 50) }}</span>
                                            @if(empty($img['alt']))
                                                <span class="text-orange-500">no alt</span>
                                            @else
                                                <span class="text-green-500">✓</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                    {{-- Issues --}}
                    @if(!empty($sp->issues))
                        <div class="py-3">
                            <span class="text-gray-500 text-xs">Issues ({{ count($sp->issues) }})</span>
                            <div class="mt-1 space-y-1">
                                @foreach($sp->issues as $issue)
                                    <div class="flex items-start gap-2 text-xs">
                                        <span @class([
                                            'mt-0.5 inline-block h-2 w-2 rounded-full shrink-0',
                                            'bg-red-500' => in_array($issue['severity'] ?? '', ['critical', 'high']),
                                            'bg-yellow-500' => ($issue['severity'] ?? '') === 'medium',
                                            'bg-blue-400' => in_array($issue['severity'] ?? '', ['low', 'info']),
                                        ])></span>
                                        <span class="text-gray-700">{{ $issue['message'] ?? '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endif

    {{-- ═══════════ TAB: Issues ═══════════ --}}
    @if($tab === 'issues')
        @php $grouped = $this->issuesGrouped; @endphp
        @if(empty($grouped))
            <x-ui.card>
                <x-ui.empty-state title="{{ __('No issues found') }}" description="{{ __('This crawl detected no SEO issues.') }}" icon="check-circle" />
            </x-ui.card>
        @else
            <div class="space-y-4">
                @foreach($grouped as $severity => $types)
                    <x-ui.card>
                        <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                            <span @class([
                                'inline-block h-3 w-3 rounded-full',
                                'bg-red-500' => in_array($severity, ['critical', 'high']),
                                'bg-yellow-500' => $severity === 'medium',
                                'bg-blue-400' => in_array($severity, ['low', 'info']),
                            ])></span>
                            {{ ucfirst($severity) }}
                            <span class="text-gray-400 font-normal">({{ collect($types)->flatten(1)->count() }} {{ __('occurrences') }})</span>
                        </h3>
                        <div class="space-y-3" x-data="{ open: null }">
                            @foreach($types as $type => $pages)
                                <div class="rounded-lg border border-gray-200">
                                    <button @click="open = open === '{{ $type }}' ? null : '{{ $type }}'" class="flex w-full items-center justify-between px-4 py-2.5 text-left text-sm hover:bg-gray-50">
                                        <span class="font-medium text-gray-700">{{ str_replace('_', ' ', ucfirst($type)) }}</span>
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">{{ count($pages) }} {{ __('pages') }}</span>
                                    </button>
                                    <div x-show="open === '{{ $type }}'" x-cloak class="border-t border-gray-100 px-4 py-2">
                                        <div class="max-h-48 overflow-y-auto space-y-1">
                                            @foreach(array_slice($pages, 0, 50) as $entry)
                                                <div class="flex items-center justify-between text-xs">
                                                    <a href="{{ $entry['url'] }}" target="_blank" class="truncate text-purple-600 hover:text-purple-800 max-w-md">{{ $entry['url'] }}</a>
                                                </div>
                                            @endforeach
                                            @if(count($pages) > 50)
                                                <div class="text-xs text-gray-400">...{{ __('and') }} {{ count($pages) - 50 }} {{ __('more') }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ═══════════ TAB: Links ═══════════ --}}
    @if($tab === 'links')
        <x-ui.card class="!p-0 overflow-hidden">
            @if(empty($this->linksData))
                <x-ui.empty-state title="{{ __('No link data') }}" icon="link" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-xs">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">Source</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">Destination</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">Anchor</th>
                                <th class="px-3 py-2 text-center font-medium text-gray-500">Type</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach(array_slice($this->linksData, 0, 200) as $link)
                                <tr class="hover:bg-gray-50">
                                    <td class="max-w-[200px] truncate px-3 py-1.5 text-gray-600">{{ $link['source'] ?? '' }}</td>
                                    <td class="max-w-[200px] truncate px-3 py-1.5 text-gray-900">{{ $link['url'] ?? '' }}</td>
                                    <td class="max-w-[150px] truncate px-3 py-1.5 text-gray-500">{{ $link['anchor'] ?? '' }}</td>
                                    <td class="px-3 py-1.5 text-center">
                                        <span @class([
                                            'rounded px-1.5 py-0.5 text-xs font-medium',
                                            'bg-purple-100 text-purple-700' => ($link['type'] ?? '') === 'internal',
                                            'bg-gray-100 text-gray-600' => ($link['type'] ?? '') === 'external',
                                        ])>{{ $link['type'] ?? '' }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- ═══════════ TAB: Images ═══════════ --}}
    @if($tab === 'images')
        <x-ui.card class="!p-0 overflow-hidden">
            @if(empty($this->imagesData))
                <x-ui.empty-state title="{{ __('No image data') }}" icon="image" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-xs">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">Page</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">Image URL</th>
                                <th class="px-3 py-2 text-left font-medium text-gray-500">Alt Text</th>
                                <th class="px-3 py-2 text-center font-medium text-gray-500">W</th>
                                <th class="px-3 py-2 text-center font-medium text-gray-500">H</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($this->imagesData as $img)
                                <tr class="hover:bg-gray-50">
                                    <td class="max-w-[180px] truncate px-3 py-1.5 text-gray-600">{{ $img['page_url'] }}</td>
                                    <td class="max-w-[200px] truncate px-3 py-1.5 text-gray-900">{{ $img['url'] }}</td>
                                    <td class="max-w-[150px] truncate px-3 py-1.5 {{ empty($img['alt']) ? 'text-orange-500 font-medium' : 'text-gray-500' }}">{{ $img['alt'] ?: '(missing)' }}</td>
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $img['width'] ?? '—' }}</td>
                                    <td class="px-3 py-1.5 text-center text-gray-500">{{ $img['height'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- ═══════════ TAB: Comparison ═══════════ --}}
    @if($tab === 'comparison')
        <x-ui.card>
            @if($this->previousCrawls->isEmpty())
                <p class="text-sm text-gray-500">{{ __('No previous crawls to compare with.') }}</p>
            @else
                <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Compare with a previous crawl') }}</h3>
                <div class="space-y-2">
                    @foreach($this->previousCrawls as $prev)
                        <a href="{{ route('seo.crawler.compare', [$siteCrawl, $prev]) }}" wire:navigate class="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50 transition">
                            <span class="text-gray-700">{{ $prev->created_at->format('M d, Y H:i') }}</span>
                            <span class="text-gray-500">{{ $prev->pages_crawled }} {{ __('pages') }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
