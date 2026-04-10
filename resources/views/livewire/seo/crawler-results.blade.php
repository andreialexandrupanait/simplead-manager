<div>
    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="{{ route('seo.crawler.index') }}" wire:navigate class="rounded-lg border border-gray-200 p-2 text-gray-400 hover:text-gray-600 transition">
                <x-dynamic-component component="icons.arrow-left" class="h-4 w-4" />
            </a>
            <div>
                <h1 class="text-lg font-semibold text-gray-900">{{ $crawlLabel }}</h1>
                <p class="text-sm text-gray-500">
                    {{ $siteCrawl->pages_crawled ?? 0 }} {{ __('pages') }} &middot;
                    <span @class([
                        'font-medium',
                        'text-green-600' => $siteCrawl->status === 'completed',
                        'text-blue-600' => $siteCrawl->status === 'running',
                        'text-red-600' => $siteCrawl->status === 'failed',
                    ])>{{ ucfirst($siteCrawl->status) }}</span>
                    &middot; {{ $siteCrawl->created_at->format('M d, Y H:i') }}
                    @if($siteCrawl->duration_seconds) &middot; {{ gmdate('H:i:s', $siteCrawl->duration_seconds) }} @endif
                </p>
            </div>
        </div>
        <button wire:click="exportCsv" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
            {{ __('Export CSV') }}
        </button>
    </div>

    {{-- Stat cards --}}
    <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
        <x-ui.stat-card label="{{ __('Total URLs') }}" :value="$this->overviewStats['total']" />
        <x-ui.stat-card label="{{ __('Indexable') }}" :value="$this->overviewStats['indexable']" />
        <x-ui.stat-card label="{{ __('Errors') }}" :value="($this->summary['status_4xx'] ?? 0) + ($this->summary['status_5xx'] ?? 0)" />
        <x-ui.stat-card label="{{ __('With Issues') }}" :value="$this->summary['pages_with_issues'] ?? 0" />
        <x-ui.stat-card label="{{ __('Avg Time') }}" :value="($this->summary['avg_response_time'] ?? 0) . 'ms'" />
    </div>

    {{-- Tab navigation: grouped --}}
    <div class="mb-4 flex flex-wrap items-center gap-2" x-data="{ open: null }">
        {{-- SEO group --}}
        <div class="relative">
            <button @click="open = open === 'seo' ? null : 'seo'" @class([
                'inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium transition',
                'bg-purple-600 text-white' => in_array($dataTab, ['internal', 'page_titles', 'meta_desc', 'h1', 'h2', 'content']),
                'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50' => !in_array($dataTab, ['internal', 'page_titles', 'meta_desc', 'h1', 'h2', 'content']),
            ])>
                SEO
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open === 'seo'" @click.outside="open = null" x-cloak class="absolute left-0 z-20 mt-1 w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                @foreach(['internal' => 'Internal', 'page_titles' => 'Page Titles', 'meta_desc' => 'Meta Description', 'h1' => 'H1', 'h2' => 'H2', 'content' => 'Content'] as $key => $label)
                    <button wire:click="setDataTab('{{ $key }}')" @click="open = null" @class([
                        'block w-full px-4 py-2 text-left text-sm hover:bg-gray-50',
                        'bg-purple-50 text-purple-700 font-medium' => $dataTab === $key,
                        'text-gray-700' => $dataTab !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Technical group --}}
        <div class="relative">
            <button @click="open = open === 'tech' ? null : 'tech'" @class([
                'inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium transition',
                'bg-purple-600 text-white' => in_array($dataTab, ['response_codes', 'canonicals', 'directives', 'hreflang', 'structured_data', 'security', 'sitemaps']),
                'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50' => !in_array($dataTab, ['response_codes', 'canonicals', 'directives', 'hreflang', 'structured_data', 'security', 'sitemaps']),
            ])>
                Technical
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open === 'tech'" @click.outside="open = null" x-cloak class="absolute left-0 z-20 mt-1 w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                @foreach(['response_codes' => 'Response Codes', 'canonicals' => 'Canonicals', 'directives' => 'Directives', 'hreflang' => 'Hreflang', 'structured_data' => 'Structured Data', 'security' => 'Security', 'sitemaps' => 'Sitemaps'] as $key => $label)
                    <button wire:click="setDataTab('{{ $key }}')" @click="open = null" @class([
                        'block w-full px-4 py-2 text-left text-sm hover:bg-gray-50',
                        'bg-purple-50 text-purple-700 font-medium' => $dataTab === $key,
                        'text-gray-700' => $dataTab !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Resources group --}}
        <div class="relative">
            <button @click="open = open === 'res' ? null : 'res'" @class([
                'inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium transition',
                'bg-purple-600 text-white' => in_array($dataTab, ['images', 'javascript', 'css', 'links', 'external']),
                'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50' => !in_array($dataTab, ['images', 'javascript', 'css', 'links', 'external']),
            ])>
                Resources
                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open === 'res'" @click.outside="open = null" x-cloak class="absolute left-0 z-20 mt-1 w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                @foreach(['images' => 'Images', 'links' => 'Links', 'external' => 'External Links', 'javascript' => 'JavaScript', 'css' => 'CSS'] as $key => $label)
                    <button wire:click="setDataTab('{{ $key }}')" @click="open = null" @class([
                        'block w-full px-4 py-2 text-left text-sm hover:bg-gray-50',
                        'bg-purple-50 text-purple-700 font-medium' => $dataTab === $key,
                        'text-gray-700' => $dataTab !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Analysis tabs (direct, no dropdown) --}}
        <div class="ml-auto flex items-center gap-1 rounded-lg border border-gray-200 bg-gray-50 p-0.5">
            @foreach(\App\Livewire\Seo\CrawlerResults::ANALYSIS_TABS as $key => $label)
                <button wire:click="setAnalysisTab('{{ $key }}')" @class([
                    'rounded-md px-3 py-1.5 text-sm font-medium transition',
                    'bg-white text-purple-700 shadow-sm' => $analysisTab === $key,
                    'text-gray-500 hover:text-gray-700' => $analysisTab !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Current data tab label --}}
    <div class="mb-3 flex items-center gap-3">
        <h2 class="text-sm font-semibold text-gray-900">
            {{ \App\Livewire\Seo\CrawlerResults::DATA_TABS[$dataTab] ?? ucfirst($dataTab) }}
        </h2>
        @if(!in_array($dataTab, ['external', 'images', 'javascript', 'css', 'links']))
            <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Filter URLs...') }}" class="w-64" />
        @endif
    </div>

    {{-- Analysis view OR Data table --}}
    @if(in_array($analysisTab, ['overview', 'issues', 'structure', 'response_times', 'segments']) && $analysisTab !== 'overview')
        {{-- Analysis panels (full width when selected) --}}

        @if($analysisTab === 'issues')
            @php $grouped = $this->issuesGrouped; @endphp
            @if(empty($grouped))
                <x-ui.card><x-ui.empty-state title="{{ __('No issues found') }}" description="{{ __('No SEO issues detected in this crawl.') }}" icon="check-circle" /></x-ui.card>
            @else
                <div class="space-y-4">
                    @foreach($grouped as $severity => $types)
                        <x-ui.card>
                            <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                                <span @class([
                                    'h-3 w-3 rounded-full',
                                    'bg-red-500' => in_array($severity, ['critical', 'high']),
                                    'bg-yellow-500' => $severity === 'medium',
                                    'bg-blue-400' => in_array($severity, ['low', 'info']),
                                ])></span>
                                {{ ucfirst($severity) }}
                                <span class="font-normal text-gray-400">({{ collect($types)->flatten(1)->count() }})</span>
                            </h3>
                            <div class="space-y-2" x-data="{ openIssue: null }">
                                @foreach($types as $type => $pages)
                                    <div class="rounded-lg border border-gray-200">
                                        <button @click="openIssue = openIssue === '{{ $type }}' ? null : '{{ $type }}'" class="flex w-full items-center justify-between px-4 py-3 text-left text-sm hover:bg-gray-50 transition">
                                            <span class="font-medium text-gray-700">{{ str_replace('_', ' ', ucfirst($type)) }}</span>
                                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">{{ count($pages) }}</span>
                                        </button>
                                        <div x-show="openIssue === '{{ $type }}'" x-cloak class="border-t border-gray-100 px-4 py-3">
                                            <div class="max-h-60 space-y-1.5 overflow-y-auto">
                                                @foreach(array_slice($pages, 0, 50) as $entry)
                                                    <a href="{{ $entry['url'] }}" target="_blank" class="block truncate text-sm text-purple-600 hover:text-purple-800">{{ $entry['url'] }}</a>
                                                @endforeach
                                                @if(count($pages) > 50)
                                                    <p class="text-xs text-gray-400">+{{ count($pages) - 50 }} {{ __('more') }}</p>
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

        @if($analysisTab === 'structure')
            <x-ui.card>
                <h3 class="mb-4 text-sm font-semibold text-gray-900">{{ __('Site Depth Distribution') }}</h3>
                <div class="max-w-xl space-y-3">
                    @foreach($this->siteStructure as $depth => $count)
                        <div class="flex items-center gap-4">
                            <span class="w-12 text-right text-sm font-medium text-gray-600">{{ __('Depth') }} {{ $depth }}</span>
                            <div class="h-6 flex-1 rounded bg-gray-100">
                                <div class="bg-purple-500 h-6 rounded flex items-center px-2" style="width: {{ min(100, max(2, ($count / max(1, $siteCrawl->pages_crawled ?? 1)) * 100)) }}%">
                                    <span class="text-xs font-medium text-white">{{ $count }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        @endif

        @if($analysisTab === 'response_times')
            @php $rt = $this->responseTimeStats; @endphp
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <x-ui.card>
                    <h3 class="mb-4 text-sm font-semibold text-gray-900">{{ __('Response Time Distribution') }}</h3>
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div class="rounded-lg bg-gray-50 p-3 text-center">
                            <div class="text-2xl font-bold text-gray-900">{{ $rt['avg'] }}ms</div>
                            <div class="text-xs text-gray-500">{{ __('Average') }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 text-center">
                            <div class="text-2xl font-bold text-gray-900">{{ $rt['p90'] }}ms</div>
                            <div class="text-xs text-gray-500">{{ __('P90') }}</div>
                        </div>
                    </div>
                    <div class="space-y-3">
                        @foreach([
                            ['< 200ms', $rt['fast'], 'bg-green-500', 'Fast'],
                            ['200-500ms', $rt['medium'], 'bg-blue-500', 'Normal'],
                            ['500ms-2s', $rt['slow'], 'bg-yellow-500', 'Slow'],
                            ['> 2s', $rt['very_slow'], 'bg-red-500', 'Critical'],
                        ] as [$label, $count, $color, $hint])
                            <div class="flex items-center gap-3">
                                <span class="w-20 text-sm text-gray-600">{{ $label }}</span>
                                <div class="h-5 flex-1 rounded bg-gray-100">
                                    <div class="{{ $color }} h-5 rounded flex items-center px-2" style="width: {{ min(100, max(1, ($count / max(1, $siteCrawl->pages_crawled ?? 1)) * 100)) }}%">
                                        @if($count > 0) <span class="text-xs text-white font-medium">{{ $count }}</span> @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <h3 class="mb-4 text-sm font-semibold text-gray-900">{{ __('Slowest Pages') }}</h3>
                    <div class="space-y-2">
                        @foreach($rt['slowest'] ?? [] as $page)
                            @php $pUrl = $page->url ?? ($page['url'] ?? ''); $pTime = $page->response_time_ms ?? ($page['response_time_ms'] ?? 0); @endphp
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2">
                                <span class="truncate text-sm text-gray-700 mr-3">{{ \Illuminate\Support\Str::limit($pUrl, 50) }}</span>
                                <span class="shrink-0 rounded bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">{{ $pTime }}ms</span>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            </div>
        @endif

        @if($analysisTab === 'segments')
            <x-ui.card>
                <h3 class="mb-4 text-sm font-semibold text-gray-900">{{ __('URL Segments') }}</h3>
                <div class="max-w-xl space-y-3">
                    @forelse($this->segments as $label => $count)
                        <div class="flex items-center gap-4">
                            <span class="w-32 text-sm font-medium text-gray-600">{{ $label }}</span>
                            <div class="h-6 flex-1 rounded bg-gray-100">
                                <div class="bg-purple-500 h-6 rounded flex items-center px-2" style="width: {{ min(100, max(2, ($count / max(1, $siteCrawl->pages_crawled ?? 1)) * 100)) }}%">
                                    <span class="text-xs font-medium text-white">{{ $count }}</span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">{{ __('No segments detected.') }}</p>
                    @endforelse
                </div>
            </x-ui.card>
        @endif

    @else
        {{-- Data table (full width) --}}
        @php
            $data = $this->tableData;
            $columns = $this->tableColumns;
            $isArray = is_array($data);
            $labels = \App\Livewire\Seo\CrawlerResults::COLUMN_LABELS;
        @endphp

        <x-ui.card class="!p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            @foreach($columns as $col)
                                <th
                                    @if(!$isArray) wire:click="setSort('{{ $col }}')" @endif
                                    class="whitespace-nowrap px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 {{ !$isArray ? 'cursor-pointer hover:text-gray-700' : '' }}"
                                >
                                    {{ $labels[$col] ?? ucfirst(str_replace('_', ' ', $col)) }}
                                    @if(!$isArray && $sortBy === $col)
                                        <span class="text-purple-600">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @php $rows = $isArray ? $data : $data->items(); @endphp
                        @forelse($rows as $row)
                            <tr
                                @if(!$isArray) wire:click="selectPage({{ $row->id }})" @endif
                                class="hover:bg-gray-50 {{ !$isArray ? 'cursor-pointer' : '' }} {{ !$isArray && $selectedPageId === ($row->id ?? null) ? 'bg-purple-50' : '' }}"
                            >
                                @foreach($columns as $col)
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        @php $val = is_array($row) ? ($row[$col] ?? '') : ($row->{$col} ?? ''); @endphp

                                        @if($col === 'status_code' && is_numeric($val))
                                            <span @class([
                                                'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                                'bg-green-100 text-green-800' => $val >= 200 && $val < 300,
                                                'bg-blue-100 text-blue-800' => $val >= 300 && $val < 400,
                                                'bg-red-100 text-red-800' => $val >= 400 || $val === 0,
                                            ])>{{ $val ?: 'ERR' }}</span>
                                        @elseif(in_array($col, ['url', 'page_url', 'source', 'canonical_url', 'redirect_url']))
                                            <span class="text-gray-900 max-w-xs truncate block" title="{{ $val }}">{{ \Illuminate\Support\Str::limit((string) $val, 60) }}</span>
                                        @elseif(in_array($col, ['title', 'meta_description', 'anchor', 'alt', 'og_title', 'og_description']))
                                            <span class="text-gray-600 max-w-xs truncate block">{{ \Illuminate\Support\Str::limit(is_string($val) ? $val : '', 50) }}</span>
                                        @elseif($col === 'h1_tags' && is_array($val))
                                            <span class="text-gray-600">{{ \Illuminate\Support\Str::limit(implode(' | ', $val), 50) }}</span>
                                        @elseif($col === 'hreflang' && is_array($val))
                                            <span class="text-gray-500">{{ count($val) }} {{ __('variants') }}</span>
                                        @elseif($col === 'structured_data_types' && is_array($val))
                                            <span class="text-gray-600">{{ implode(', ', array_slice($val, 0, 3)) }}</span>
                                        @elseif($col === 'title_length')
                                            <span class="{{ $val > 60 ? 'text-red-600 font-semibold' : ($val > 0 && $val < 30 ? 'text-orange-600' : 'text-gray-600') }}">{{ $val }}</span>
                                        @elseif($col === 'meta_desc_length')
                                            <span class="{{ $val > 160 ? 'text-red-600 font-semibold' : ($val > 0 && $val < 80 ? 'text-orange-600' : 'text-gray-600') }}">{{ $val }}</span>
                                        @elseif($col === 'response_time_ms')
                                            <span class="{{ ($val ?? 0) > 2000 ? 'text-red-600 font-semibold' : 'text-gray-600' }}">{{ $val }}ms</span>
                                        @elseif($col === 'h1_count')
                                            <span class="{{ $val > 1 ? 'text-red-600 font-semibold' : ($val === 0 ? 'text-orange-600' : 'text-gray-600') }}">{{ $val }}</span>
                                        @elseif($col === 'word_count')
                                            <span class="{{ $val < 300 ? 'text-orange-600' : 'text-gray-600' }}">{{ $val }}</span>
                                        @elseif(in_array($col, ['is_https', 'canonical_self_ref']))
                                            @if($val) <span class="text-green-600 font-medium">Yes</span> @else <span class="text-gray-400">No</span> @endif
                                        @elseif($col === 'has_mixed_content')
                                            @if($val) <span class="text-red-600 font-semibold">Yes</span> @else <span class="text-gray-400">—</span> @endif
                                        @elseif($col === 'nofollow')
                                            @if($val) <span class="rounded bg-orange-100 px-1.5 py-0.5 text-xs text-orange-700">nofollow</span> @else <span class="text-gray-400">—</span> @endif
                                        @elseif($col === 'type')
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                                'bg-purple-100 text-purple-700' => $val === 'internal',
                                                'bg-gray-100 text-gray-600' => $val !== 'internal',
                                            ])>{{ $val }}</span>
                                        @elseif($col === 'readability_score')
                                            <span class="text-gray-600">{{ $val ? number_format((float) $val, 1) : '—' }}</span>
                                        @elseif($col === 'content_length')
                                            <span class="text-gray-600">{{ is_numeric($val) && $val ? number_format($val / 1024, 0) . 'KB' : '—' }}</span>
                                        @else
                                            <span class="text-gray-600">{{ is_scalar($val) ? $val : '—' }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($columns) }}" class="px-4 py-12 text-center text-sm text-gray-400">{{ __('No data for this view.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if(!$isArray && method_exists($data, 'links'))
                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $data->links() }}
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- Page detail drawer (slide-over) --}}
    @if($this->selectedPage)
        @php $sp = $this->selectedPage; @endphp
        <div
            class="fixed inset-0 z-40"
            x-data="{ show: true }"
            x-show="show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:leave="transition ease-in duration-150"
        >
            {{-- Backdrop --}}
            <div class="absolute inset-0 bg-gray-900/20" wire:click="selectPage(null)"></div>

            {{-- Drawer --}}
            <div class="absolute inset-y-0 right-0 w-full max-w-lg overflow-y-auto bg-white shadow-xl sm:w-[480px]">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4">
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('Page Details') }}</h3>
                    <button wire:click="selectPage(null)" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                        <x-dynamic-component component="icons.x" class="h-5 w-5" />
                    </button>
                </div>

                <div class="divide-y divide-gray-100 px-6">
                    {{-- URL + Status --}}
                    <div class="py-4">
                        <a href="{{ $sp->url }}" target="_blank" class="text-sm font-medium text-purple-600 hover:text-purple-800 break-all">{{ $sp->url }}</a>
                        <div class="mt-2 flex items-center gap-4">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                'bg-green-100 text-green-800' => $sp->status_code >= 200 && $sp->status_code < 300,
                                'bg-blue-100 text-blue-800' => $sp->status_code >= 300 && $sp->status_code < 400,
                                'bg-red-100 text-red-800' => $sp->status_code >= 400,
                            ])>{{ $sp->status_code }}</span>
                            <span class="text-sm text-gray-500">{{ $sp->response_time_ms }}ms</span>
                            <span class="text-sm text-gray-500">{{ __('Depth') }}: {{ $sp->depth }}</span>
                            <span class="text-sm text-gray-500">{{ $sp->content_length ? number_format($sp->content_length / 1024, 1) . 'KB' : '' }}</span>
                        </div>
                    </div>

                    {{-- Title --}}
                    <div class="py-4">
                        <label class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Title') }} ({{ $sp->title_length }} {{ __('chars') }})</label>
                        <p class="mt-1 text-sm text-gray-900">{{ $sp->title ?? '—' }}</p>
                    </div>

                    {{-- Meta Description --}}
                    <div class="py-4">
                        <label class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Meta Description') }} ({{ $sp->meta_desc_length }} {{ __('chars') }})</label>
                        <p class="mt-1 text-sm text-gray-700">{{ $sp->meta_description ?? '—' }}</p>
                    </div>

                    {{-- Headings --}}
                    <div class="py-4">
                        <label class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Headings') }}</label>
                        <div class="mt-1 space-y-1">
                            @foreach($sp->h1_tags ?? [] as $h1)
                                <div class="text-sm"><span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500">H1</span> {{ $h1 }}</div>
                            @endforeach
                            <div class="text-sm text-gray-500">H2: {{ $sp->h2_count }} &middot; H3: {{ $sp->h3_count }}</div>
                        </div>
                    </div>

                    {{-- Content metrics --}}
                    <div class="py-4">
                        <label class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Content') }}</label>
                        <div class="mt-1 grid grid-cols-3 gap-4 text-sm">
                            <div><span class="text-gray-400">Words</span><div class="font-medium text-gray-900">{{ $sp->word_count }}</div></div>
                            <div><span class="text-gray-400">Readability</span><div class="font-medium text-gray-900">{{ $sp->readability_score ? number_format($sp->readability_score, 1) : '—' }}</div></div>
                            <div><span class="text-gray-400">Images</span><div class="font-medium text-gray-900">{{ $sp->images_count }} ({{ $sp->images_without_alt }} no alt)</div></div>
                        </div>
                    </div>

                    {{-- Technical --}}
                    <div class="py-4">
                        <label class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Technical') }}</label>
                        <div class="mt-1 space-y-1.5 text-sm">
                            <div><span class="text-gray-400">Canonical:</span> <span class="text-gray-700">{{ $sp->canonical_url ?? '—' }} @if($sp->canonical_self_ref) <span class="text-green-600">(self)</span> @endif</span></div>
                            <div><span class="text-gray-400">Meta Robots:</span> <span class="text-gray-700">{{ $sp->meta_robots ?? '—' }}</span></div>
                            <div><span class="text-gray-400">OG Title:</span> <span class="text-gray-600">{{ \Illuminate\Support\Str::limit($sp->og_title, 60) ?? '—' }}</span></div>
                            <div><span class="text-gray-400">Links:</span> <span class="text-gray-700">{{ $sp->internal_links_count }} {{ __('internal') }}, {{ $sp->external_links_count }} {{ __('external') }}</span></div>
                        </div>
                    </div>

                    {{-- Inlinks --}}
                    @if(!empty($this->selectedPageInlinks))
                        <div class="py-4">
                            <label class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Inlinks') }} ({{ count($this->selectedPageInlinks) }})</label>
                            <div class="mt-1 max-h-40 overflow-y-auto space-y-1">
                                @foreach($this->selectedPageInlinks as $inlink)
                                    <div class="truncate text-sm text-purple-600">{{ $inlink }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Issues --}}
                    @if(!empty($sp->issues))
                        <div class="py-4">
                            <label class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Issues') }} ({{ count($sp->issues) }})</label>
                            <div class="mt-2 space-y-2">
                                @foreach($sp->issues as $issue)
                                    <div class="flex items-start gap-2 text-sm">
                                        <span @class([
                                            'mt-1 h-2.5 w-2.5 rounded-full shrink-0',
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
        </div>
    @endif
</div>
