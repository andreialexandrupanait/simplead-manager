<div>
    {{-- Header --}}
    <div class="mb-6">
        <x-ui.page-header title="{{ __('Notifications') }}" subtitle="{{ __('All in-app notifications and alerts') }}" />
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $this->totalCount }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Total') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-accent-600">{{ $this->unreadCount }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Unread') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $this->totalCount - $this->unreadCount }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Read') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-400 dark:text-gray-500">
                    {{ now()->format('M j') }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Today') }}</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters & Bulk Actions --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        {{-- Filter tabs --}}
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'unread' => __('Unread'), 'read' => __('Read')]"
            :selected="$filter"
            wire="filter"
        />

        {{-- Type filter --}}
        <select wire:model.live="typeFilter"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 text-sm py-1.5 pr-8">
            <option value="all">{{ __('All Types') }}</option>
            <option value="critical">{{ __('Critical') }}</option>
            <option value="warning">{{ __('Warning') }}</option>
            <option value="info">{{ __('Info') }}</option>
        </select>

        {{-- Search --}}
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search notifications...') }}"
            class="w-full sm:ml-auto sm:w-64"
        />
    </div>

    {{-- Bulk Actions --}}
    @if($this->unreadCount > 0 || $this->totalCount > 0)
        <div class="mb-4 flex items-center gap-3">
            @if($this->unreadCount > 0)
                <button
                    wire:click="markAllAsRead"
                    wire:loading.attr="disabled"
                    wire:target="markAllAsRead"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 transition"
                >
                    <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span wire:loading.remove wire:target="markAllAsRead">{{ __('Mark All Read') }}</span>
                    <span wire:loading wire:target="markAllAsRead">{{ __('Marking...') }}</span>
                </button>
            @endif

            <button
                wire:click="deleteOld"
                wire:loading.attr="disabled"
                wire:confirm="{{ __('Delete all read notifications older than 30 days?') }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 disabled:opacity-50 transition"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
                {{ __('Delete Old (30d+)') }}
            </button>
        </div>
    @endif

    {{-- Notification List --}}
    <x-ui.card class="!p-0 overflow-hidden">
        @forelse($notifications as $notification)
            <div
                wire:key="notification-{{ $notification->id }}"
                class="flex items-start gap-4 px-4 py-3.5 border-b border-gray-100 dark:border-gray-700 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition {{ !$notification->isRead() ? 'bg-accent-50/40 dark:bg-accent-900/10' : '' }}"
            >
                {{-- Type Icon --}}
                <div class="shrink-0 mt-0.5">
                    @php
                        $iconBg = match($notification->type) {
                            'critical' => 'bg-red-100 dark:bg-red-900/30',
                            'warning'  => 'bg-yellow-100 dark:bg-yellow-900/30',
                            'info'     => 'bg-blue-100 dark:bg-blue-900/30',
                            default    => 'bg-gray-100 dark:bg-gray-700',
                        };
                        $iconColor = match($notification->type) {
                            'critical' => 'text-red-600 dark:text-red-400',
                            'warning'  => 'text-yellow-600 dark:text-yellow-400',
                            'info'     => 'text-blue-600 dark:text-blue-400',
                            default    => 'text-gray-500 dark:text-gray-400',
                        };
                    @endphp
                    <div class="flex h-8 w-8 items-center justify-center rounded-full {{ $iconBg }}">
                        @if($notification->type === 'critical')
                            <svg class="h-4 w-4 {{ $iconColor }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                        @elseif($notification->type === 'warning')
                            <svg class="h-4 w-4 {{ $iconColor }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        @elseif($notification->type === 'info')
                            <svg class="h-4 w-4 {{ $iconColor }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        @else
                            <svg class="h-4 w-4 {{ $iconColor }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                        @endif
                    </div>
                </div>

                {{-- Content --}}
                <div class="min-w-0 flex-1">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white {{ !$notification->isRead() ? 'font-semibold' : '' }}">
                            {{ $notification->title }}
                        </p>
                        @if(!$notification->isRead())
                            <span class="shrink-0 inline-block h-2 w-2 rounded-full bg-accent-500 mt-1.5" title="{{ __('Unread') }}"></span>
                        @endif
                    </div>
                    @if($notification->message)
                        <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300 line-clamp-2">{{ $notification->message }}</p>
                    @endif
                    <div class="mt-1.5 flex flex-wrap items-center gap-3">
                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ $notification->created_at->diffForHumans() }}</span>
                        @if(isset($notification->data['site_name']))
                            <span class="text-xs text-gray-400 dark:text-gray-500">
                                &middot; {{ $notification->data['site_name'] }}
                            </span>
                        @endif
                        @if(isset($notification->data['event']))
                            <x-ui.badge variant="gray">{{ $notification->data['event'] }}</x-ui.badge>
                        @endif
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex shrink-0 items-center gap-1">
                    @if(!$notification->isRead())
                        <button
                            wire:click="markAsRead({{ $notification->id }})"
                            wire:loading.attr="disabled"
                            class="rounded-lg p-1.5 text-gray-400 hover:text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20 dark:hover:text-green-400 transition disabled:opacity-50"
                            title="{{ __('Mark as read') }}"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-4 py-12 text-center">
                <svg class="mx-auto h-10 w-10 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="mt-3 text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('No notifications found') }}</p>
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    @if($search || $filter !== 'all' || $typeFilter !== 'all')
                        {{ __('Try adjusting your filters.') }}
                    @else
                        {{ __("You're all caught up.") }}
                    @endif
                </p>
            </div>
        @endforelse
    </x-ui.card>

    {{-- Pagination --}}
    @if($notifications->hasPages())
        <div class="mt-4">
            {{ $notifications->links() }}
        </div>
    @endif
</div>
