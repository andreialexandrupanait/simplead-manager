<div>
    <div class="mb-6">
        <x-ui.page-header title="{{ __('Error Logs') }}" subtitle="{{ __('PHP errors across all sites') }}" />
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Unresolved') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">{{ $this->stats['fatal'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Fatal Errors') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ $this->stats['warning'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Warnings') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-accent-600">{{ $this->stats['sites'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Sites Affected') }}</p>
            </div>
        </x-ui.card>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'unresolved' => __('Unresolved'), 'fatal' => __('Fatal'), 'warning' => __('Warnings'), 'resolved' => __('Resolved')]"
            :selected="$filter"
            wire="filter"
        />
        <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search errors or sites...') }}" class="w-full sm:ml-auto sm:w-64" />
    </div>

    <x-ui.card class="!p-0 overflow-hidden">
        @forelse($errors as $error)
            <div class="flex gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ $error->is_resolved ? 'opacity-50' : '' }}">
                <div class="shrink-0 mt-0.5">
                    @if($error->level === 'fatal')
                        <div class="h-7 w-7 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                            <svg class="h-3.5 w-3.5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                        </div>
                    @elseif($error->level === 'warning')
                        <div class="h-7 w-7 rounded-full bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                            <svg class="h-3.5 w-3.5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    @else
                        <div class="h-7 w-7 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                            <svg class="h-3.5 w-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <x-ui.badge :variant="match($error->level) { 'fatal' => 'red', 'warning' => 'yellow', default => 'gray' }">{{ ucfirst($error->level) }}</x-ui.badge>
                        @if($error->count > 1)
                            <span class="text-xs text-gray-400">&times;{{ $error->count }}</span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-gray-900 dark:text-white font-mono text-xs line-clamp-2">{{ $error->message }}</p>
                    @if($error->file)
                        <p class="mt-0.5 text-[11px] text-gray-400 font-mono">{{ $error->file }}:{{ $error->line }}</p>
                    @endif
                    <div class="mt-1 flex items-center gap-3 text-[11px] text-gray-400">
                        @if($error->site)
                            <a href="{{ route('sites.overview', $error->site) }}" class="text-accent-500 hover:underline" wire:navigate>{{ $error->site->name }}</a>
                        @endif
                        <span>{{ __('Last seen') }}: {{ $error->last_seen_at->diffForHumans() }}</span>
                        <span>{{ __('First seen') }}: {{ $error->first_seen_at->diffForHumans() }}</span>
                    </div>
                </div>

                @unless($error->is_resolved)
                    <button wire:click="resolve({{ $error->id }})" class="shrink-0 self-center text-xs text-gray-400 hover:text-green-600 transition" title="{{ __('Mark as resolved') }}">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </button>
                @endunless
            </div>
        @empty
            <div class="py-12 text-center text-sm text-gray-500">{{ __('No errors found.') }}</div>
        @endforelse
    </x-ui.card>

    @if($errors->hasPages())
        <div class="mt-4">{{ $errors->links() }}</div>
    @endif
</div>
