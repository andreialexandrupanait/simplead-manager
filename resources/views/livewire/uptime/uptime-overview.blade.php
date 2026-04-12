<div>
    {{-- Flash Message --}}
    <x-ui.flash-alert type="success" key="message" />

    {{-- Header with Add Button --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header :title="__('Uptime Monitoring')" :subtitle="__('Track site availability and response times')" />
        <div class="flex items-center gap-3">
            @if($this->sitesWithoutMonitorCount > 0)
                <x-ui.button variant="secondary" wire:click="addMonitorsForAllSites" wire:confirm="{{ __('Create uptime monitors for :count site(s) that don\'t have one?', ['count' => $this->sitesWithoutMonitorCount]) }}">
                    <span wire:loading.remove wire:target="addMonitorsForAllSites">{{ __('Add Monitors for All Sites (:count)', ['count' => $this->sitesWithoutMonitorCount]) }}</span>
                    <span wire:loading wire:target="addMonitorsForAllSites">{{ __('Creating Monitors...') }}</span>
                </x-ui.button>
            @endif
            <x-ui.button wire:click="$dispatch('open-configure-monitor')">
                <x-icons.plus class="mr-1.5 h-4 w-4" />
                {{ __('Add Monitor') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <x-ui.card>
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Total') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ $this->counts['total'] }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Up') }}</p>
            <p class="mt-1 text-2xl font-bold text-green-600">{{ $this->counts['up'] }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Down') }}</p>
            <p class="mt-1 text-2xl font-bold text-red-600">{{ $this->counts['down'] }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Degraded') }}</p>
            <p class="mt-1 text-2xl font-bold text-yellow-600">{{ $this->counts['degraded'] }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">{{ __('Paused') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-400">{{ $this->counts['paused'] }}</p>
        </x-ui.card>
    </div>

    {{-- Filters & Search --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'up' => __('Up'), 'down' => __('Down'), 'degraded' => __('Degraded'), 'paused' => __('Paused')]"
            :selected="$filter"
            wire="filter"
        />
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search monitors...') }}"
            class="w-full sm:ml-auto sm:w-64"
        />
    </div>

    {{-- Monitor list --}}
    @if($monitors->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                :title="__('No monitors found')"
                :description="$search || $filter !== 'all' ? __('Try adjusting your filters.') : __('Add a monitor to start tracking uptime.')"
                icon="activity"
            >
                <x-slot:action>
                    @if(!$search && $filter === 'all')
                        <x-ui.button wire:click="$dispatch('open-configure-monitor')">
                            <x-icons.plus class="mr-1.5 h-4 w-4" />
                            {{ __('Add Monitor') }}
                        </x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="space-y-3">
            @foreach($monitors as $monitor)
                <x-ui.card class="hover:ring-accent-200 transition">
                    <div class="flex items-center gap-4">
                        {{-- Status indicator --}}
                        <div class="flex-shrink-0">
                            @php
                                $stateColor = match($monitor->current_state) {
                                    'up' => 'bg-green-500',
                                    'down' => 'bg-red-500',
                                    'degraded' => 'bg-yellow-500',
                                    default => 'bg-gray-400',
                                };
                                $isPaused = $monitor->status === 'paused';
                            @endphp
                            <span class="relative flex h-3 w-3">
                                @if(!$isPaused && $monitor->current_state === 'up')
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75"></span>
                                @endif
                                <span class="relative inline-flex h-3 w-3 rounded-full {{ $isPaused ? 'bg-gray-300' : $stateColor }}"></span>
                            </span>
                        </div>

                        {{-- Site info --}}
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('sites.uptime', $monitor->site) }}" class="truncate text-sm font-semibold text-gray-900 hover:text-accent-600 transition">
                                    {{ $monitor->site->name }}
                                </a>
                                @if($isPaused)
                                    <x-ui.badge variant="gray">{{ __('Paused') }}</x-ui.badge>
                                @endif
                                @if($monitor->isInMaintenanceWindow())
                                    <x-ui.badge variant="warning" title="{{ $monitor->maintenance_reason }}">{{ __('Maintenance') }}</x-ui.badge>
                                @endif
                            </div>
                            <p class="truncate text-xs text-gray-500">{{ $monitor->url }}</p>
                        </div>

                        {{-- Uptime % --}}
                        <div class="hidden text-right sm:block">
                            <p class="text-sm font-semibold {{ $monitor->uptime_30d >= 99.5 ? 'text-green-600' : ($monitor->uptime_30d >= 95 ? 'text-yellow-600' : 'text-red-600') }}">
                                {{ $monitor->uptime_30d !== null ? number_format($monitor->uptime_30d, 2) . '%' : '—' }}
                            </p>
                            <p class="text-xs text-gray-400">{{ __('30d uptime') }}</p>
                        </div>

                        {{-- Response time --}}
                        <div class="hidden text-right sm:block">
                            <p class="text-sm font-semibold text-gray-900">
                                {{ $monitor->last_response_time ? $monitor->last_response_time . 'ms' : '—' }}
                            </p>
                            <p class="text-xs text-gray-400">{{ __('Response') }}</p>
                        </div>

                        {{-- Last check --}}
                        <div class="hidden text-right md:block">
                            <p class="text-sm text-gray-600">
                                {{ $monitor->last_checked_at ? $monitor->last_checked_at->diffForHumans() : __('Never') }}
                            </p>
                            <p class="text-xs text-gray-400">{{ __('Last check') }}</p>
                        </div>

                        {{-- Actions dropdown --}}
                        <x-ui.dropdown align="right" width="48">
                            <x-slot:trigger>
                                <button class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                    </svg>
                                </button>
                            </x-slot:trigger>
                            <button wire:click="$dispatch('open-configure-monitor', { monitorId: {{ $monitor->id }} })"
                                    class="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                {{ __('Edit') }}
                            </button>
                            <button wire:click="testMonitor({{ $monitor->id }})"
                                    wire:loading.attr="disabled"
                                    class="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                                {{ __('Test Now') }}
                            </button>
                            @if($isPaused)
                                <button wire:click="resumeMonitor({{ $monitor->id }})"
                                        wire:loading.attr="disabled"
                                        class="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                                    {{ __('Resume') }}
                                </button>
                            @else
                                <button wire:click="pauseMonitor({{ $monitor->id }})"
                                        wire:loading.attr="disabled"
                                        class="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                                    {{ __('Pause') }}
                                </button>
                            @endif
                            @if($monitor->isInMaintenanceWindow())
                                <button wire:click="clearMaintenanceWindow({{ $monitor->id }})"
                                        class="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                    {{ __('Clear Maintenance') }}
                                </button>
                            @else
                                <button wire:click="openMaintenanceModal({{ $monitor->id }})"
                                        class="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                    {{ __('Schedule Maintenance') }}
                                </button>
                            @endif
                            <button wire:click="deleteMonitor({{ $monitor->id }})"
                                    wire:confirm="{{ __('Are you sure you want to delete this monitor?') }}"
                                    wire:loading.attr="disabled"
                                    class="w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50 disabled:opacity-50">
                                {{ __('Delete') }}
                            </button>
                        </x-ui.dropdown>
                    </div>

                    {{-- Uptime bar --}}
                    @if(!$isPaused)
                        <div class="mt-3">
                            <livewire:components.uptime-bar :monitor="$monitor" :key="'bar-'.$monitor->id" />
                        </div>
                    @endif
                </x-ui.card>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $monitors->links() }}
        </div>
    @endif

    {{-- Configure Monitor Modal --}}
    <livewire:uptime.configure-monitor />

    {{-- Maintenance Window Modal --}}
    <x-ui.modal name="maintenance-window">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Schedule Maintenance Window') }}</h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('Uptime checks will be skipped during this window.') }}</p>

        <div class="mt-4 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Start') }}</label>
                <input type="datetime-local" wire:model="maintenanceStartsAt"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-accent-500 focus:ring-accent-500 sm:text-sm" />
                @error('maintenanceStartsAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('End') }}</label>
                <input type="datetime-local" wire:model="maintenanceEndsAt"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-accent-500 focus:ring-accent-500 sm:text-sm" />
                @error('maintenanceEndsAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Reason (optional)') }}</label>
                <input type="text" wire:model="maintenanceReason" placeholder="{{ __('e.g. Server migration') }}"
                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-accent-500 focus:ring-accent-500 sm:text-sm" />
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal-maintenance-window')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button wire:click="setMaintenanceWindow" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="setMaintenanceWindow">{{ __('Schedule') }}</span>
                <span wire:loading wire:target="setMaintenanceWindow">{{ __('Saving...') }}</span>
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
