<div {!! $hasRunningJobs || $this->isRunning ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    <x-ui.page-header title="{{ __('Site Crawl') }}" subtitle="{{ __('Crawl your site to discover SEO issues across all pages') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="crawl" :jobs="$trackedJobs" title="{{ __('Crawling site pages...') }}" />

    {{-- Running crawl status --}}
    @if($this->isRunning)
        @php $running = $this->latestCrawl; @endphp
        <div class="mb-6 rounded-xl border border-purple-200 bg-purple-50 p-5">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-purple-800">{{ __('Crawl in progress') }}</p>
                    <p class="mt-1 text-xs text-purple-600">
                        {{ __('Pages crawled') }}: <strong>{{ number_format($running->pages_crawled) }}</strong>
                        @if($running->pages_found > 0)
                            &nbsp;/&nbsp;{{ number_format($running->pages_found) }} {{ __('found') }}
                        @endif
                        &nbsp;&mdash;&nbsp;
                        {{ __('Started') }}: {{ $running->started_at?->diffForHumans() ?? $running->created_at->diffForHumans() }}
                    </p>
                </div>
                <x-ui.button variant="secondary" size="sm" wire:click="cancelCrawl" wire:loading.attr="disabled" wire:target="cancelCrawl">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="cancelCrawl" />
                    {{ __('Cancel Crawl') }}
                </x-ui.button>
            </div>
        </div>
    @endif

    @unless($this->isRunning)
        {{-- Start Crawl Configuration --}}
        @if(!$this->latestCrawl || $this->latestCrawl?->isCompleted() || in_array($this->latestCrawl?->status, ['failed', 'cancelled']))
            <div class="mb-6">
                <x-ui.card>
                    <h3 class="mb-5 text-base font-semibold text-gray-900">
                        {{ $this->latestCrawl ? __('Start New Crawl') : __('Start Your First Crawl') }}
                    </h3>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                        {{-- Max Pages --}}
                        <div>
                            <label for="maxPages" class="mb-1.5 block text-xs font-medium text-gray-700">
                                {{ __('Max pages') }}
                            </label>
                            <input
                                type="number"
                                id="maxPages"
                                wire:model="maxPages"
                                min="10"
                                max="2000"
                                class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
                            />
                            <p class="mt-1 text-xs text-gray-400">{{ __('10 – 2,000') }}</p>
                        </div>

                        {{-- Rate Limit --}}
                        <div>
                            <label for="rateLimit" class="mb-1.5 block text-xs font-medium text-gray-700">
                                {{ __('Delay between requests (ms)') }}
                            </label>
                            <input
                                type="number"
                                id="rateLimit"
                                wire:model="rateLimit"
                                min="500"
                                max="5000"
                                class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
                            />
                            <p class="mt-1 text-xs text-gray-400">{{ __('500 – 5,000 ms') }}</p>
                        </div>

                        {{-- Max Depth --}}
                        <div>
                            <label for="maxDepth" class="mb-1.5 block text-xs font-medium text-gray-700">
                                {{ __('Max depth') }}
                            </label>
                            <input
                                type="number"
                                id="maxDepth"
                                wire:model="maxDepth"
                                min="1"
                                max="100"
                                class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
                            />
                            <p class="mt-1 text-xs text-gray-400">{{ __('1 – 100 levels') }}</p>
                        </div>
                    </div>

                    <div class="mt-5">
                        <x-ui.button variant="primary" wire:click="startCrawl" wire:loading.attr="disabled" wire:target="startCrawl">
                            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="startCrawl" />
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                            {{ __('Start Crawl') }}
                        </x-ui.button>
                    </div>
                </x-ui.card>
            </div>
        @endif
    @endunless

    {{-- Last Crawl Summary --}}
    @php $latestCrawl = $this->latestCrawl; @endphp
    @if($latestCrawl && $latestCrawl->isCompleted())
        @php
            $summary = $latestCrawl->summary ?? [];
            $statusBreakdown = $summary['status_breakdown'] ?? [];
            $total2xx = $statusBreakdown['2xx'] ?? 0;
            $total3xx = $statusBreakdown['3xx'] ?? 0;
            $total4xx = $statusBreakdown['4xx'] ?? 0;
            $total5xx = $statusBreakdown['5xx'] ?? 0;
            $totalCodes = $total2xx + $total3xx + $total4xx + $total5xx;
            $avgResponseMs = $summary['avg_response_time_ms'] ?? null;
            $durationSec = $latestCrawl->duration_seconds;
            $durationFormatted = $durationSec
                ? (floor($durationSec / 60) > 0 ? floor($durationSec / 60).'m ' : '') . ($durationSec % 60).'s'
                : '—';
            $issueTypes = $summary['issue_types'] ?? [];
        @endphp

        <div class="mb-6">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-700">{{ __('Last Crawl Summary') }}</h3>
                <span class="text-xs text-gray-400">{{ $latestCrawl->completed_at?->format('M d, Y H:i') ?? $latestCrawl->created_at->format('M d, Y H:i') }}</span>
            </div>

            {{-- Summary stat cards --}}
            <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {{-- Pages crawled --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-100">
                            <svg class="h-4 w-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <span class="text-xs font-medium text-gray-500">{{ __('Pages crawled') }}</span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">{{ number_format($latestCrawl->pages_crawled) }}</p>
                </div>

                {{-- Issues found --}}
                <div class="rounded-xl border {{ $latestCrawl->pages_with_issues > 0 ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white' }} p-4 shadow-sm">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $latestCrawl->pages_with_issues > 0 ? 'bg-red-100' : 'bg-gray-100' }}">
                            <svg class="h-4 w-4 {{ $latestCrawl->pages_with_issues > 0 ? 'text-red-600' : 'text-gray-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.072 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                        </div>
                        <span class="text-xs font-medium {{ $latestCrawl->pages_with_issues > 0 ? 'text-red-700' : 'text-gray-500' }}">{{ __('Pages with issues') }}</span>
                    </div>
                    <p class="text-2xl font-bold {{ $latestCrawl->pages_with_issues > 0 ? 'text-red-600' : 'text-gray-900' }}">
                        {{ number_format($latestCrawl->pages_with_issues) }}
                    </p>
                </div>

                {{-- Avg response time --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-100">
                            <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <span class="text-xs font-medium text-gray-500">{{ __('Avg response') }}</span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">
                        {{ $avgResponseMs !== null ? number_format((int) $avgResponseMs).'ms' : '—' }}
                    </p>
                </div>

                {{-- Duration --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="mb-2 flex items-center gap-2">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-purple-100">
                            <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                        </div>
                        <span class="text-xs font-medium text-gray-500">{{ __('Duration') }}</span>
                    </div>
                    <p class="text-2xl font-bold text-gray-900">{{ $durationFormatted }}</p>
                </div>
            </div>

            {{-- Status breakdown bar --}}
            @if($totalCodes > 0)
                <x-ui.card class="mb-4">
                    <h4 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('Response Code Breakdown') }}</h4>
                    <div class="mb-3 flex h-3 w-full overflow-hidden rounded-full bg-gray-100">
                        @if($total2xx > 0)
                            <div class="h-full bg-green-500 transition-all" style="width: {{ round($total2xx / $totalCodes * 100, 1) }}%" title="{{ $total2xx }} 2xx"></div>
                        @endif
                        @if($total3xx > 0)
                            <div class="h-full bg-blue-400 transition-all" style="width: {{ round($total3xx / $totalCodes * 100, 1) }}%" title="{{ $total3xx }} 3xx"></div>
                        @endif
                        @if($total4xx > 0)
                            <div class="h-full bg-orange-400 transition-all" style="width: {{ round($total4xx / $totalCodes * 100, 1) }}%" title="{{ $total4xx }} 4xx"></div>
                        @endif
                        @if($total5xx > 0)
                            <div class="h-full bg-red-500 transition-all" style="width: {{ round($total5xx / $totalCodes * 100, 1) }}%" title="{{ $total5xx }} 5xx"></div>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                        @if($total2xx > 0)
                            <div class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-full bg-green-500"></span>
                                <span class="text-xs text-gray-600">2xx &mdash; {{ number_format($total2xx) }}</span>
                            </div>
                        @endif
                        @if($total3xx > 0)
                            <div class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-full bg-blue-400"></span>
                                <span class="text-xs text-gray-600">3xx &mdash; {{ number_format($total3xx) }}</span>
                            </div>
                        @endif
                        @if($total4xx > 0)
                            <div class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-full bg-orange-400"></span>
                                <span class="text-xs text-gray-600">4xx &mdash; {{ number_format($total4xx) }}</span>
                            </div>
                        @endif
                        @if($total5xx > 0)
                            <div class="flex items-center gap-1.5">
                                <span class="h-2.5 w-2.5 rounded-full bg-red-500"></span>
                                <span class="text-xs text-gray-600">5xx &mdash; {{ number_format($total5xx) }}</span>
                            </div>
                        @endif
                    </div>
                </x-ui.card>
            @endif

            {{-- Issue type summary --}}
            @if(!empty($issueTypes))
                <x-ui.card class="mb-4">
                    <h4 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('Top Issue Types') }}</h4>
                    <div class="space-y-2">
                        @foreach(collect($issueTypes)->sortByDesc(fn($count) => $count)->take(8) as $issueType => $count)
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate text-sm text-gray-700">{{ $issueType }}</span>
                                <span class="shrink-0 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">
                                    {{ number_format($count) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif

            {{-- Actions --}}
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('sites.seo.crawl.results', $site) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-purple-700">
                    {{ __('View Detailed Results') }} &rarr;
                </a>
                @unless($this->isRunning)
                    <x-ui.button variant="secondary" size="md" wire:click="startCrawl" wire:loading.attr="disabled" wire:target="startCrawl">
                        <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="startCrawl" />
                        {{ __('Start New Crawl') }}
                    </x-ui.button>
                @endunless
            </div>
        </div>
    @elseif(!$latestCrawl && !$this->isRunning)
        {{-- No crawl yet, no start form shown (already shown above) --}}
        <x-ui.card>
            <x-ui.empty-state
                title="{{ __('No crawl data yet') }}"
                description="{{ __('Configure and start a crawl above to discover SEO issues across all your site\'s pages.') }}"
                icon="search"
            />
        </x-ui.card>
    @endif

    {{-- Recent Crawls Table --}}
    @if($this->recentCrawls->isNotEmpty())
        <div class="mt-6">
            <x-ui.card>
                <h3 class="mb-4 text-base font-semibold text-gray-900">{{ __('Recent Crawls') }}</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="pb-2 text-left font-medium text-gray-500">{{ __('Date') }}</th>
                                <th class="pb-2 text-center font-medium text-gray-500">{{ __('Status') }}</th>
                                <th class="pb-2 text-center font-medium text-gray-500">{{ __('Pages') }}</th>
                                <th class="pb-2 text-center font-medium text-gray-500">{{ __('Issues') }}</th>
                                <th class="pb-2 text-right font-medium text-gray-500">{{ __('Duration') }}</th>
                                <th class="pb-2 text-right font-medium text-gray-500"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($this->recentCrawls as $crawl)
                                @php
                                    $crawlDuration = $crawl->duration_seconds
                                        ? (floor($crawl->duration_seconds / 60) > 0 ? floor($crawl->duration_seconds / 60).'m ' : '') . ($crawl->duration_seconds % 60).'s'
                                        : '—';
                                    $statusBadge = match($crawl->status) {
                                        'completed'  => ['bg-green-100 text-green-700', __('Completed')],
                                        'running'    => ['bg-purple-100 text-purple-700', __('Running')],
                                        'failed'     => ['bg-red-100 text-red-700', __('Failed')],
                                        'cancelled'  => ['bg-gray-100 text-gray-600', __('Cancelled')],
                                        default      => ['bg-yellow-100 text-yellow-700', ucfirst($crawl->status)],
                                    };
                                @endphp
                                <tr>
                                    <td class="py-2.5 text-gray-700">
                                        {{ $crawl->created_at->format('M d, Y H:i') }}
                                    </td>
                                    <td class="py-2.5 text-center">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge[0] }}">
                                            {{ $statusBadge[1] }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 text-center text-gray-700">
                                        {{ number_format($crawl->pages_crawled) }}
                                    </td>
                                    <td class="py-2.5 text-center {{ $crawl->pages_with_issues > 0 ? 'font-medium text-red-600' : 'text-gray-600' }}">
                                        {{ number_format($crawl->pages_with_issues) }}
                                    </td>
                                    <td class="py-2.5 text-right text-gray-500">
                                        {{ $crawlDuration }}
                                    </td>
                                    <td class="py-2.5 text-right">
                                        @if($crawl->isCompleted())
                                            <a href="{{ route('sites.seo.crawl.results', ['site' => $site, 'crawlId' => $crawl->id]) }}"
                                               class="text-xs text-purple-600 hover:text-purple-700">
                                                {{ __('View') }} &rarr;
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- Crawl Scheduling --}}
    <div class="mt-6">
        <x-ui.card>
            <h3 class="mb-4 text-base font-semibold text-gray-900">{{ __('Scheduled Crawls') }}</h3>
            <div class="flex flex-wrap items-center gap-4">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="crawlEnabled" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
                    {{ __('Enable automatic crawling') }}
                </label>

                @if($crawlEnabled)
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-600">{{ __('Every') }}</span>
                        <select wire:model="crawlIntervalDays" class="rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                            <option value="1">{{ __('Day') }}</option>
                            <option value="7">{{ __('Week') }}</option>
                            <option value="14">{{ __('2 Weeks') }}</option>
                            <option value="30">{{ __('Month') }}</option>
                        </select>
                    </div>
                @endif

                <x-ui.button variant="secondary" size="sm" wire:click="saveSchedule">{{ __('Save Schedule') }}</x-ui.button>
            </div>

            @if($crawlEnabled && $site->seoMonitor?->next_crawl_at)
                <p class="mt-2 text-xs text-gray-500">{{ __('Next crawl:') }} {{ $site->seoMonitor->next_crawl_at->format('M d, Y H:i') }}</p>
            @endif
        </x-ui.card>
    </div>
</div>
