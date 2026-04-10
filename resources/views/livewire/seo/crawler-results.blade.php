<div class="flex h-[calc(100vh-4rem)] flex-col overflow-hidden">
    {{-- Top bar --}}
    <div class="flex shrink-0 items-center justify-between border-b border-gray-200 bg-white px-4 py-2">
        <div class="flex items-center gap-3">
            <a href="{{ route('seo.crawler.index') }}" wire:navigate class="text-gray-400 hover:text-gray-600">
                <x-dynamic-component component="icons.arrow-left" class="h-4 w-4" />
            </a>
            <div>
                <h1 class="text-sm font-semibold text-gray-900">{{ $crawlLabel }}</h1>
                <p class="text-xs text-gray-500">
                    {{ $siteCrawl->pages_crawled ?? 0 }} {{ __('pages') }} &middot;
                    {{ ucfirst($siteCrawl->status) }} &middot;
                    {{ $siteCrawl->created_at->format('M d, H:i') }}
                    @if($siteCrawl->duration_seconds) &middot; {{ gmdate('H:i:s', $siteCrawl->duration_seconds) }} @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Filter URLs...') }}" class="w-56 text-xs" />
            <button wire:click="exportCsv" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">
                {{ __('Export CSV') }}
            </button>
        </div>
    </div>

    {{-- Main content: table (left) + analysis panel (right) --}}
    <div class="flex flex-1 overflow-hidden">
        {{-- Table area --}}
        <div class="flex-1 overflow-auto">
            @php
                $data = $this->tableData;
                $columns = $this->tableColumns;
                $isArray = is_array($data);
                $labels = \App\Livewire\Seo\CrawlerResults::COLUMN_LABELS;
            @endphp

            <table class="min-w-full divide-y divide-gray-200 text-xs">
                <thead class="sticky top-0 z-10 bg-gray-50">
                    <tr>
                        @foreach($columns as $col)
                            <th
                                @if(!$isArray) wire:click="setSort('{{ $col }}')" @endif
                                class="whitespace-nowrap px-3 py-2 text-left font-medium text-gray-500 {{ !$isArray ? 'cursor-pointer hover:text-gray-700' : '' }}"
                            >
                                {{ $labels[$col] ?? ucfirst(str_replace('_', ' ', $col)) }}
                                @if(!$isArray && $sortBy === $col)
                                    <span class="text-purple-600">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @php $rows = $isArray ? $data : $data->items(); @endphp
                    @forelse($rows as $row)
                        <tr
                            @if(!$isArray) wire:click="selectPage({{ $row->id }})" @endif
                            class="hover:bg-purple-50/40 cursor-pointer {{ !$isArray && $selectedPageId === ($row->id ?? null) ? 'bg-purple-50' : '' }}"
                        >
                            @foreach($columns as $col)
                                <td class="max-w-[250px] truncate whitespace-nowrap px-3 py-1.5">
                                    @php
                                        $val = is_array($row) ? ($row[$col] ?? '') : ($row->{$col} ?? '');
                                    @endphp

                                    @if($col === 'status_code' && is_numeric($val))
                                        <span @class([
                                            'inline-flex rounded px-1.5 py-0.5 font-semibold',
                                            'bg-green-100 text-green-800' => $val >= 200 && $val < 300,
                                            'bg-blue-100 text-blue-800' => $val >= 300 && $val < 400,
                                            'bg-red-100 text-red-800' => $val >= 400 || $val === 0,
                                        ])>{{ $val ?: 'ERR' }}</span>
                                    @elseif($col === 'url' || $col === 'page_url' || $col === 'source')
                                        <span class="text-gray-700" title="{{ $val }}">{{ \Illuminate\Support\Str::limit($val, 55) }}</span>
                                    @elseif($col === 'title' || $col === 'meta_description' || $col === 'anchor' || $col === 'alt')
                                        <span class="text-gray-600">{{ \Illuminate\Support\Str::limit(is_string($val) ? $val : '', 45) }}</span>
                                    @elseif($col === 'h1_tags' && is_array($val))
                                        <span class="text-gray-600">{{ \Illuminate\Support\Str::limit(implode(', ', $val), 45) }}</span>
                                    @elseif($col === 'hreflang' && is_array($val))
                                        <span class="text-gray-500">{{ count($val) }} {{ __('langs') }}</span>
                                    @elseif($col === 'structured_data_types' && is_array($val))
                                        <span class="text-gray-500">{{ implode(', ', array_slice($val, 0, 3)) }}</span>
                                    @elseif($col === 'title_length')
                                        <span class="{{ $val > 60 ? 'text-red-600 font-semibold' : ($val > 0 && $val < 30 ? 'text-orange-500' : 'text-gray-500') }}">{{ $val }}</span>
                                    @elseif($col === 'meta_desc_length')
                                        <span class="{{ $val > 160 ? 'text-red-600 font-semibold' : ($val > 0 && $val < 80 ? 'text-orange-500' : 'text-gray-500') }}">{{ $val }}</span>
                                    @elseif($col === 'response_time_ms')
                                        <span class="{{ ($val ?? 0) > 2000 ? 'text-red-600 font-semibold' : 'text-gray-500' }}">{{ $val }}</span>
                                    @elseif($col === 'h1_count')
                                        <span class="{{ $val > 1 ? 'text-red-600 font-semibold' : ($val === 0 ? 'text-orange-500' : 'text-gray-500') }}">{{ $val }}</span>
                                    @elseif($col === 'is_https' || $col === 'canonical_self_ref')
                                        <span class="{{ $val ? 'text-green-600' : 'text-gray-400' }}">{{ $val ? '✓' : '✗' }}</span>
                                    @elseif($col === 'has_mixed_content')
                                        <span class="{{ $val ? 'text-red-600 font-semibold' : 'text-gray-400' }}">{{ $val ? 'Yes' : '—' }}</span>
                                    @elseif($col === 'nofollow')
                                        @if($val) <span class="text-orange-500">nf</span> @else <span class="text-gray-400">—</span> @endif
                                    @elseif($col === 'type')
                                        <span @class([
                                            'rounded px-1 py-0.5 font-medium',
                                            'bg-purple-100 text-purple-700' => $val === 'internal',
                                            'bg-gray-100 text-gray-600' => $val === 'external',
                                        ])>{{ $val }}</span>
                                    @elseif($col === 'word_count')
                                        <span class="{{ $val < 300 ? 'text-orange-500' : 'text-gray-500' }}">{{ $val }}</span>
                                    @elseif($col === 'readability_score')
                                        {{ $val ? number_format((float) $val, 1) : '—' }}
                                    @else
                                        <span class="text-gray-500">{{ is_scalar($val) ? $val : '—' }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($columns) }}" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('No data for this tab.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>

            @if(!$isArray && method_exists($data, 'links'))
                <div class="sticky bottom-0 border-t border-gray-200 bg-white px-4 py-2">
                    {{ $data->links() }}
                </div>
            @endif
        </div>

        {{-- Right analysis panel --}}
        <div class="hidden w-80 shrink-0 overflow-y-auto border-l border-gray-200 bg-gray-50/50 lg:block">
            {{-- Analysis tab selector --}}
            <div class="sticky top-0 z-10 flex gap-0.5 overflow-x-auto border-b border-gray-200 bg-white p-1">
                @foreach(\App\Livewire\Seo\CrawlerResults::ANALYSIS_TABS as $key => $label)
                    <button wire:click="setAnalysisTab('{{ $key }}')" @class([
                        'whitespace-nowrap rounded px-2 py-1 text-xs font-medium transition',
                        'bg-purple-600 text-white' => $analysisTab === $key,
                        'text-gray-600 hover:bg-gray-100' => $analysisTab !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="p-3">
                {{-- OVERVIEW --}}
                @if($analysisTab === 'overview')
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-2">
                            <div class="rounded-lg bg-white p-2.5 text-center shadow-sm">
                                <div class="text-lg font-bold text-gray-900">{{ $this->overviewStats['total'] }}</div>
                                <div class="text-xs text-gray-500">URLs</div>
                            </div>
                            <div class="rounded-lg bg-white p-2.5 text-center shadow-sm">
                                <div class="text-lg font-bold text-green-600">{{ $this->overviewStats['indexable'] }}</div>
                                <div class="text-xs text-gray-500">Indexable</div>
                            </div>
                        </div>

                        <div class="rounded-lg bg-white p-3 shadow-sm">
                            <h4 class="mb-2 text-xs font-semibold text-gray-700">{{ __('Status Codes') }}</h4>
                            @foreach([
                                ['2xx', $this->summary['status_2xx'] ?? 0, 'bg-green-500'],
                                ['3xx', $this->summary['status_3xx'] ?? 0, 'bg-blue-500'],
                                ['4xx', $this->summary['status_4xx'] ?? 0, 'bg-orange-500'],
                                ['5xx', $this->summary['status_5xx'] ?? 0, 'bg-red-500'],
                            ] as [$code, $count, $color])
                                <div class="mb-1 flex items-center gap-2 text-xs">
                                    <span class="w-6 text-gray-500">{{ $code }}</span>
                                    <div class="h-2 flex-1 rounded-full bg-gray-100">
                                        @if(($siteCrawl->pages_crawled ?? 1) > 0)
                                            <div class="{{ $color }} h-2 rounded-full" style="width: {{ min(100, ($count / max(1, $siteCrawl->pages_crawled)) * 100) }}%"></div>
                                        @endif
                                    </div>
                                    <span class="w-8 text-right font-medium text-gray-700">{{ $count }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="rounded-lg bg-white p-3 shadow-sm">
                            <h4 class="mb-2 text-xs font-semibold text-gray-700">{{ __('Key Metrics') }}</h4>
                            <div class="space-y-1 text-xs">
                                @foreach([
                                    ['Broken Links', $this->summary['broken_links'] ?? 0],
                                    ['Missing Titles', $this->summary['missing_titles'] ?? 0],
                                    ['Missing H1', $this->summary['missing_h1'] ?? 0],
                                    ['Duplicate Titles', $this->summary['duplicate_titles'] ?? 0],
                                    ['Thin Content', $this->summary['thin_content'] ?? 0],
                                    ['Avg Response', ($this->summary['avg_response_time'] ?? 0) . 'ms'],
                                ] as [$label, $val])
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">{{ $label }}</span>
                                        <span class="font-medium {{ is_numeric($val) && $val > 0 ? 'text-orange-600' : 'text-gray-700' }}">{{ $val }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                {{-- ISSUES --}}
                @if($analysisTab === 'issues')
                    @php $grouped = $this->issuesGrouped; @endphp
                    @if(empty($grouped))
                        <p class="py-4 text-center text-xs text-gray-400">{{ __('No issues detected.') }}</p>
                    @else
                        <div class="space-y-3" x-data="{ openIssue: null }">
                            @foreach($grouped as $severity => $types)
                                <div>
                                    <h4 class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold">
                                        <span @class([
                                            'h-2 w-2 rounded-full',
                                            'bg-red-500' => in_array($severity, ['critical', 'high']),
                                            'bg-yellow-500' => $severity === 'medium',
                                            'bg-blue-400' => in_array($severity, ['low', 'info']),
                                        ])></span>
                                        {{ ucfirst($severity) }}
                                    </h4>
                                    @foreach($types as $type => $pages)
                                        <div class="mb-1 rounded border border-gray-200 bg-white">
                                            <button @click="openIssue = openIssue === '{{ $severity }}-{{ $type }}' ? null : '{{ $severity }}-{{ $type }}'" class="flex w-full items-center justify-between px-2.5 py-1.5 text-left text-xs hover:bg-gray-50">
                                                <span class="text-gray-700">{{ str_replace('_', ' ', ucfirst($type)) }}</span>
                                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold text-gray-600">{{ count($pages) }}</span>
                                            </button>
                                            <div x-show="openIssue === '{{ $severity }}-{{ $type }}'" x-cloak class="max-h-32 overflow-y-auto border-t border-gray-100 px-2.5 py-1.5">
                                                @foreach(array_slice($pages, 0, 20) as $entry)
                                                    <div class="truncate text-[10px] text-purple-600">{{ $entry['url'] }}</div>
                                                @endforeach
                                                @if(count($pages) > 20)
                                                    <div class="text-[10px] text-gray-400">+{{ count($pages) - 20 }} more</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif

                {{-- SITE STRUCTURE --}}
                @if($analysisTab === 'structure')
                    <div class="rounded-lg bg-white p-3 shadow-sm">
                        <h4 class="mb-2 text-xs font-semibold text-gray-700">{{ __('Depth Distribution') }}</h4>
                        @foreach($this->siteStructure as $depth => $count)
                            <div class="mb-1.5 flex items-center gap-2 text-xs">
                                <span class="w-8 text-gray-500">{{ $depth }}</span>
                                <div class="h-3 flex-1 rounded-full bg-gray-100">
                                    <div class="bg-purple-500 h-3 rounded-full" style="width: {{ min(100, ($count / max(1, $siteCrawl->pages_crawled ?? 1)) * 100) }}%"></div>
                                </div>
                                <span class="w-10 text-right font-medium text-gray-700">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- RESPONSE TIMES --}}
                @if($analysisTab === 'response_times')
                    @php $rt = $this->responseTimeStats; @endphp
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-2">
                            <div class="rounded-lg bg-white p-2.5 text-center shadow-sm">
                                <div class="text-lg font-bold text-gray-900">{{ $rt['avg'] }}ms</div>
                                <div class="text-xs text-gray-500">Average</div>
                            </div>
                            <div class="rounded-lg bg-white p-2.5 text-center shadow-sm">
                                <div class="text-lg font-bold text-gray-900">{{ $rt['p90'] }}ms</div>
                                <div class="text-xs text-gray-500">P90</div>
                            </div>
                        </div>

                        <div class="rounded-lg bg-white p-3 shadow-sm">
                            <h4 class="mb-2 text-xs font-semibold text-gray-700">{{ __('Distribution') }}</h4>
                            @foreach([
                                ['< 200ms', $rt['fast'], 'bg-green-500'],
                                ['200-500ms', $rt['medium'], 'bg-blue-500'],
                                ['500ms-2s', $rt['slow'], 'bg-yellow-500'],
                                ['> 2s', $rt['very_slow'], 'bg-red-500'],
                            ] as [$label, $count, $color])
                                <div class="mb-1 flex items-center gap-2 text-xs">
                                    <span class="w-16 text-gray-500">{{ $label }}</span>
                                    <div class="h-2 flex-1 rounded-full bg-gray-100">
                                        <div class="{{ $color }} h-2 rounded-full" style="width: {{ min(100, ($count / max(1, $siteCrawl->pages_crawled ?? 1)) * 100) }}%"></div>
                                    </div>
                                    <span class="w-6 text-right text-gray-700">{{ $count }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="rounded-lg bg-white p-3 shadow-sm">
                            <h4 class="mb-2 text-xs font-semibold text-gray-700">{{ __('Slowest Pages') }}</h4>
                            <div class="space-y-1">
                                @foreach($rt['slowest'] ?? [] as $page)
                                    <div class="flex items-center justify-between text-[10px]">
                                        <span class="truncate text-gray-600 mr-2">{{ \Illuminate\Support\Str::limit($page->url ?? ($page['url'] ?? ''), 35) }}</span>
                                        <span class="shrink-0 font-semibold text-red-600">{{ $page->response_time_ms ?? ($page['response_time_ms'] ?? 0) }}ms</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif

                {{-- SEGMENTS --}}
                @if($analysisTab === 'segments')
                    <div class="rounded-lg bg-white p-3 shadow-sm">
                        <h4 class="mb-2 text-xs font-semibold text-gray-700">{{ __('URL Segments') }}</h4>
                        @forelse($this->segments as $label => $count)
                            <div class="mb-1.5 flex items-center justify-between text-xs">
                                <span class="text-gray-600">{{ $label }}</span>
                                <span class="rounded bg-purple-100 px-1.5 py-0.5 font-semibold text-purple-700">{{ $count }}</span>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400">{{ __('No segments detected.') }}</p>
                        @endforelse
                    </div>
                @endif
            </div>

            {{-- Page detail (shown below analysis when a page is selected) --}}
            @if($this->selectedPage)
                @php $sp = $this->selectedPage; @endphp
                <div class="border-t border-gray-200 bg-white p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <h4 class="text-xs font-semibold text-gray-900">{{ __('Page Detail') }}</h4>
                        <button wire:click="selectPage(null)" class="text-gray-400 hover:text-gray-600">
                            <x-dynamic-component component="icons.x" class="h-3.5 w-3.5" />
                        </button>
                    </div>
                    <div class="space-y-2 text-[11px]">
                        <div class="truncate text-purple-600" title="{{ $sp->url }}">{{ $sp->url }}</div>
                        <div class="grid grid-cols-3 gap-1.5">
                            <div><span class="text-gray-400">Status</span> <strong>{{ $sp->status_code }}</strong></div>
                            <div><span class="text-gray-400">Time</span> {{ $sp->response_time_ms }}ms</div>
                            <div><span class="text-gray-400">Depth</span> {{ $sp->depth }}</div>
                        </div>
                        <div><span class="text-gray-400">Title</span> <span class="text-gray-700">({{ $sp->title_length }}) {{ \Illuminate\Support\Str::limit($sp->title, 60) }}</span></div>
                        <div><span class="text-gray-400">Meta</span> <span class="text-gray-600">({{ $sp->meta_desc_length }}) {{ \Illuminate\Support\Str::limit($sp->meta_description, 60) }}</span></div>
                        <div><span class="text-gray-400">H1</span> <span class="text-gray-700">{{ implode(', ', array_slice($sp->h1_tags ?? [], 0, 2)) ?: '—' }}</span></div>
                        <div><span class="text-gray-400">Canonical</span> <span class="text-gray-600">{{ $sp->canonical_self_ref ? 'Self' : \Illuminate\Support\Str::limit($sp->canonical_url, 40) ?? '—' }}</span></div>
                        <div><span class="text-gray-400">Robots</span> <span class="text-gray-600">{{ $sp->meta_robots ?? '—' }}</span></div>
                        <div class="grid grid-cols-3 gap-1.5">
                            <div><span class="text-gray-400">Words</span> {{ $sp->word_count }}</div>
                            <div><span class="text-gray-400">Images</span> {{ $sp->images_count }}</div>
                            <div><span class="text-gray-400">Links</span> {{ $sp->internal_links_count }}/{{ $sp->external_links_count }}</div>
                        </div>

                        @if(!empty($this->selectedPageInlinks))
                            <div>
                                <span class="text-gray-400">Inlinks ({{ count($this->selectedPageInlinks) }})</span>
                                <div class="mt-0.5 max-h-20 overflow-y-auto">
                                    @foreach(array_slice($this->selectedPageInlinks, 0, 10) as $inlink)
                                        <div class="truncate text-purple-500">{{ $inlink }}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if(!empty($sp->issues))
                            <div>
                                <span class="text-gray-400">Issues ({{ count($sp->issues) }})</span>
                                @foreach(array_slice($sp->issues, 0, 5) as $issue)
                                    <div class="flex items-start gap-1">
                                        <span @class([
                                            'mt-0.5 h-1.5 w-1.5 rounded-full shrink-0',
                                            'bg-red-500' => in_array($issue['severity'] ?? '', ['critical', 'high']),
                                            'bg-yellow-500' => ($issue['severity'] ?? '') === 'medium',
                                            'bg-blue-400' => true,
                                        ])></span>
                                        <span class="text-gray-600">{{ \Illuminate\Support\Str::limit($issue['message'] ?? '', 60) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Bottom tab bar (data tabs) --}}
    <div class="shrink-0 overflow-x-auto border-t border-gray-200 bg-white">
        <div class="flex gap-0">
            @foreach(\App\Livewire\Seo\CrawlerResults::DATA_TABS as $key => $label)
                <button wire:click="setDataTab('{{ $key }}')" @class([
                    'whitespace-nowrap border-r border-gray-200 px-3 py-2 text-xs font-medium transition',
                    'bg-purple-600 text-white' => $dataTab === $key,
                    'text-gray-600 hover:bg-gray-100' => $dataTab !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>
</div>
