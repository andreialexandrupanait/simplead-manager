<div x-data="{ open: false }"
     @click.outside="open = false"
     @keydown.escape.window="open = false"
     class="{{ $sidebarMode ? 'w-full' : 'relative' }}">

    @if($sidebarMode)
        {{-- Sidebar button style --}}
        <button @click="open = !open"
                @mouseenter="if (!sidebarOpen && window.innerWidth >= 1024) { showSidebarTooltip($el) }"
                @mouseleave="hideSidebarTooltip()"
                class="flex items-center gap-3 px-3 py-1.5 text-sm font-medium text-white/70 hover:text-white hover:bg-sidebar-hover rounded-lg transition-all duration-200 w-full relative"
                :class="sidebarOpen ? '' : 'lg:justify-center lg:px-0'">
            <div class="relative shrink-0">
                <x-icons.bell class="h-4 w-4" aria-hidden="true" />
                @if($this->count > 0)
                    <span class="absolute -top-1 -right-1 flex h-3 min-w-3 items-center justify-center rounded-full bg-red-500 text-[8px] font-bold text-white px-0.5">
                        {{ $this->count > 9 ? '9+' : $this->count }}
                    </span>
                @endif
            </div>
            <span class="whitespace-nowrap transition-all duration-300"
                  :class="sidebarOpen ? '' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
                Notifications
                @if($this->count > 0)
                    <span class="ml-1 text-xs opacity-60">({{ $this->count }})</span>
                @endif
            </span>
        </button>
    @else
        {{-- Header button style --}}
        <button @click="open = !open" aria-label="{{ __('Notifications') }}" class="relative rounded-lg p-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-600 dark:hover:text-gray-300 transition">
            <x-icons.bell class="h-5 w-5" aria-hidden="true" />
            @if($this->count > 0)
                <span class="absolute top-0.5 right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                    {{ $this->count > 9 ? '9+' : $this->count }}
                </span>
            @endif
        </button>
    @endif

    {{-- Dropdown Panel --}}
    <div x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         x-cloak
         @if($sidebarMode)
             class="fixed left-64 bottom-16 w-96 rounded-xl bg-white dark:bg-gray-800 shadow-lg ring-1 ring-gray-950/5 dark:ring-gray-700 z-50"
             :class="sidebarOpen ? 'lg:left-64' : 'lg:left-16'"
         @else
             class="absolute right-0 mt-6 w-[calc(100vw-2rem)] sm:w-96 rounded-xl bg-white dark:bg-gray-800 shadow-lg ring-1 ring-gray-950/5 dark:ring-gray-700 z-50"
         @endif
    >
        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
            @if($this->count > 0)
                <button wire:click="dismissAll" wire:loading.attr="disabled" class="text-xs font-medium text-gray-400 hover:text-gray-600 transition disabled:opacity-50">
                    <span wire:loading.remove wire:target="dismissAll">Mark all as read</span>
                    <span wire:loading wire:target="dismissAll">Clearing...</span>
                </button>
            @endif
        </div>

        {{-- Flash message --}}
        @if(session('message'))
            <div class="mx-4 mt-3 rounded-lg bg-green-50 p-2.5 text-xs font-medium text-green-700 ring-1 ring-green-200">
                {{ session('message') }}
            </div>
        @endif

        {{-- Alert List --}}
        <div class="max-h-96 overflow-y-auto">
            @forelse($this->alerts as $alert)
                <div class="flex items-start gap-3 border-b border-gray-50 px-4 py-3 hover:bg-gray-50/50 transition" wire:key="alert-{{ $alert['key'] }}">
                    {{-- Severity dot + icon --}}
                    <div class="flex-shrink-0 mt-0.5">
                        <div class="relative">
                            <x-dynamic-component
                                :component="'icons.' . $alert['icon']"
                                class="h-5 w-5 {{ $alert['severity'] === 'critical' ? 'text-red-500' : 'text-yellow-500' }}"
                            />
                            <span class="absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full {{ $alert['severity'] === 'critical' ? 'bg-red-500' : 'bg-yellow-400' }}"></span>
                        </div>
                    </div>

                    {{-- Content --}}
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ $alert['title'] }}</p>
                        @if($alert['description'])
                            <p class="mt-0.5 text-xs text-gray-500 line-clamp-2">{{ $alert['description'] }}</p>
                        @endif
                        <div class="mt-1.5 flex items-center gap-2">
                            @if($alert['timestamp'])
                                <span class="text-xs text-gray-400">{{ $alert['timestamp']->diffForHumans() }}</span>
                            @endif
                            @if($alert['url'])
                                <a href="{{ $alert['url'] }}" class="text-xs font-medium text-purple-600 hover:text-purple-800 transition">View</a>
                            @endif
                            @if(str_starts_with($alert['action'] ?? '', 'retry_backup_'))
                                <button
                                    wire:click="retrySiteBackup({{ str_replace('retry_backup_', '', $alert['action']) }})"
                                    class="text-xs font-medium text-red-600 hover:text-red-800 transition"
                                >
                                    Retry
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Mark as read button --}}
                    <button
                        wire:click.stop="dismissAlert('{{ $alert['key'] }}')"
                        class="flex-shrink-0 rounded-md p-1.5 text-gray-400 hover:text-green-600 hover:bg-green-50 transition"
                        title="{{ __('Mark as read') }}"
                        aria-label="{{ __('Mark as read') }}"
                    >
                        <svg class="h-4 w-4" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                    </button>
                </div>
            @empty
                @if($this->notifications->isEmpty())
                    <div class="px-4 py-8 text-center">
                        <svg class="mx-auto h-8 w-8 text-gray-300" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="mt-2 text-sm font-medium text-gray-500">All clear</p>
                        <p class="text-xs text-gray-400">No notifications</p>
                    </div>
                @endif
            @endforelse

            {{-- In-app notifications --}}
            @if($this->notifications->isNotEmpty())
                <div class="border-t border-gray-100 px-4 py-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-500 uppercase">Recent</span>
                        @if($this->unreadCount > 0)
                            <button wire:click="markAllNotificationsRead" class="text-xs text-gray-400 hover:text-gray-600">Mark all read</button>
                        @endif
                    </div>
                </div>
                @foreach($this->notifications as $notif)
                    <div class="flex items-start gap-3 border-b border-gray-50 px-4 py-2.5 {{ !$notif->isRead() ? 'bg-purple-50/30' : '' }}" wire:key="notif-{{ $notif->id }}">
                        <div class="mt-0.5 shrink-0">
                            <span @class([
                                'h-2 w-2 rounded-full inline-block',
                                'bg-red-500' => $notif->type === 'critical' || $notif->type === 'warning',
                                'bg-blue-500' => $notif->type === 'info',
                                'bg-gray-400' => !in_array($notif->type, ['critical', 'warning', 'info']),
                            ])></span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 truncate {{ !$notif->isRead() ? 'font-semibold' : '' }}">{{ $notif->title }}</p>
                            @if($notif->message)
                                <p class="text-xs text-gray-500 line-clamp-2">{{ $notif->message }}</p>
                            @endif
                            <span class="text-xs text-gray-400">{{ $notif->created_at->diffForHumans() }}</span>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            @if(!$notif->isRead())
                                <button wire:click="markAsRead({{ $notif->id }})" class="rounded p-1 text-gray-400 hover:text-green-600 hover:bg-green-50" title="Mark read">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </button>
                            @endif
                            <button wire:click="deleteNotification({{ $notif->id }})" class="rounded p-1 text-gray-400 hover:text-red-600 hover:bg-red-50" title="Delete">
                                <x-icons.x class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</div>
