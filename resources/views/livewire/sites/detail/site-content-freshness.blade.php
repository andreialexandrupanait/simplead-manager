<div>
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="{{ __('Content Freshness') }}" subtitle="{{ __('Track content age and identify stale pages') }}" />
        <x-ui.button wire:click="syncNow" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="syncNow">{{ __('Sync Now') }}</span>
            <span wire:loading wire:target="syncNow">{{ __('Syncing...') }}</span>
        </x-ui.button>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Total Content') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $this->stats['fresh'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Fresh (<90d)') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ $this->stats['aging'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Aging (90-180d)') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">{{ $this->stats['stale'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Stale (>180d)') }}</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'fresh' => __('Fresh'), 'aging' => __('Aging'), 'stale' => __('Stale'), 'posts' => __('Posts'), 'pages' => __('Pages')]"
            :selected="$filter"
            wire="filter"
        />
        <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search content...') }}" class="w-full sm:ml-auto sm:w-64" />
    </div>

    {{-- Table --}}
    <x-ui.card class="!p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Title') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Type') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Words') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Last Modified') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Freshness') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($contents as $content)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ Str::limit($content->title, 60) }}</div>
                            @if($content->url)
                                <a href="{{ $content->url }}" target="_blank" class="text-xs text-accent-500 hover:underline">{{ __('View') }}</a>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <x-ui.badge :variant="$content->type === 'page' ? 'purple' : 'blue'">{{ ucfirst($content->type) }}</x-ui.badge>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ number_format($content->word_count) }}</td>
                        <td class="px-4 py-3">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $content->modified_at?->format('M j, Y') }}</span>
                            <div class="text-xs text-gray-400">{{ $content->modified_at?->diffForHumans() }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if($content->days_since_modified <= 90)
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-400 rounded-full px-2 py-0.5">
                                    <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> {{ __('Fresh') }}
                                </span>
                            @elseif($content->days_since_modified <= 180)
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-yellow-700 bg-yellow-50 dark:bg-yellow-900/20 dark:text-yellow-400 rounded-full px-2 py-0.5">
                                    <span class="h-1.5 w-1.5 rounded-full bg-yellow-500"></span> {{ __('Aging') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs font-medium text-red-700 bg-red-50 dark:bg-red-900/20 dark:text-red-400 rounded-full px-2 py-0.5">
                                    <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> {{ $content->days_since_modified }}d
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-gray-500">
                            {{ __('No content found. Click "Sync Now" to fetch content from WordPress.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.card>

    @if($contents->hasPages())
        <div class="mt-4">{{ $contents->links() }}</div>
    @endif
</div>
