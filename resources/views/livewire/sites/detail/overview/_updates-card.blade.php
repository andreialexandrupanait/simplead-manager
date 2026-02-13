<x-ui.card :padding="false">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100">
                <svg class="h-5 w-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900">Pending Updates</h3>
        </div>
        <a href="{{ route('sites.updates', $site) }}" class="text-sm text-purple-600 hover:text-purple-700">
            View Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="p-4">
        @php
            $updates = $this->updatesData;
            $hasUpdates = $updates['total'] > 0;
        @endphp

        @if($hasUpdates)
            {{-- Total Count --}}
            <div class="mb-4 text-center">
                <div class="text-5xl font-bold text-blue-600">{{ $updates['total'] }}</div>
                <div class="mt-1 text-sm text-gray-500">
                    {{ Str::plural('Update', $updates['total']) }} Available
                </div>
            </div>

            {{-- Update Breakdown --}}
            <div class="space-y-2 border-t border-gray-100 pt-4">
                @if($updates['core'] > 0)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                        </svg>
                        <span class="text-sm text-gray-600">WordPress Core</span>
                    </div>
                    <span class="rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700">
                        {{ $updates['core'] }}
                    </span>
                </div>
                @endif

                @if($updates['plugins'] > 0)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/>
                        </svg>
                        <span class="text-sm text-gray-600">Plugins</span>
                    </div>
                    <span class="rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700">
                        {{ $updates['plugins'] }}
                    </span>
                </div>
                @endif

                @if($updates['themes'] > 0)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                        </svg>
                        <span class="text-sm text-gray-600">Themes</span>
                    </div>
                    <span class="rounded-full bg-blue-100 px-2 py-1 text-xs font-medium text-blue-700">
                        {{ $updates['themes'] }}
                    </span>
                </div>
                @endif
            </div>

            {{-- Update All Button --}}
            <div class="mt-4 border-t border-gray-100 pt-4">
                <x-ui.button wire:click="updateAll" color="purple" size="sm" class="w-full">
                    Update All
                </x-ui.button>
            </div>
        @else
            <x-ui.empty-state
                title="All up to date"
                description="Your site is running the latest versions."
            />
        @endif
    </div>
</x-ui.card>
