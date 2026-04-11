<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('Backlinks') }}</h1>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('Global backlink overview across all sites') }}</p>
        </div>

        {{-- Site filter --}}
        <div class="flex items-center gap-3">
            <label for="site-filter" class="sr-only">{{ __('Filter by site') }}</label>
            <select
                id="site-filter"
                wire:model.live="siteId"
                class="rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
            >
                <option value="">{{ __('All sites') }}</option>
                @foreach($this->siteOptions as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        {{-- Total Backlinks --}}
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-gray-700/30">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Total Backlinks') }}</p>
            <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                {{ number_format($this->stats['total_backlinks']) }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('active') }}</p>
        </div>

        {{-- Referring Domains --}}
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-gray-700/30">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Referring Domains') }}</p>
            <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                {{ number_format($this->stats['referring_domains']) }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('unique domains') }}</p>
        </div>

        {{-- New (last 30d) --}}
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-gray-700/30">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('New') }}</p>
            <p class="mt-2 text-2xl font-semibold text-green-600 dark:text-green-400">
                +{{ number_format($this->stats['new_last_30d']) }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('last 30 days') }}</p>
        </div>

        {{-- Lost (last 30d) --}}
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-gray-700/30">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Lost') }}</p>
            <p class="mt-2 text-2xl font-semibold text-red-500 dark:text-red-400">
                -{{ number_format($this->stats['lost_last_30d']) }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('last 30 days') }}</p>
        </div>
    </div>

    {{-- Sites table --}}
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-gray-700/30">
        {{-- Table header row --}}
        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3 dark:border-gray-700">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Sites') }}</h2>

            {{-- Sort controls --}}
            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <span>{{ __('Sort by') }}:</span>
                <button
                    wire:click="sort('total_backlinks')"
                    @class([
                        'rounded px-2 py-1 font-medium transition',
                        'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300' => $sortBy === 'total_backlinks',
                        'hover:bg-gray-100 dark:hover:bg-gray-700' => $sortBy !== 'total_backlinks',
                    ])
                >
                    {{ __('Backlinks') }}
                    @if($sortBy === 'total_backlinks')
                        {{ $sortDir === 'asc' ? '↑' : '↓' }}
                    @endif
                </button>
                <button
                    wire:click="sort('referring_domains')"
                    @class([
                        'rounded px-2 py-1 font-medium transition',
                        'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300' => $sortBy === 'referring_domains',
                        'hover:bg-gray-100 dark:hover:bg-gray-700' => $sortBy !== 'referring_domains',
                    ])
                >
                    {{ __('Domains') }}
                    @if($sortBy === 'referring_domains')
                        {{ $sortDir === 'asc' ? '↑' : '↓' }}
                    @endif
                </button>
            </div>
        </div>

        @if($this->sites->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('No backlink data found') }}</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('Backlink snapshots will appear here once they are collected.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ __('Site') }}
                            </th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ __('Total Backlinks') }}
                            </th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ __('Ref. Domains') }}
                            </th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ __('New') }}
                            </th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ __('Lost') }}
                            </th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ __('Dofollow') }}
                            </th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ __('Snapshot Date') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($this->sites as $site)
                            @php $snap = $site->latestBacklinkSnapshot; @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <td class="px-5 py-3">
                                    <a
                                        href="{{ route('sites.overview', $site) }}"
                                        wire:navigate
                                        class="font-medium text-gray-900 hover:text-purple-600 dark:text-gray-100 dark:hover:text-purple-400"
                                    >
                                        {{ $site->name }}
                                    </a>
                                    <div class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-500">{{ $site->url }}</div>
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $snap ? number_format($snap->total_backlinks) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right text-gray-700 dark:text-gray-300">
                                    {{ $snap ? number_format($snap->referring_domains) : '—' }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if($snap && $snap->new_backlinks > 0)
                                        <span class="font-medium text-green-600 dark:text-green-400">+{{ number_format($snap->new_backlinks) }}</span>
                                    @elseif($snap)
                                        <span class="text-gray-400 dark:text-gray-500">0</span>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if($snap && $snap->lost_backlinks > 0)
                                        <span class="font-medium text-red-500 dark:text-red-400">-{{ number_format($snap->lost_backlinks) }}</span>
                                    @elseif($snap)
                                        <span class="text-gray-400 dark:text-gray-500">0</span>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @if($snap)
                                        @php
                                            $total = $snap->dofollow_count + $snap->nofollow_count;
                                            $pct = $total > 0 ? round($snap->dofollow_count / $total * 100) : 0;
                                        @endphp
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
                                            'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400' => $pct >= 70,
                                            'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400' => $pct >= 40 && $pct < 70,
                                            'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400' => $pct < 40,
                                        ])>{{ $pct }}%</span>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right text-xs text-gray-400 dark:text-gray-500">
                                    {{ $snap?->date?->format('M d, Y') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($this->sites->hasPages())
                <div class="border-t border-gray-200 px-5 py-3 dark:border-gray-700">
                    {{ $this->sites->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
