<div>
    <x-ui.page-header title="{{ __('Backlinks') }}" subtitle="{{ __('External links pointing to your site') }}" />

    @include('livewire.sites.detail.seo.partials.seo-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />

    {{-- Stats Overview --}}
    @php $stats = $this->stats; @endphp
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        {{-- Total --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Total') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['total']) }}</p>
        </div>

        {{-- Referring Domains --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Ref. Domains') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['referring_domains']) }}</p>
        </div>

        {{-- New Last 30d --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('New (30d)') }}</p>
            <p class="mt-1 text-2xl font-bold text-green-600 dark:text-green-400">+{{ number_format($stats['new_last_30_days']) }}</p>
        </div>

        {{-- Lost Last 30d --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Lost (30d)') }}</p>
            <p class="mt-1 text-2xl font-bold text-red-600 dark:text-red-400">-{{ number_format($stats['lost_last_30_days']) }}</p>
        </div>

        {{-- Dofollow --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Dofollow') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['dofollow']) }}</p>
            @if($stats['total'] > 0)
                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                    {{ number_format($stats['dofollow'] / $stats['total'] * 100, 0) }}%
                </p>
            @endif
        </div>

        {{-- Nofollow --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Nofollow') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['nofollow']) }}</p>
            @if($stats['total'] > 0)
                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                    {{ number_format($stats['nofollow'] / $stats['total'] * 100, 0) }}%
                </p>
            @endif
        </div>
    </div>

    {{-- Import CSV --}}
    <div class="mb-6 flex justify-end">
        <button
            wire:click="toggleImportForm"
            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            {{ __('Import CSV') }}
        </button>
    </div>

    {{-- CSV Import Form --}}
    @if($showImportForm)
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Import Backlinks from CSV') }}</h3>
            <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
                {{ __('Required columns: source_url, target_url. Optional columns: anchor_text, nofollow (1/true/yes).') }}
            </p>

            <form wire:submit="importCsv" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="csv-upload" class="mb-1.5 block text-xs font-medium text-gray-700 dark:text-gray-300">
                        {{ __('CSV File') }} <span class="text-gray-400">({{ __('max 10 MB') }})</span>
                    </label>
                    <input
                        id="csv-upload"
                        type="file"
                        wire:model="csvFile"
                        accept=".csv,.txt"
                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 shadow-sm transition
                               file:mr-3 file:rounded file:border-0 file:bg-purple-50 file:px-3 file:py-1 file:text-xs file:font-medium file:text-purple-700
                               hover:file:bg-purple-100
                               focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500
                               dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                    />
                    @error('csvFile')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex shrink-0 gap-2">
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="importCsv"
                        class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-purple-700 disabled:opacity-60">
                        <svg wire:loading.remove wire:target="importCsv" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        <svg wire:loading wire:target="importCsv" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span wire:loading.remove wire:target="importCsv">{{ __('Import') }}</span>
                        <span wire:loading wire:target="importCsv">{{ __('Importing...') }}</span>
                    </button>

                    <button
                        type="button"
                        wire:click="toggleImportForm"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('Cancel') }}
                    </button>
                </div>
            </form>
        </div>
    @endif

    @if($stats['total'] === 0)
        {{-- Empty state --}}
        <div class="rounded-xl border border-gray-200 bg-white p-12 text-center dark:border-gray-700 dark:bg-gray-800">
            <svg class="mx-auto mb-4 h-12 w-12 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
            </svg>
            <h3 class="mb-1 text-sm font-semibold text-gray-900 dark:text-white">{{ __('No backlinks found') }}</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Import a CSV file to get started, or wait for the next Google Search Console sync.') }}
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

            {{-- Top Linked Pages --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Top Linked Pages') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Pages on your site with the most inbound links') }}</p>
                </div>

                @php $pages = $this->topLinkedPages; @endphp
                @if(empty($pages))
                    <div class="px-5 py-8 text-center text-sm text-gray-400 dark:text-gray-500">{{ __('No data yet') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/30">
                                    <th class="px-5 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Page') }}</th>
                                    <th class="px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Links') }}</th>
                                    <th class="px-4 py-2.5 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Domains') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach($pages as $page)
                                    <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                        <td class="max-w-xs px-5 py-2.5">
                                            <a
                                                href="{{ $page['target_url'] }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="{{ $page['target_url'] }}"
                                                class="block truncate text-xs text-purple-600 hover:text-purple-800 dark:text-purple-400 dark:hover:text-purple-300">
                                                {{ $page['target_url'] }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-2.5 text-center text-xs font-medium text-gray-700 dark:text-gray-300">
                                            {{ number_format($page['backlink_count']) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-center text-xs text-gray-500 dark:text-gray-400">
                                            {{ number_format($page['domain_count']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Anchor Text Distribution --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Anchor Text Distribution') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Most common anchor texts in inbound links') }}</p>
                </div>

                @php
                    $anchors = $this->anchorDistribution;
                    $maxCount = !empty($anchors) ? max(array_column($anchors, 'count')) : 1;
                @endphp
                @if(empty($anchors))
                    <div class="px-5 py-8 text-center text-sm text-gray-400 dark:text-gray-500">{{ __('No anchor text data yet') }}</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/30">
                                    <th class="px-5 py-2.5 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Anchor') }}</th>
                                    <th class="px-4 py-2.5 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Count') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach($anchors as $anchor)
                                    @php $barWidth = $maxCount > 0 ? round($anchor['count'] / $maxCount * 100) : 0; @endphp
                                    <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                        <td class="px-5 py-2.5">
                                            <div class="relative">
                                                <div
                                                    class="absolute inset-y-0 left-0 rounded bg-purple-100 dark:bg-purple-900/30"
                                                    style="width: {{ $barWidth }}%">
                                                </div>
                                                <span class="relative z-10 truncate text-xs font-medium text-gray-800 dark:text-gray-200">
                                                    {{ $anchor['anchor_text'] }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-xs font-medium text-gray-700 dark:text-gray-300">
                                            {{ number_format($anchor['count']) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

        </div>
    @endif
</div>
