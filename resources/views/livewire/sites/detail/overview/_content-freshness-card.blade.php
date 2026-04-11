@php $freshness = $this->contentFreshnessStatus; @endphp

<x-ui.card :padding="false" class="flex flex-col">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg {{ $freshness['stale'] > 0 ? 'bg-amber-100 dark:bg-amber-900/30' : 'bg-green-100 dark:bg-green-900/30' }}">
                <svg class="h-4 w-4 {{ $freshness['stale'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-green-600 dark:text-green-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Content</h3>
        </div>
        <a href="{{ route('sites.content-freshness', $site) }}" class="text-xs text-purple-600 hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300">
            Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="flex flex-1 flex-col p-3">
        @if($freshness['total'] > 0)
            <div class="flex-1 space-y-2">
                {{-- Stale content --}}
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Stale (180+ days)</span>
                    @if($freshness['stale'] > 0)
                        <span class="text-sm font-bold text-red-600 dark:text-red-400">{{ $freshness['stale'] }}</span>
                    @else
                        <span class="text-sm font-medium text-green-600 dark:text-green-400">None</span>
                    @endif
                </div>

                {{-- Total published --}}
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-400">Published</span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ $freshness['total'] }}</span>
                </div>
            </div>

            @if($freshness['stale'] > 0)
                <div class="mt-3 border-t border-gray-100 dark:border-gray-700 pt-2">
                    <a href="{{ route('sites.content-freshness', $site) }}" class="block text-center text-xs font-medium text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300">
                        Review Stale Content →
                    </a>
                </div>
            @endif
        @else
            <div class="py-2 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">No published content tracked</p>
                <a href="{{ route('sites.content-freshness', $site) }}" class="mt-1 inline-block text-xs text-purple-600 hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300">
                    View Content →
                </a>
            </div>
        @endif
    </div>
</x-ui.card>
