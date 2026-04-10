<div class="flex gap-6 overflow-hidden">
    {{-- Left sidebar navigation --}}
    <nav class="hidden w-52 shrink-0 lg:block">
        <div class="sticky top-4 space-y-5">
            {{-- Crawl info --}}
            <div>
                <a href="{{ route('seo.crawler.index') }}" wire:navigate class="mb-2 inline-flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600">
                    <x-dynamic-component component="icons.arrow-left" class="h-3 w-3" /> {{ __('All Crawls') }}
                </a>
                <h2 class="text-sm font-semibold text-gray-900 truncate" title="{{ $crawlLabel }}">{{ \Illuminate\Support\Str::limit($crawlLabel, 25) }}</h2>
                <p class="text-xs text-gray-500">{{ $siteCrawl->pages_crawled ?? 0 }} pg &middot; {{ $siteCrawl->created_at->format('M d') }}</p>
            </div>

            {{-- Analysis --}}
            <div>
                <h3 class="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400">{{ __('Analysis') }}</h3>
                @foreach(\App\Livewire\Seo\CrawlerResults::ANALYSIS_TABS as $key => $label)
                    <button wire:click="setAnalysisTab('{{ $key }}')" @class([
                        'flex w-full items-center rounded-md px-2.5 py-1.5 text-sm transition',
                        'bg-purple-50 font-medium text-purple-700' => $analysisTab === $key,
                        'text-gray-600 hover:bg-gray-50 hover:text-gray-900' => $analysisTab !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            {{-- SEO --}}
            <div>
                <h3 class="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400">SEO</h3>
                @foreach(['internal' => 'Internal', 'page_titles' => 'Page Titles', 'meta_desc' => 'Meta Description', 'h1' => 'H1', 'h2' => 'H2', 'content' => 'Content'] as $key => $label)
                    <button wire:click="setDataTab('{{ $key }}')" @class([
                        'flex w-full items-center rounded-md px-2.5 py-1.5 text-sm transition',
                        'bg-purple-50 font-medium text-purple-700' => $dataTab === $key && $analysisTab === 'overview',
                        'text-gray-600 hover:bg-gray-50 hover:text-gray-900' => $dataTab !== $key || $analysisTab !== 'overview',
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            {{-- Technical --}}
            <div>
                <h3 class="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400">{{ __('Technical') }}</h3>
                @foreach(['response_codes' => 'Response Codes', 'canonicals' => 'Canonicals', 'directives' => 'Directives', 'hreflang' => 'Hreflang', 'structured_data' => 'Structured Data', 'security' => 'Security', 'sitemaps' => 'Sitemaps'] as $key => $label)
                    <button wire:click="setDataTab('{{ $key }}')" @class([
                        'flex w-full items-center rounded-md px-2.5 py-1.5 text-sm transition',
                        'bg-purple-50 font-medium text-purple-700' => $dataTab === $key && $analysisTab === 'overview',
                        'text-gray-600 hover:bg-gray-50 hover:text-gray-900' => $dataTab !== $key || $analysisTab !== 'overview',
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            {{-- Resources --}}
            <div>
                <h3 class="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-gray-400">{{ __('Resources') }}</h3>
                @foreach(['images' => 'Images', 'links' => 'Links', 'external' => 'External Links', 'javascript' => 'JavaScript', 'css' => 'CSS'] as $key => $label)
                    <button wire:click="setDataTab('{{ $key }}')" @class([
                        'flex w-full items-center rounded-md px-2.5 py-1.5 text-sm transition',
                        'bg-purple-50 font-medium text-purple-700' => $dataTab === $key && $analysisTab === 'overview',
                        'text-gray-600 hover:bg-gray-50 hover:text-gray-900' => $dataTab !== $key || $analysisTab !== 'overview',
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            {{-- Export --}}
            <div class="pt-2 border-t border-gray-200">
                <button wire:click="exportCsv" class="flex w-full items-center rounded-md px-2.5 py-1.5 text-sm text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition">
                    {{ __('Export CSV') }}
                </button>
            </div>
        </div>
    </nav>

    {{-- Main content (full-width, no page-level scroll) --}}
    <div class="min-w-0 flex-1 overflow-hidden">
        {{-- Stat cards --}}
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
            <x-ui.stat-card label="{{ __('Total URLs') }}" :value="$this->overviewStats['total']" />
            <x-ui.stat-card label="{{ __('Indexable') }}" :value="$this->overviewStats['indexable']" />
            <x-ui.stat-card label="{{ __('Errors') }}" :value="($this->summary['status_4xx'] ?? 0) + ($this->summary['status_5xx'] ?? 0)" />
            <x-ui.stat-card label="{{ __('With Issues') }}" :value="$this->summary['pages_with_issues'] ?? 0" />
            <x-ui.stat-card label="{{ __('Avg Time') }}" :value="($this->summary['avg_response_time'] ?? 0) . 'ms'" />
        </div>

        {{-- View title + search --}}
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-900">
                @if($analysisTab !== 'overview')
                    {{ \App\Livewire\Seo\CrawlerResults::ANALYSIS_TABS[$analysisTab] ?? '' }}
                @else
                    {{ \App\Livewire\Seo\CrawlerResults::DATA_TABS[$dataTab] ?? ucfirst($dataTab) }}
                @endif
            </h2>
            @if($analysisTab === 'overview' && !in_array($dataTab, ['external', 'images', 'javascript', 'css', 'links']))
                <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Filter URLs...') }}" class="w-64" />
            @endif
        </div>

        {{-- ════════ ANALYSIS VIEWS ════════ --}}
        @if($analysisTab === 'overview' && $dataTab === 'internal')
            {{-- OVERVIEW DASHBOARD --}}
            @php $os = $this->overviewStats; @endphp
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {{-- Status codes --}}
                <x-ui.card>
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Status Code Distribution') }}</h3>
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

                {{-- Content stats --}}
                <x-ui.card>
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Content Statistics') }}</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-lg bg-gray-50 p-3 text-center">
                            <div class="text-lg font-bold text-gray-900">{{ $this->summary['avg_word_count'] ?? 0 }}</div>
                            <div class="text-xs text-gray-500">{{ __('Avg Words') }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 text-center">
                            <div class="text-lg font-bold text-gray-900">{{ $this->summary['avg_response_time'] ?? 0 }}ms</div>
                            <div class="text-xs text-gray-500">{{ __('Avg Response') }}</div>
                        </div>
                    </div>
                </x-ui.card>

                {{-- Quick issue counts --}}
                <x-ui.card class="lg:col-span-2">
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('SEO Quick Check') }}</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        @foreach([
                            ['No Title', $os['no_title'], 'page_titles'],
                            ['No Meta Desc', $os['no_desc'], 'meta_desc'],
                            ['No H1', $os['no_h1'], 'h1'],
                            ['Multiple H1', $os['multi_h1'], 'h1'],
                            ['Thin Content', $os['thin_content'], 'content'],
                            ['Slow (>2s)', $os['slow_pages'], 'response_codes'],
                        ] as [$label, $count, $tab])
                            <button wire:click="setDataTab('{{ $tab }}')" class="rounded-lg border p-3 text-center transition hover:bg-gray-50 {{ $count > 0 ? 'border-orange-200 bg-orange-50' : 'border-gray-200' }}">
                                <div class="text-lg font-bold {{ $count > 0 ? 'text-orange-600' : 'text-green-600' }}">{{ $count }}</div>
                                <div class="text-xs text-gray-600">{{ $label }}</div>
                            </button>
                        @endforeach
                    </div>
                </x-ui.card>

                {{-- Top issues from summary --}}
                <x-ui.card class="lg:col-span-2">
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Issue Summary') }}</h3>
                    <div class="grid grid-cols-2 gap-x-8 gap-y-2 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach([
                            ['Broken Links', $this->summary['broken_links'] ?? 0, 'critical'],
                            ['Duplicate Titles', $this->summary['duplicate_titles'] ?? 0, 'high'],
                            ['Duplicate Descs', $this->summary['duplicate_descriptions'] ?? 0, 'medium'],
                            ['Orphan Pages', $this->summary['orphan_pages'] ?? 0, 'medium'],
                            ['Noindex', $this->summary['noindex_pages'] ?? 0, 'info'],
                        ] as [$label, $count, $sev])
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-600">{{ $label }}</span>
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-xs font-semibold',
                                    'bg-red-100 text-red-700' => $count > 0 && in_array($sev, ['critical', 'high']),
                                    'bg-yellow-100 text-yellow-700' => $count > 0 && $sev === 'medium',
                                    'bg-gray-100 text-gray-600' => $count === 0 || $sev === 'info',
                                ])>{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>

                {{-- Sitemap status --}}
                <x-ui.card class="lg:col-span-2">
                    <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Sitemap') }}</h3>
                    @if(($this->summary['sitemap_count'] ?? 0) > 0)
                        <p class="text-sm text-green-600 font-medium">{{ $this->summary['sitemap_count'] }} {{ __('URLs found in sitemap.xml') }}</p>
                        <button wire:click="setDataTab('sitemaps')" class="mt-2 text-sm text-purple-600 hover:text-purple-800 font-medium">{{ __('View sitemap comparison') }} &rarr;</button>
                    @else
                        <p class="text-sm text-orange-600">{{ __('No sitemap.xml found. Consider adding one to help search engines discover your pages.') }}</p>
                    @endif
                </x-ui.card>
            </div>

        @elseif($analysisTab === 'issues')
            @php $grouped = $this->issuesGrouped; @endphp
            @if(empty($grouped))
                <x-ui.card><x-ui.empty-state title="{{ __('No issues found') }}" description="{{ __('No SEO issues detected.') }}" icon="check-circle" /></x-ui.card>
            @else
                <div class="space-y-4">
                    @foreach($grouped as $severity => $types)
                        <x-ui.card>
                            <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                                <span @class(['h-3 w-3 rounded-full', 'bg-red-500' => in_array($severity, ['critical', 'high']), 'bg-yellow-500' => $severity === 'medium', 'bg-blue-400' => in_array($severity, ['low', 'info'])])></span>
                                {{ ucfirst($severity) }}
                                <span class="font-normal text-gray-400">({{ collect($types)->flatten(1)->count() }})</span>
                            </h3>
                            <div class="space-y-2" x-data="{ openIssue: null }">
                                @foreach($types as $type => $pages)
                                    @php $firstRec = collect($pages)->pluck('recommendation')->filter()->first(); @endphp
                                    <div class="rounded-lg border border-gray-200">
                                        <button @click="openIssue = openIssue === '{{ $type }}' ? null : '{{ $type }}'" class="flex w-full items-center justify-between px-4 py-3 text-left text-sm hover:bg-gray-50 transition">
                                            <span class="font-medium text-gray-700">{{ str_replace('_', ' ', ucfirst($type)) }}</span>
                                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">{{ count($pages) }}</span>
                                        </button>
                                        <div x-show="openIssue === '{{ $type }}'" x-cloak class="border-t border-gray-100">
                                            {{-- Recommendation --}}
                                            @if($firstRec)
                                                <div class="bg-blue-50 border-b border-blue-100 px-4 py-3">
                                                    <div class="flex items-start gap-2">
                                                        <span class="mt-0.5 shrink-0 text-blue-500">&#x1f527;</span>
                                                        <div>
                                                            <div class="text-xs font-semibold text-blue-700 mb-0.5">{{ __('How to fix') }}</div>
                                                            <p class="text-sm text-blue-800">{{ $firstRec }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endif
                                            {{-- Affected pages --}}
                                            <div class="px-4 py-3 max-h-60 overflow-y-auto space-y-1.5">
                                                <div class="text-xs font-medium text-gray-500 mb-1">{{ __('Affected pages') }}:</div>
                                                @foreach(array_slice($pages, 0, 50) as $entry)
                                                    <a href="{{ $entry['url'] }}" target="_blank" class="block truncate text-sm text-purple-600 hover:text-purple-800">{{ $entry['url'] }}</a>
                                                @endforeach
                                                @if(count($pages) > 50) <p class="text-xs text-gray-400">+{{ count($pages) - 50 }} more</p> @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </x-ui.card>
                    @endforeach
                </div>
            @endif

        @elseif($analysisTab === 'structure')
            <x-ui.card>
                <h3 class="mb-4 text-sm font-semibold text-gray-900">{{ __('Crawl Depth Distribution') }}</h3>
                <div class="space-y-3">
                    @foreach($this->siteStructure as $depth => $count)
                        <div class="flex items-center gap-4">
                            <span class="w-16 text-right text-sm text-gray-600">{{ __('Depth') }} {{ $depth }}</span>
                            <div class="h-7 flex-1 rounded-lg bg-gray-100">
                                <div class="bg-purple-500 h-7 rounded-lg flex items-center px-3" style="width: {{ min(100, max(3, ($count / max(1, $siteCrawl->pages_crawled ?? 1)) * 100)) }}%">
                                    <span class="text-xs font-semibold text-white">{{ $count }}</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

        @elseif($analysisTab === 'response_times')
            @php $rt = $this->responseTimeStats; @endphp
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <x-ui.card>
                    <h3 class="mb-4 text-sm font-semibold text-gray-900">{{ __('Response Time Distribution') }}</h3>
                    <div class="mb-4 grid grid-cols-2 gap-3">
                        <div class="rounded-lg bg-gray-50 p-4 text-center"><div class="text-2xl font-bold text-gray-900">{{ $rt['avg'] }}ms</div><div class="text-xs text-gray-500">{{ __('Average') }}</div></div>
                        <div class="rounded-lg bg-gray-50 p-4 text-center"><div class="text-2xl font-bold text-gray-900">{{ $rt['p90'] }}ms</div><div class="text-xs text-gray-500">P90</div></div>
                    </div>
                    <div class="space-y-3">
                        @foreach([['< 200ms', $rt['fast'], 'bg-green-500'], ['200-500ms', $rt['medium'], 'bg-blue-500'], ['500ms-2s', $rt['slow'], 'bg-yellow-500'], ['> 2s', $rt['very_slow'], 'bg-red-500']] as [$label, $count, $color])
                            <div class="flex items-center gap-3">
                                <span class="w-20 text-sm text-gray-600">{{ $label }}</span>
                                <div class="h-6 flex-1 rounded-lg bg-gray-100">
                                    <div class="{{ $color }} h-6 rounded-lg flex items-center px-2" style="width: {{ min(100, max(1, ($count / max(1, $siteCrawl->pages_crawled ?? 1)) * 100)) }}%">
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
                            <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2.5">
                                <span class="truncate text-sm text-gray-700 mr-3">{{ \Illuminate\Support\Str::limit($pUrl, 50) }}</span>
                                <span class="shrink-0 rounded-lg bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700">{{ $pTime }}ms</span>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            </div>

        @elseif($analysisTab === 'segments')
            <x-ui.card>
                <h3 class="mb-4 text-sm font-semibold text-gray-900">{{ __('URL Segments') }}</h3>
                <div class="space-y-3">
                    @forelse($this->segments as $label => $count)
                        <div class="flex items-center gap-4">
                            <span class="w-32 text-sm font-medium text-gray-600">{{ $label }}</span>
                            <div class="h-7 flex-1 rounded-lg bg-gray-100">
                                <div class="bg-purple-500 h-7 rounded-lg flex items-center px-3" style="width: {{ min(100, max(3, ($count / max(1, $siteCrawl->pages_crawled ?? 1)) * 100)) }}%">
                                    <span class="text-xs font-semibold text-white">{{ $count }}</span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">{{ __('No segments detected.') }}</p>
                    @endforelse
                </div>
            </x-ui.card>

        @elseif($dataTab === 'sitemaps' && $analysisTab === 'overview')
            {{-- ════════ SITEMAPS COMPARISON ════════ --}}
            @php $sm = $this->sitemapComparison; @endphp
            @if(!$sm['found'])
                <x-ui.card>
                    <x-ui.empty-state
                        title="{{ __('No sitemap found') }}"
                        description="{{ __('No sitemap.xml was found during this crawl. Add a sitemap to help search engines discover all your pages.') }}"
                        icon="globe"
                    />
                    <div class="mt-4 rounded-lg bg-blue-50 p-4">
                        <div class="flex items-start gap-2">
                            <span class="mt-0.5 shrink-0 text-blue-500">&#x1f527;</span>
                            <div class="text-sm text-blue-800">
                                <strong>{{ __('How to fix') }}:</strong> {{ __('Install Yoast SEO or RankMath plugin — both generate a sitemap.xml automatically. Then submit it in Google Search Console under Sitemaps.') }}
                            </div>
                        </div>
                    </div>
                </x-ui.card>
            @else
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 mb-4">
                    <x-ui.stat-card label="{{ __('In Sitemap & Crawled') }}" :value="$sm['in_both_count']" />
                    <x-ui.stat-card label="{{ __('In Sitemap Only') }}" :value="$sm['only_sitemap_count']" />
                    <x-ui.stat-card label="{{ __('Crawled Only') }}" :value="$sm['only_crawl_count']" />
                </div>

                @if($sm['only_sitemap_count'] > 0)
                    <x-ui.card class="mb-4">
                        <h3 class="mb-2 text-sm font-semibold text-orange-700">{{ __('In Sitemap but NOT Crawled') }} ({{ $sm['only_sitemap_count'] }})</h3>
                        <p class="mb-3 text-xs text-gray-500">{{ __('These URLs are in your sitemap but were not discovered during the crawl. They may be orphan pages or have broken internal linking.') }}</p>
                        <div class="rounded-lg bg-blue-50 p-3 mb-3">
                            <p class="text-xs text-blue-800"><strong>Fix:</strong> {{ __('Ensure these pages are linked from other pages on your site. If they are no longer needed, remove them from the sitemap.') }}</p>
                        </div>
                        <div class="max-h-60 overflow-y-auto space-y-1">
                            @foreach($sm['only_sitemap'] as $url)
                                <a href="{{ $url }}" target="_blank" class="block truncate text-sm text-purple-600 hover:text-purple-800">{{ $url }}</a>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                @if($sm['only_crawl_count'] > 0)
                    <x-ui.card class="mb-4">
                        <h3 class="mb-2 text-sm font-semibold text-yellow-700">{{ __('Crawled but NOT in Sitemap') }} ({{ $sm['only_crawl_count'] }})</h3>
                        <p class="mb-3 text-xs text-gray-500">{{ __('These pages were found by crawling but are not listed in your sitemap. Consider adding them if they should be indexed.') }}</p>
                        <div class="rounded-lg bg-blue-50 p-3 mb-3">
                            <p class="text-xs text-blue-800"><strong>Fix:</strong> {{ __('Add these URLs to your sitemap if they are important pages. In Yoast/RankMath, they are usually added automatically unless excluded.') }}</p>
                        </div>
                        <div class="max-h-60 overflow-y-auto space-y-1">
                            @foreach($sm['only_crawl'] as $url)
                                <div class="truncate text-sm text-gray-700">{{ $url }}</div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                @if($sm['in_both_count'] > 0)
                    <x-ui.card>
                        <h3 class="mb-2 text-sm font-semibold text-green-700">{{ __('In Both Sitemap & Crawl') }} ({{ $sm['in_both_count'] }})</h3>
                        <div class="max-h-40 overflow-y-auto space-y-1">
                            @foreach($sm['in_both'] as $url)
                                <div class="truncate text-sm text-gray-500">{{ $url }}</div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            @endif

        @else
            {{-- ════════ DATA TABLE (full-width) ════════ --}}
            @php
                $data = $this->tableData;
                $columns = $this->tableColumns;
                $isArray = is_array($data);
                $labels = \App\Livewire\Seo\CrawlerResults::COLUMN_LABELS;
            @endphp

            <x-ui.card class="!p-0 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full table-fixed divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                @foreach($columns as $col)
                                    @php
                                    $colWidth = match($col) {
                                        'url', 'page_url', 'source', 'canonical_url', 'redirect_url' => 'w-[35%]',
                                        'title', 'meta_description', 'h1_tags', 'anchor', 'alt' => 'w-[20%]',
                                        default => '',
                                    };
                                @endphp
                                <th @if(!$isArray) wire:click="setSort('{{ $col }}')" @endif @class([
                                        'truncate px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500',
                                        'cursor-pointer hover:text-gray-700' => !$isArray,
                                        $colWidth,
                                    ])>
                                        {{ $labels[$col] ?? ucfirst(str_replace('_', ' ', $col)) }}
                                        @if(!$isArray && $sortBy === $col) <span class="text-purple-600">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span> @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @php $rows = $isArray ? $data : $data->items(); @endphp
                            @forelse($rows as $row)
                                <tr @if(!$isArray) wire:click="selectPage({{ $row->id }})" @endif class="hover:bg-gray-50 {{ !$isArray ? 'cursor-pointer' : '' }} {{ !$isArray && $selectedPageId === ($row->id ?? null) ? 'bg-purple-50' : '' }}">
                                    @foreach($columns as $col)
                                        <td class="truncate px-4 py-2.5">
                                            @php $val = is_array($row) ? ($row[$col] ?? '') : ($row->{$col} ?? ''); @endphp
                                            @if($col === 'status_code' && is_numeric($val))
                                                <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-green-100 text-green-800' => $val >= 200 && $val < 300, 'bg-blue-100 text-blue-800' => $val >= 300 && $val < 400, 'bg-red-100 text-red-800' => $val >= 400 || $val === 0])>{{ $val ?: 'ERR' }}</span>
                                            @elseif(in_array($col, ['url', 'page_url', 'source', 'canonical_url', 'redirect_url']))
                                                <span class="text-gray-900 max-w-sm truncate block" title="{{ $val }}">{{ \Illuminate\Support\Str::limit((string) $val, 50) }}</span>
                                            @elseif(in_array($col, ['title', 'meta_description', 'anchor', 'alt']))
                                                <span class="text-gray-600 max-w-sm truncate block">{{ \Illuminate\Support\Str::limit(is_string($val) ? $val : '', 40) }}</span>
                                            @elseif($col === 'h1_tags' && is_array($val))
                                                <span class="text-gray-600 max-w-xs truncate block">{{ \Illuminate\Support\Str::limit(implode(' | ', $val), 50) }}</span>
                                            @elseif(($col === 'hreflang' || $col === 'structured_data_types') && is_array($val))
                                                <span class="text-gray-500">{{ count($val) > 0 ? implode(', ', array_slice($val, 0, 3)) : '—' }}</span>
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
                                                @if($val) <span class="text-green-600">Yes</span> @else <span class="text-gray-400">No</span> @endif
                                            @elseif($col === 'has_mixed_content')
                                                @if($val) <span class="text-red-600 font-semibold">Yes</span> @else <span class="text-gray-400">—</span> @endif
                                            @elseif($col === 'nofollow')
                                                @if($val) <span class="rounded bg-orange-100 px-1.5 py-0.5 text-xs text-orange-700">nf</span> @else <span class="text-gray-400">—</span> @endif
                                            @elseif($col === 'type')
                                                <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-purple-100 text-purple-700' => $val === 'internal', 'bg-gray-100 text-gray-600' => $val !== 'internal'])>{{ $val }}</span>
                                            @elseif($col === 'readability_score')
                                                <span class="text-gray-600">{{ $val ? number_format((float) $val, 1) : '—' }}</span>
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
                    <div class="border-t border-gray-200 px-4 py-3">{{ $data->links() }}</div>
                @endif
            </x-ui.card>
        @endif
    </div>

    {{-- Page detail drawer --}}
    @if($this->selectedPage)
        @php $sp = $this->selectedPage; @endphp
        <div class="fixed inset-0 z-40">
            <div class="absolute inset-0 bg-gray-900/20" wire:click="selectPage(null)"></div>
            <div class="absolute inset-y-0 right-0 w-full max-w-lg overflow-y-auto bg-white shadow-xl sm:w-[480px]">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4">
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('Page Details') }}</h3>
                    <button wire:click="selectPage(null)" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                        <x-dynamic-component component="icons.x" class="h-5 w-5" />
                    </button>
                </div>
                <div class="divide-y divide-gray-100 px-6">
                    <div class="py-4">
                        <a href="{{ $sp->url }}" target="_blank" class="text-sm font-medium text-purple-600 hover:text-purple-800 break-all">{{ $sp->url }}</a>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold', 'bg-green-100 text-green-800' => $sp->status_code >= 200 && $sp->status_code < 300, 'bg-blue-100 text-blue-800' => $sp->status_code >= 300 && $sp->status_code < 400, 'bg-red-100 text-red-800' => $sp->status_code >= 400])>{{ $sp->status_code }}</span>
                            <span class="text-sm text-gray-500">{{ $sp->response_time_ms }}ms</span>
                            <span class="text-sm text-gray-500">Depth {{ $sp->depth }}</span>
                        </div>
                    </div>
                    <div class="py-4"><label class="text-xs font-medium uppercase tracking-wider text-gray-400">Title ({{ $sp->title_length }})</label><p class="mt-1 text-sm text-gray-900">{{ $sp->title ?? '—' }}</p></div>
                    <div class="py-4"><label class="text-xs font-medium uppercase tracking-wider text-gray-400">Meta Description ({{ $sp->meta_desc_length }})</label><p class="mt-1 text-sm text-gray-700">{{ $sp->meta_description ?? '—' }}</p></div>
                    <div class="py-4">
                        <label class="text-xs font-medium uppercase tracking-wider text-gray-400">Headings</label>
                        <div class="mt-1 space-y-1">
                            @foreach($sp->h1_tags ?? [] as $h1) <div class="text-sm"><span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500">H1</span> {{ $h1 }}</div> @endforeach
                            <div class="text-sm text-gray-500">H2: {{ $sp->h2_count }} &middot; H3: {{ $sp->h3_count }}</div>
                        </div>
                    </div>
                    <div class="py-4 grid grid-cols-3 gap-4 text-sm">
                        <div><span class="text-gray-400 text-xs">Words</span><div class="font-medium">{{ $sp->word_count }}</div></div>
                        <div><span class="text-gray-400 text-xs">Images</span><div class="font-medium">{{ $sp->images_count }} ({{ $sp->images_without_alt }} no alt)</div></div>
                        <div><span class="text-gray-400 text-xs">Links</span><div class="font-medium">{{ $sp->internal_links_count }}i / {{ $sp->external_links_count }}e</div></div>
                    </div>
                    <div class="py-4 space-y-1.5 text-sm">
                        <div><span class="text-gray-400">Canonical:</span> {{ $sp->canonical_url ?? '—' }} @if($sp->canonical_self_ref) <span class="text-green-600">(self)</span> @endif</div>
                        <div><span class="text-gray-400">Robots:</span> {{ $sp->meta_robots ?? '—' }}</div>
                    </div>
                    @if(!empty($this->selectedPageInlinks))
                        <div class="py-4">
                            <label class="text-xs font-medium uppercase tracking-wider text-gray-400">Inlinks ({{ count($this->selectedPageInlinks) }})</label>
                            <div class="mt-1 max-h-40 overflow-y-auto space-y-1">
                                @foreach($this->selectedPageInlinks as $il) <div class="truncate text-sm text-purple-600">{{ $il }}</div> @endforeach
                            </div>
                        </div>
                    @endif
                    @if(!empty($sp->issues))
                        <div class="py-4">
                            <label class="text-xs font-medium uppercase tracking-wider text-gray-400">Issues ({{ count($sp->issues) }})</label>
                            <div class="mt-2 space-y-3">
                                @foreach($sp->issues as $issue)
                                    <div class="rounded-lg border border-gray-200 p-3">
                                        <div class="flex items-start gap-2 text-sm">
                                            <span @class(['mt-1 h-2.5 w-2.5 rounded-full shrink-0', 'bg-red-500' => in_array($issue['severity'] ?? '', ['critical', 'high']), 'bg-yellow-500' => ($issue['severity'] ?? '') === 'medium', 'bg-blue-400' => in_array($issue['severity'] ?? '', ['low', 'info'])])></span>
                                            <span class="font-medium text-gray-800">{{ $issue['message'] ?? '' }}</span>
                                        </div>
                                        @if(!empty($issue['recommendation']))
                                            <div class="mt-2 ml-4 rounded bg-blue-50 px-3 py-2 text-xs text-blue-800">
                                                <span class="font-semibold">Fix:</span> {{ $issue['recommendation'] }}
                                            </div>
                                        @endif
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
