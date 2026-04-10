<div x-data="{ expandedRows: {} }">
    <x-ui.page-header title="{{ __('Crawl Results') }}" subtitle="{{ __('Detailed per-page breakdown from the site crawl') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    @if($this->crawl)
        @php
            $crawl = $this->crawl;
            $summary = $this->summary ?? [];
            $statusBreakdown = $summary['status_breakdown'] ?? [];
        @endphp

        {{-- Crawl meta + export --}}
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-4 text-sm text-gray-500">
                <span>
                    {{ __('Crawled') }}:
                    <strong class="text-gray-900">
                        {{ $crawl->completed_at?->format('M d, Y H:i') ?? $crawl->created_at->format('M d, Y H:i') }}
                    </strong>
                </span>
                @if($crawl->duration_seconds)
                    @php
                        $dur = $crawl->duration_seconds;
                        $durStr = (floor($dur / 60) > 0 ? floor($dur / 60).'m ' : '') . ($dur % 60).'s';
                    @endphp
                    <span>{{ __('Duration') }}: <strong class="text-gray-900">{{ $durStr }}</strong></span>
                @endif
                <span>{{ __('Pages') }}: <strong class="text-gray-900">{{ number_format($crawl->pages_crawled) }}</strong></span>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('sites.seo.crawl', $site) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50">
                    &larr; {{ __('Back to Crawl') }}
                </a>
                <x-ui.button variant="secondary" size="sm" wire:click="exportCsv" wire:loading.attr="disabled" wire:target="exportCsv">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="exportCsv" />
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    {{ __('Export CSV') }}
                </x-ui.button>
            </div>
        </div>

        {{-- Summary cards --}}
        @if(!empty($statusBreakdown))
            <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
                @php
                    $summaryCards = [
                        ['label' => __('Total'), 'count' => $crawl->pages_crawled, 'bg' => 'bg-gray-50', 'border' => 'border-gray-200', 'text' => 'text-gray-900', 'sub' => 'text-gray-500'],
                        ['label' => '2xx', 'count' => $statusBreakdown['2xx'] ?? 0, 'bg' => 'bg-green-50', 'border' => 'border-green-200', 'text' => 'text-green-700', 'sub' => 'text-green-600'],
                        ['label' => '3xx', 'count' => $statusBreakdown['3xx'] ?? 0, 'bg' => 'bg-blue-50', 'border' => 'border-blue-200', 'text' => 'text-blue-700', 'sub' => 'text-blue-600'],
                        ['label' => '4xx', 'count' => $statusBreakdown['4xx'] ?? 0, 'bg' => 'bg-orange-50', 'border' => 'border-orange-200', 'text' => 'text-orange-700', 'sub' => 'text-orange-600'],
                        ['label' => '5xx', 'count' => $statusBreakdown['5xx'] ?? 0, 'bg' => 'bg-red-50', 'border' => 'border-red-200', 'text' => 'text-red-700', 'sub' => 'text-red-600'],
                    ];
                @endphp
                @foreach($summaryCards as $card)
                    <div class="rounded-xl border {{ $card['border'] }} {{ $card['bg'] }} p-3 text-center">
                        <p class="text-xl font-bold {{ $card['text'] }}">{{ number_format($card['count']) }}</p>
                        <p class="mt-0.5 text-xs font-medium {{ $card['sub'] }}">{{ $card['label'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Filter pills --}}
        <div class="mb-4 flex flex-wrap items-center gap-2">
            @php
                $filters = [
                    ['key' => 'all',    'label' => __('All')],
                    ['key' => '2xx',    'label' => '2xx'],
                    ['key' => '3xx',    'label' => '3xx'],
                    ['key' => '4xx',    'label' => '4xx'],
                    ['key' => '5xx',    'label' => '5xx'],
                    ['key' => 'issues', 'label' => __('With Issues')],
                ];
            @endphp
            @foreach($filters as $filter)
                <button
                    wire:click="setStatusFilter('{{ $filter['key'] }}')"
                    class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium transition
                           {{ $statusFilter === $filter['key']
                               ? 'bg-purple-600 text-white'
                               : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}"
                >
                    {{ $filter['label'] }}
                </button>
            @endforeach

            {{-- Search --}}
            <div class="ml-auto flex items-center gap-2">
                <div class="relative">
                    <svg class="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search URL...') }}"
                        class="block w-56 rounded-lg border border-gray-300 py-1.5 pl-8 pr-3 text-xs text-gray-900 shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
                    />
                </div>
            </div>
        </div>

        {{-- Pages table --}}
        @php $pages = $this->pages; @endphp
        @if($pages->isNotEmpty())
            <x-ui.card :padding="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 bg-gray-50">
                                <th class="px-4 py-3 text-left">
                                    <button wire:click="setSort('url')"
                                            class="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-700">
                                        {{ __('URL') }}
                                        @if($sortBy === 'url')
                                            <svg class="h-3 w-3 {{ $sortDir === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            </svg>
                                        @endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-center">
                                    <button wire:click="setSort('status_code')"
                                            class="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-700 mx-auto">
                                        {{ __('Status') }}
                                        @if($sortBy === 'status_code')
                                            <svg class="h-3 w-3 {{ $sortDir === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            </svg>
                                        @endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-left">
                                    <button wire:click="setSort('title')"
                                            class="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-700">
                                        {{ __('Title') }}
                                        @if($sortBy === 'title')
                                            <svg class="h-3 w-3 {{ $sortDir === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            </svg>
                                        @endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-center">
                                    <button wire:click="setSort('word_count')"
                                            class="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-700 mx-auto">
                                        {{ __('Words') }}
                                        @if($sortBy === 'word_count')
                                            <svg class="h-3 w-3 {{ $sortDir === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            </svg>
                                        @endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-center">
                                    <button wire:click="setSort('response_time_ms')"
                                            class="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-700 mx-auto">
                                        {{ __('Response') }}
                                        @if($sortBy === 'response_time_ms')
                                            <svg class="h-3 w-3 {{ $sortDir === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                            </svg>
                                        @endif
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">{{ __('Issues') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($pages as $page)
                                @php
                                    $rowKey = 'row-'.$page->id;
                                    $code = $page->status_code;
                                    $codeBadge = match(true) {
                                        $code === null          => 'bg-gray-100 text-gray-500',
                                        $code >= 200 && $code < 300 => 'bg-green-100 text-green-700',
                                        $code >= 300 && $code < 400 => 'bg-blue-100 text-blue-700',
                                        $code >= 400 && $code < 500 => 'bg-orange-100 text-orange-700',
                                        $code >= 500            => 'bg-red-100 text-red-700',
                                        default                 => 'bg-gray-100 text-gray-500',
                                    };
                                    $pageIssues = $page->issues ?? [];
                                    $issueCount = count($pageIssues);
                                @endphp

                                {{-- Main row --}}
                                <tr
                                    class="cursor-pointer transition hover:bg-gray-50"
                                    @click="expandedRows['{{ $rowKey }}'] = !expandedRows['{{ $rowKey }}']"
                                    wire:key="page-row-{{ $page->id }}"
                                >
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <svg class="h-3.5 w-3.5 shrink-0 text-gray-400 transition-transform"
                                                 :class="expandedRows['{{ $rowKey }}'] ? 'rotate-90' : ''"
                                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                            </svg>
                                            <span class="max-w-xs truncate text-xs font-medium text-gray-800" title="{{ $page->url }}">
                                                {{ $page->url }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold {{ $codeBadge }}">
                                            {{ $code ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="block max-w-xs truncate text-xs text-gray-600" title="{{ $page->title }}">
                                            {{ $page->title ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center text-xs text-gray-600">
                                        {{ $page->word_count > 0 ? number_format($page->word_count) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-center text-xs text-gray-600">
                                        {{ $page->response_time_ms !== null ? number_format($page->response_time_ms).'ms' : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if($issueCount > 0)
                                            <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                                {{ $issueCount }}
                                            </span>
                                        @else
                                            <span class="text-xs text-gray-300">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>

                                {{-- Expanded detail row --}}
                                <tr x-show="expandedRows['{{ $rowKey }}']" x-collapse wire:key="page-detail-{{ $page->id }}">
                                    <td colspan="6" class="bg-gray-50 px-6 py-4">
                                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">

                                            {{-- URL & Canonical --}}
                                            <div>
                                                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">{{ __('URL & Canonical') }}</p>
                                                <div class="space-y-1.5">
                                                    <div>
                                                        <span class="text-xs text-gray-500">{{ __('URL') }}:</span>
                                                        <a href="{{ $page->url }}" target="_blank" rel="noopener noreferrer"
                                                           class="ml-1 break-all text-xs text-purple-600 hover:text-purple-700">
                                                            {{ $page->url }}
                                                        </a>
                                                    </div>
                                                    @if($page->canonical_url)
                                                        <div>
                                                            <span class="text-xs text-gray-500">{{ __('Canonical') }}:</span>
                                                            <span class="ml-1 break-all text-xs {{ $page->canonical_self_ref ? 'text-green-600' : 'text-orange-600' }}">
                                                                {{ $page->canonical_url }}
                                                                @if($page->canonical_self_ref)
                                                                    <span class="text-green-500">(self)</span>
                                                                @endif
                                                            </span>
                                                        </div>
                                                    @endif
                                                    @if($page->meta_robots)
                                                        <div>
                                                            <span class="text-xs text-gray-500">{{ __('Robots') }}:</span>
                                                            <code class="ml-1 rounded bg-gray-200 px-1 py-0.5 text-xs text-gray-700">{{ $page->meta_robots }}</code>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>

                                            {{-- Meta / Content --}}
                                            <div>
                                                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">{{ __('Meta & Content') }}</p>
                                                <div class="space-y-1.5">
                                                    <div>
                                                        <span class="text-xs text-gray-500">{{ __('Title') }}:</span>
                                                        <span class="ml-1 text-xs text-gray-800">{{ $page->title ?? '—' }}</span>
                                                        @if($page->title_length > 0)
                                                            <span class="ml-1 text-xs {{ $page->title_length < 30 || $page->title_length > 60 ? 'text-orange-500' : 'text-green-600' }}">({{ $page->title_length }})</span>
                                                        @endif
                                                    </div>
                                                    @if($page->meta_description)
                                                        <div>
                                                            <span class="text-xs text-gray-500">{{ __('Meta desc') }}:</span>
                                                            <span class="ml-1 text-xs text-gray-800">{{ Str::limit($page->meta_description, 80) }}</span>
                                                            @if($page->meta_desc_length > 0)
                                                                <span class="ml-1 text-xs {{ $page->meta_desc_length < 70 || $page->meta_desc_length > 160 ? 'text-orange-500' : 'text-green-600' }}">({{ $page->meta_desc_length }})</span>
                                                            @endif
                                                        </div>
                                                    @endif
                                                    @if(!empty($page->h1_tags))
                                                        <div>
                                                            <span class="text-xs text-gray-500">{{ __('H1') }}:</span>
                                                            @foreach($page->h1_tags as $h1)
                                                                <span class="ml-1 text-xs text-gray-800">{{ Str::limit($h1, 60) }}</span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                    <div class="flex gap-3 text-xs text-gray-600">
                                                        <span>H2: {{ $page->h2_count }}</span>
                                                        <span>H3: {{ $page->h3_count }}</span>
                                                        <span>{{ __('Words') }}: {{ number_format($page->word_count) }}</span>
                                                    </div>
                                                </div>
                                            </div>

                                            {{-- Links & Images --}}
                                            <div>
                                                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">{{ __('Links & Images') }}</p>
                                                <div class="space-y-1.5 text-xs text-gray-600">
                                                    <div class="flex gap-3">
                                                        <span>{{ __('Internal links') }}: <strong class="text-gray-800">{{ $page->internal_links_count }}</strong></span>
                                                        <span>{{ __('External') }}: <strong class="text-gray-800">{{ $page->external_links_count }}</strong></span>
                                                    </div>
                                                    <div class="flex gap-3">
                                                        <span>{{ __('Images') }}: <strong class="text-gray-800">{{ $page->images_count }}</strong></span>
                                                        @if($page->images_without_alt > 0)
                                                            <span class="text-orange-600">{{ $page->images_without_alt }} {{ __('missing alt') }}</span>
                                                        @endif
                                                    </div>
                                                    @if(!empty($page->structured_data_types))
                                                        <div class="flex flex-wrap gap-1 pt-1">
                                                            @foreach($page->structured_data_types as $sdType)
                                                                <span class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700">
                                                                    {{ $sdType }}
                                                                </span>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>

                                            {{-- OG Tags --}}
                                            @if($page->og_title || $page->og_description || $page->og_image)
                                                <div>
                                                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">{{ __('Open Graph') }}</p>
                                                    <div class="space-y-1.5 text-xs text-gray-600">
                                                        @if($page->og_title)
                                                            <div><span class="text-gray-500">og:title:</span> <span class="ml-1 text-gray-800">{{ Str::limit($page->og_title, 60) }}</span></div>
                                                        @endif
                                                        @if($page->og_description)
                                                            <div><span class="text-gray-500">og:description:</span> <span class="ml-1 text-gray-800">{{ Str::limit($page->og_description, 60) }}</span></div>
                                                        @endif
                                                        @if($page->og_image)
                                                            <div><span class="text-gray-500">og:image:</span>
                                                                <a href="{{ $page->og_image }}" target="_blank" rel="noopener noreferrer"
                                                                   class="ml-1 text-purple-600 hover:text-purple-700">{{ Str::limit($page->og_image, 50) }}</a>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Issues --}}
                                            @if($issueCount > 0)
                                                <div class="{{ $page->og_title || $page->og_description ? '' : 'sm:col-span-2 lg:col-span-1' }}">
                                                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">{{ __('Issues') }}</p>
                                                    <div class="space-y-1.5">
                                                        @foreach($pageIssues as $issue)
                                                            @php
                                                                $severity = $issue['severity'] ?? 'info';
                                                                $severityBadge = match($severity) {
                                                                    'critical' => 'bg-red-100 text-red-700',
                                                                    'high'     => 'bg-orange-100 text-orange-700',
                                                                    'medium'   => 'bg-yellow-100 text-yellow-700',
                                                                    'low'      => 'bg-blue-100 text-blue-700',
                                                                    default    => 'bg-gray-100 text-gray-600',
                                                                };
                                                            @endphp
                                                            <div class="flex items-start gap-2">
                                                                <span class="mt-0.5 inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $severityBadge }}">
                                                                    {{ ucfirst($severity) }}
                                                                </span>
                                                                <span class="text-xs text-gray-700">{{ $issue['message'] ?? $issue['type'] ?? '' }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                @if($pages->hasPages())
                    <div class="border-t border-gray-100 px-4 py-3">
                        {{ $pages->links() }}
                    </div>
                @endif
            </x-ui.card>
        @else
            <x-ui.card>
                <x-ui.empty-state
                    title="{{ __('No pages found') }}"
                    description="{{ $search || $statusFilter !== 'all' ? __('No pages match your current filters.') : __('This crawl has no page data recorded.') }}"
                    icon="search"
                >
                    @if($search || $statusFilter !== 'all')
                        <x-slot:action>
                            <button wire:click="$set('statusFilter', 'all'); $set('search', '')"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50">
                                {{ __('Clear filters') }}
                            </button>
                        </x-slot:action>
                    @endif
                </x-ui.empty-state>
            </x-ui.card>
        @endif

    @else
        {{-- No crawl data --}}
        <x-ui.card>
            <x-ui.empty-state
                title="{{ __('No crawl data available') }}"
                description="{{ __('Start a crawl from the Crawl tab to collect page data for this site.') }}"
                icon="search"
            >
                <x-slot:action>
                    <a href="{{ route('sites.seo.crawl', $site) }}"
                       class="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-purple-700">
                        {{ __('Go to Crawl') }} &rarr;
                    </a>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @endif
</div>
