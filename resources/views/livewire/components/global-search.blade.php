<div class="relative"
     x-data="{ open: @entangle('isOpen') }"
     @keydown.escape.window="$wire.close()"
     @click.outside="$wire.close()">

    {{-- Search Input --}}
    <div class="relative">
        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
            <svg class="h-4 w-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
            </svg>
        </div>
        <input
            type="search"
            wire:model.live.debounce.300ms="query"
            placeholder="{{ __('Search sites, plugins, clients…') }}"
            autocomplete="off"
            class="block w-56 rounded-lg border border-gray-200 bg-gray-50 py-1.5 pl-9 pr-3 text-sm text-gray-900 placeholder-gray-400 focus:border-accent-400 focus:bg-white focus:outline-none focus:ring-1 focus:ring-accent-400 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:placeholder-gray-400 dark:focus:border-accent-500 dark:focus:bg-gray-800 transition"
        />
    </div>

    {{-- Results Dropdown --}}
    <div x-show="open"
         x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute right-0 top-full z-50 mt-2 w-96 origin-top-right overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">

        @if(count($results) === 0 && strlen($query) >= 2)
            <div class="px-4 py-6 text-center">
                <svg class="mx-auto mb-2 h-8 w-8 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                </svg>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No results found for') }} "<span class="font-medium">{{ $query }}</span>"</p>
            </div>
        @else
            <div class="max-h-96 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($results as $group)
                    <div>
                        <div class="bg-gray-50 dark:bg-gray-700/50 px-4 py-1.5">
                            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ $group['category'] }}</p>
                        </div>
                        @foreach($group['items'] as $item)
                            <a href="{{ $item['url'] }}"
                               wire:navigate
                               wire:click="close"
                               class="flex items-center gap-3 px-4 py-2.5 hover:bg-accent-50 dark:hover:bg-accent-900/20 transition group">
                                <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-gray-100 dark:bg-gray-700 group-hover:bg-accent-100 dark:group-hover:bg-accent-900/40 transition">
                                    <svg class="h-3.5 w-3.5 text-gray-500 dark:text-gray-400 group-hover:text-accent-600 dark:group-hover:text-accent-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                    </svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100 group-hover:text-accent-700 dark:group-hover:text-accent-300 transition">{{ $item['title'] }}</p>
                                    @if(!empty($item['subtitle']))
                                        <p class="truncate text-xs text-gray-400 dark:text-gray-500">{{ $item['subtitle'] }}</p>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
