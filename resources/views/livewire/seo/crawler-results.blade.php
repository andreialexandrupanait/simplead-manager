<div>
    <x-ui.page-header title="{{ __('Crawl Results') }}" subtitle="{{ $siteCrawl->site?->name ?? '' }} — {{ $siteCrawl->created_at->format('M d, Y H:i') }}">
        <div class="flex items-center gap-2">
            <button wire:click="exportCsv" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                {{ __('Export CSV') }}
            </button>
            <a href="{{ route('seo.crawler.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                {{ __('Back to Crawls') }}
            </a>
        </div>
    </x-ui.page-header>

    {{-- Summary cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <x-ui.stat-card label="{{ __('Pages Crawled') }}" :value="$siteCrawl->pages_crawled ?? 0" />
        <x-ui.stat-card label="{{ __('Errors') }}" :value="$siteCrawl->errors_count ?? 0" />
        <x-ui.stat-card label="{{ __('Pages with Issues') }}" :value="$siteCrawl->pages_with_issues ?? 0" />
        <x-ui.stat-card label="{{ __('Duration') }}" :value="$siteCrawl->duration_seconds ? gmdate('H:i:s', $siteCrawl->duration_seconds) : '—'" />
    </div>

    {{-- Tabs --}}
    <div class="mb-4 flex gap-1 border-b border-gray-200">
        @foreach(['overview' => 'Overview', 'pages' => 'Pages', 'links' => 'Links', 'images' => 'Images', 'comparison' => 'Compare'] as $key => $label)
            <button
                wire:click="setTab('{{ $key }}')"
                @class([
                    'px-4 py-2 text-sm font-medium border-b-2 transition',
                    'border-purple-600 text-purple-600' => $tab === $key,
                    'border-transparent text-gray-500 hover:text-gray-700' => $tab !== $key,
                ])
            >
                {{ __($label) }}
            </button>
        @endforeach
    </div>

    {{-- Tab: Overview --}}
    @if($tab === 'overview')
        <x-ui.card>
            <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Status Code Distribution') }}</h3>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-center">
                    <div class="text-2xl font-bold text-green-700">{{ $this->summary['status_2xx'] ?? 0 }}</div>
                    <div class="text-xs text-green-600">2xx OK</div>
                </div>
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-center">
                    <div class="text-2xl font-bold text-blue-700">{{ $this->summary['status_3xx'] ?? 0 }}</div>
                    <div class="text-xs text-blue-600">3xx Redirect</div>
                </div>
                <div class="rounded-lg border border-orange-200 bg-orange-50 p-3 text-center">
                    <div class="text-2xl font-bold text-orange-700">{{ $this->summary['status_4xx'] ?? 0 }}</div>
                    <div class="text-xs text-orange-600">4xx Client Error</div>
                </div>
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-center">
                    <div class="text-2xl font-bold text-red-700">{{ $this->summary['status_5xx'] ?? 0 }}</div>
                    <div class="text-xs text-red-600">5xx Server Error</div>
                </div>
            </div>

            @if(!empty($this->summary['issue_counts']))
                <h3 class="mb-3 mt-6 text-sm font-semibold text-gray-900">{{ __('Issues by Category') }}</h3>
                <div class="space-y-2">
                    @foreach($this->summary['issue_counts'] ?? [] as $type => $count)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-700">{{ str_replace('_', ' ', ucfirst($type)) }}</span>
                            <span class="font-semibold text-gray-900">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- Tab: Pages --}}
    @if($tab === 'pages')
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <x-ui.filter-tabs
                :options="['' => __('All'), '2xx' => '2xx', '3xx' => '3xx', '4xx' => '4xx', '5xx' => '5xx', 'issues' => __('Issues')]"
                :selected="$statusFilter"
                wire="statusFilter"
            />
            <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search URLs...') }}" class="ml-auto w-64" />
        </div>

        <x-ui.card class="!p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th wire:click="setSort('url')" class="cursor-pointer px-4 py-2.5 text-left font-medium text-gray-500 hover:text-gray-700">URL</th>
                            <th wire:click="setSort('status_code')" class="cursor-pointer px-4 py-2.5 text-center font-medium text-gray-500 hover:text-gray-700">Status</th>
                            <th class="px-4 py-2.5 text-left font-medium text-gray-500">Title</th>
                            <th wire:click="setSort('response_time_ms')" class="cursor-pointer px-4 py-2.5 text-center font-medium text-gray-500 hover:text-gray-700">Time</th>
                            <th wire:click="setSort('word_count')" class="cursor-pointer px-4 py-2.5 text-center font-medium text-gray-500 hover:text-gray-700">Words</th>
                            <th class="px-4 py-2.5 text-center font-medium text-gray-500">Depth</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($this->pages as $page)
                            <tr class="hover:bg-gray-50">
                                <td class="max-w-xs truncate px-4 py-2 text-gray-900">
                                    <a href="{{ $page->url }}" target="_blank" class="hover:text-purple-600" title="{{ $page->url }}">{{ \Illuminate\Support\Str::limit($page->url, 60) }}</a>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                        'bg-green-100 text-green-800' => $page->status_code >= 200 && $page->status_code < 300,
                                        'bg-blue-100 text-blue-800' => $page->status_code >= 300 && $page->status_code < 400,
                                        'bg-red-100 text-red-800' => $page->status_code >= 400,
                                    ])>{{ $page->status_code }}</span>
                                </td>
                                <td class="max-w-xs truncate px-4 py-2 text-xs text-gray-600">{{ \Illuminate\Support\Str::limit($page->title, 50) }}</td>
                                <td class="px-4 py-2 text-center text-xs {{ ($page->response_time_ms ?? 0) > 2000 ? 'text-red-600 font-semibold' : 'text-gray-500' }}">{{ $page->response_time_ms }}ms</td>
                                <td class="px-4 py-2 text-center text-xs text-gray-500">{{ $page->word_count }}</td>
                                <td class="px-4 py-2 text-center text-xs text-gray-500">{{ $page->depth }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-200 px-4 py-3">
                {{ $this->pages->links() }}
            </div>
        </x-ui.card>
    @endif

    {{-- Tab: Links --}}
    @if($tab === 'links')
        <x-ui.card class="!p-0 overflow-hidden">
            @if(empty($this->links))
                <x-ui.empty-state title="{{ __('No link data') }}" description="{{ __('Link data is extracted during crawl.') }}" icon="link" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500">Source</th>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500">Destination</th>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500">Anchor</th>
                                <th class="px-4 py-2.5 text-center font-medium text-gray-500">Type</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach(array_slice($this->links, 0, 200) as $link)
                                <tr class="hover:bg-gray-50">
                                    <td class="max-w-[200px] truncate px-4 py-2 text-xs text-gray-600">{{ $link['source'] ?? '' }}</td>
                                    <td class="max-w-[200px] truncate px-4 py-2 text-xs text-gray-900">{{ $link['url'] ?? '' }}</td>
                                    <td class="max-w-[150px] truncate px-4 py-2 text-xs text-gray-500">{{ $link['anchor'] ?? '' }}</td>
                                    <td class="px-4 py-2 text-center">
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-purple-100 text-purple-700' => ($link['type'] ?? '') === 'internal',
                                            'bg-gray-100 text-gray-700' => ($link['type'] ?? '') === 'external',
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

    {{-- Tab: Images --}}
    @if($tab === 'images')
        <x-ui.card class="!p-0 overflow-hidden">
            @if(empty($this->imageStats))
                <x-ui.empty-state title="{{ __('No image data') }}" description="{{ __('Image statistics are collected during crawl.') }}" icon="image" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-medium text-gray-500">Page URL</th>
                                <th class="px-4 py-2.5 text-center font-medium text-gray-500">Images</th>
                                <th class="px-4 py-2.5 text-center font-medium text-gray-500">Missing Alt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($this->imageStats as $stat)
                                <tr class="hover:bg-gray-50">
                                    <td class="max-w-xs truncate px-4 py-2 text-xs text-gray-900">{{ $stat['url'] }}</td>
                                    <td class="px-4 py-2 text-center text-gray-600">{{ $stat['total'] }}</td>
                                    <td class="px-4 py-2 text-center {{ $stat['without_alt'] > 0 ? 'text-orange-600 font-semibold' : 'text-gray-400' }}">{{ $stat['without_alt'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- Tab: Comparison --}}
    @if($tab === 'comparison')
        <x-ui.card>
            @if($this->previousCrawls->isEmpty())
                <p class="text-sm text-gray-500">{{ __('No previous crawls to compare with.') }}</p>
            @else
                <h3 class="mb-3 text-sm font-semibold text-gray-900">{{ __('Compare with previous crawl') }}</h3>
                <div class="space-y-2">
                    @foreach($this->previousCrawls as $prev)
                        <a
                            href="{{ route('seo.crawler.compare', [$siteCrawl, $prev]) }}"
                            wire:navigate
                            class="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3 text-sm hover:bg-gray-50 transition"
                        >
                            <span class="text-gray-700">{{ $prev->created_at->format('M d, Y H:i') }}</span>
                            <span class="text-gray-500">{{ $prev->pages_crawled }} pages</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    @endif
</div>
