<div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative">
    {{-- Bell Button --}}
    <button @click="open = !open" class="relative text-gray-400 hover:text-gray-600 transition">
        <x-icons.bell class="h-5 w-5" />
        @if($this->count > 0)
            <span class="absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                {{ $this->count > 9 ? '9+' : $this->count }}
            </span>
        @endif
    </button>

    {{-- Dropdown Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="absolute right-0 mt-2 w-[calc(100vw-2rem)] sm:w-96 rounded-xl bg-white shadow-lg ring-1 ring-gray-950/5 z-50"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-gray-900">Notifications</h3>
            @if($this->count > 0)
                <button wire:click="dismissAll" class="text-xs font-medium text-gray-400 hover:text-gray-600 transition">
                    Dismiss all
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
                            @if(($alert['action'] ?? null) === 'retry_backups')
                                <button
                                    wire:click="retryFailedBackups"
                                    class="text-xs font-medium text-red-600 hover:text-red-800 transition"
                                >
                                    Retry All
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Dismiss button --}}
                    <button
                        wire:click="dismissAlert('{{ $alert['key'] }}')"
                        class="flex-shrink-0 rounded p-1 text-gray-300 hover:text-gray-500 hover:bg-gray-100 transition"
                        title="Dismiss"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            @empty
                <div class="px-4 py-8 text-center">
                    <svg class="mx-auto h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="mt-2 text-sm font-medium text-gray-500">All clear</p>
                    <p class="text-xs text-gray-400">No issues detected</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
