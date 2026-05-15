<div>
    {{-- Header --}}
    <div class="mb-6">
        <x-ui.page-header title="{{ __('Activity') }}" subtitle="{{ __('Timeline of events across all sites') }}" />
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Total Events') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-red-600">{{ $this->stats['critical'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Critical') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-yellow-600">{{ $this->stats['warning'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Warnings') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-green-600">{{ $this->stats['success'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Success') }}</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <select wire:model.live="filter" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm py-1.5 pr-8">
            @foreach($this->typeOptions as $key => $label)
                <option value="{{ $key }}">{{ __($label) }}</option>
            @endforeach
        </select>

        <select wire:model.live="severity" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm py-1.5 pr-8">
            <option value="all">{{ __('All Severities') }}</option>
            <option value="critical">{{ __('Critical') }}</option>
            <option value="warning">{{ __('Warning') }}</option>
            <option value="success">{{ __('Success') }}</option>
            <option value="info">{{ __('Info') }}</option>
        </select>

        <select wire:model.live="dateRange" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm py-1.5 pr-8">
            <option value="today">{{ __('Today') }}</option>
            <option value="week">{{ __('Last 7 days') }}</option>
            <option value="month">{{ __('Last 30 days') }}</option>
            <option value="quarter">{{ __('Last 90 days') }}</option>
        </select>

        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search events...') }}"
            class="w-full sm:ml-auto sm:w-64"
        />
    </div>

    {{-- Timeline --}}
    <x-ui.card class="!p-0 overflow-hidden">
        @forelse($events as $event)
            <div class="flex gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                {{-- Severity indicator --}}
                <div class="shrink-0 mt-0.5">
                    @switch($event->severity)
                        @case('critical')
                            <div class="h-8 w-8 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                                <svg class="h-4 w-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                                </svg>
                            </div>
                            @break
                        @case('warning')
                            <div class="h-8 w-8 rounded-full bg-yellow-100 dark:bg-yellow-900/30 flex items-center justify-center">
                                <svg class="h-4 w-4 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            @break
                        @case('success')
                            <div class="h-8 w-8 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                                <svg class="h-4 w-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            @break
                        @default
                            <div class="h-8 w-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                <svg class="h-4 w-4 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                    @endswitch
                </div>

                {{-- Content --}}
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $event->title }}</span>
                        <x-ui.badge variant="{{ match($event->type) {
                            'uptime' => 'red',
                            'backup' => 'blue',
                            'update', 'plugin' => 'purple',
                            'security' => 'yellow',
                            'performance' => 'green',
                            'auth' => 'gray',
                            default => 'gray',
                        } }}" class="text-[10px]">{{ ucfirst(str_replace('_', ' ', $event->type)) }}</x-ui.badge>
                    </div>

                    @if($event->description)
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 line-clamp-1">{{ $event->description }}</p>
                    @endif

                    <div class="mt-1 flex items-center gap-3 text-[11px] text-gray-400 dark:text-gray-500">
                        <span title="{{ $event->created_at->format('Y-m-d H:i:s') }}">{{ $event->created_at->diffForHumans() }}</span>
                        @if($event->site)
                            <a href="{{ route('sites.overview', $event->site) }}" class="text-accent-500 hover:text-accent-700 hover:underline" wire:navigate>{{ $event->site->name }}</a>
                        @endif
                        @if($event->user)
                            <span>{{ $event->user->name }}</span>
                        @endif
                    </div>
                </div>

                {{-- Link --}}
                @if($event->url)
                    <a href="{{ $event->url }}" class="shrink-0 self-center text-gray-400 hover:text-accent-600 transition" wire:navigate>
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                @endif
            </div>
        @empty
            <div class="py-12 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No activity found for the selected filters.') }}</p>
            </div>
        @endforelse
    </x-ui.card>

    {{-- Pagination --}}
    @if($events->hasPages())
        <div class="mt-4">
            {{ $events->links() }}
        </div>
    @endif
</div>
