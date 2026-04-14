<div class="min-w-0">
    {{-- Header with Add Button --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="Performance" subtitle="Monitor site performance and Core Web Vitals" />
        <x-ui.button wire:click="testAllSites" wire:loading.attr="disabled" wire:confirm="This will queue performance tests for all monitored sites. Continue?">
            <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span wire:loading.remove wire:target="testAllSites">Test All Sites</span>
            <span wire:loading wire:target="testAllSites">Queuing...</span>
        </x-ui.button>
    </div>

    <x-ui.flash-alert type="success" key="perf-success" />

    {{-- Stats Cards --}}
    <div class="mb-6 grid grid-cols-2 sm:grid-cols-4 gap-4">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900">{{ $this->stats['total'] }}</p>
                <p class="mt-1 text-xs text-gray-500">Sites Monitored</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                @php
                    $mobileAvg = $this->stats['avg_mobile'];
                    $mobileColor = $mobileAvg === null ? 'text-gray-400' : ($mobileAvg >= 90 ? 'text-green-600' : ($mobileAvg >= 50 ? 'text-yellow-600' : 'text-red-600'));
                @endphp
                <p class="text-2xl font-bold {{ $mobileColor }}">{{ $mobileAvg ?? '—' }}</p>
                <p class="mt-1 text-xs text-gray-500">Avg Mobile</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                @php
                    $desktopAvg = $this->stats['avg_desktop'];
                    $desktopColor = $desktopAvg === null ? 'text-gray-400' : ($desktopAvg >= 90 ? 'text-green-600' : ($desktopAvg >= 50 ? 'text-yellow-600' : 'text-red-600'));
                @endphp
                <p class="text-2xl font-bold {{ $desktopColor }}">{{ $desktopAvg ?? '—' }}</p>
                <p class="mt-1 text-xs text-gray-500">Avg Desktop</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">{{ $this->stats['poor_count'] }}</p>
                <p class="mt-1 text-xs text-gray-500">Poor (&lt;50)</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Search --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="Search by site name or domain..."
            class="w-full sm:ml-auto sm:w-64"
        />
    </div>

    {{-- Ranking Table --}}
    <x-ui.card class="overflow-hidden">
        @if($monitors->isEmpty())
            <p class="py-8 text-center text-sm text-gray-500">No monitored sites found.</p>
        @else
            {{-- Mobile cards --}}
            <div class="md:hidden space-y-2">
                @foreach($monitors as $index => $monitor)
                    @php
                        $ms = $monitor->latest_mobile_score;
                        $msColor = $ms === null ? 'text-gray-400' : ($ms >= 90 ? 'text-green-600' : ($ms >= 50 ? 'text-yellow-600' : 'text-red-600'));
                        $msBg = $ms === null ? '' : ($ms >= 90 ? 'bg-green-50' : ($ms >= 50 ? 'bg-yellow-50' : 'bg-red-50'));
                        $ds = $monitor->latest_desktop_score;
                        $dsColor = $ds === null ? 'text-gray-400' : ($ds >= 90 ? 'text-green-600' : ($ds >= 50 ? 'text-yellow-600' : 'text-red-600'));
                        $dsBg = $ds === null ? '' : ($ds >= 90 ? 'bg-green-50' : ($ds >= 50 ? 'bg-yellow-50' : 'bg-red-50'));
                        $lcp = $monitor->latestMobileTest?->lcp;
                        $lcpColor = $lcp === null ? 'text-gray-400' : ($lcp <= 2.5 ? 'text-green-600' : ($lcp <= 4.0 ? 'text-yellow-600' : 'text-red-600'));
                        $current = $monitor->latest_mobile_score;
                        $previous = $monitor->previous_mobile_score;
                        $trend = ($current !== null && $previous !== null) ? $current - $previous : null;
                    @endphp
                    <div class="rounded-lg border border-gray-200 p-3">
                        {{-- Rank + site name --}}
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-gray-400 w-5 flex-shrink-0">#{{ $index + 1 }}</span>
                            <div class="min-w-0">
                                @if($monitor->site)
                                    <a href="{{ route('sites.performance', $monitor->site) }}" class="text-sm font-medium text-accent-600 hover:text-accent-800 truncate block">
                                        {{ $monitor->site->name }}
                                    </a>
                                    <div class="text-xs text-gray-400 truncate">{{ $monitor->site->domain }}</div>
                                @else
                                    <span class="text-sm text-gray-400">Deleted site</span>
                                @endif
                            </div>
                        </div>

                        {{-- Scores row --}}
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                            <span>
                                Mobile:
                                <span class="font-bold {{ $msColor }}">{{ $ms ?? '—' }}</span>
                            </span>
                            <span>
                                Desktop:
                                <span class="font-bold {{ $dsColor }}">{{ $ds ?? '—' }}</span>
                            </span>
                            <span>
                                LCP:
                                <span class="font-medium {{ $lcpColor }}">{{ $lcp !== null ? round($lcp, 1) . ' s' : '—' }}</span>
                            </span>
                            @if($trend !== null)
                                <span>
                                    Trend:
                                    <span class="font-medium {{ $trend > 0 ? 'text-green-600' : ($trend < 0 ? 'text-red-600' : 'text-gray-500') }}">
                                        {{ $trend > 0 ? '+' : '' }}{{ $trend }}
                                    </span>
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block -mx-6 overflow-x-auto px-6">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">#</th>
                            <th class="px-3 py-2 text-left text-xs font-medium uppercase text-gray-500">Site</th>
                            <th class="px-3 py-2 text-center text-xs font-medium uppercase text-gray-500">
                                <button wire:click="sort('mobile_score')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    Mobile
                                    @if($sortBy === 'mobile_score')
                                        <svg aria-hidden="true" class="h-3 w-3 {{ $sortDir === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                                    @endif
                                </button>
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium uppercase text-gray-500">
                                <button wire:click="sort('desktop_score')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    Desktop
                                    @if($sortBy === 'desktop_score')
                                        <svg aria-hidden="true" class="h-3 w-3 {{ $sortDir === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                                    @endif
                                </button>
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium uppercase text-gray-500">
                                <button wire:click="sort('lcp')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    LCP
                                    @if($sortBy === 'lcp')
                                        <svg aria-hidden="true" class="h-3 w-3 {{ $sortDir === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                                    @endif
                                </button>
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium uppercase text-gray-500">
                                <button wire:click="sort('trend')" class="inline-flex items-center gap-1 hover:text-gray-700">
                                    Trend
                                    @if($sortBy === 'trend')
                                        <svg aria-hidden="true" class="h-3 w-3 {{ $sortDir === 'asc' ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"/></svg>
                                    @endif
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($monitors as $index => $monitor)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-3 text-sm text-gray-500">{{ $index + 1 }}</td>
                                <td class="px-3 py-3 text-sm">
                                    @if($monitor->site)
                                        <a href="{{ route('sites.performance', $monitor->site) }}" class="font-medium text-accent-600 hover:text-accent-800">
                                            {{ $monitor->site->name }}
                                        </a>
                                        <div class="text-xs text-gray-400">{{ $monitor->site->domain }}</div>
                                    @else
                                        <span class="text-gray-400">Deleted site</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-center">
                                    @php
                                        $ms = $monitor->latest_mobile_score;
                                        $msColor = $ms === null ? 'text-gray-400' : ($ms >= 90 ? 'text-green-600' : ($ms >= 50 ? 'text-yellow-600' : 'text-red-600'));
                                        $msBg = $ms === null ? '' : ($ms >= 90 ? 'bg-green-50' : ($ms >= 50 ? 'bg-yellow-50' : 'bg-red-50'));
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-bold {{ $msColor }} {{ $msBg }}">
                                        {{ $ms ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-center">
                                    @php
                                        $ds = $monitor->latest_desktop_score;
                                        $dsColor = $ds === null ? 'text-gray-400' : ($ds >= 90 ? 'text-green-600' : ($ds >= 50 ? 'text-yellow-600' : 'text-red-600'));
                                        $dsBg = $ds === null ? '' : ($ds >= 90 ? 'bg-green-50' : ($ds >= 50 ? 'bg-yellow-50' : 'bg-red-50'));
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-bold {{ $dsColor }} {{ $dsBg }}">
                                        {{ $ds ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-center text-sm">
                                    @php
                                        $lcp = $monitor->latestMobileTest?->lcp;
                                        $lcpColor = $lcp === null ? 'text-gray-400' : ($lcp <= 2.5 ? 'text-green-600' : ($lcp <= 4.0 ? 'text-yellow-600' : 'text-red-600'));
                                    @endphp
                                    <span class="{{ $lcpColor }}">
                                        {{ $lcp !== null ? round($lcp, 1) . ' s' : '—' }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-center text-sm">
                                    @php
                                        $current = $monitor->latest_mobile_score;
                                        $previous = $monitor->previous_mobile_score;
                                        $trend = ($current !== null && $previous !== null) ? $current - $previous : null;
                                    @endphp
                                    @if($trend !== null)
                                        <span class="{{ $trend > 0 ? 'text-green-600' : ($trend < 0 ? 'text-red-600' : 'text-gray-500') }}">
                                            {{ $trend > 0 ? '+' : '' }}{{ $trend }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
