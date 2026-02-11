<div>
    {{-- Flash Message --}}
    <x-ui.flash-alert type="success" key="message" />

    {{-- Header with Actions --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <x-ui.page-header
            title="Dashboard"
            subtitle="Customizable widget-based overview"
        />

        <div class="flex items-center gap-2">
            {{-- Edit Mode Toggle --}}
            <button
                wire:click="toggleEditMode"
                @click="$refs.grid.toggleEditMode()"
                class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition {{ $isEditMode ? 'border-purple-600 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    @if($isEditMode)
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    @else
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    @endif
                </svg>
                {{ $isEditMode ? 'Done Editing' : 'Edit Layout' }}
            </button>

            {{-- Add Widget Button --}}
            @if(count($this->availableWidgetTypes) > 0)
                <button
                    wire:click="openAddWidgetModal"
                    class="inline-flex items-center gap-2 rounded-lg border border-purple-600 bg-purple-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-purple-700"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Add Widget
                </button>
            @endif

            {{-- Reset Menu --}}
            <div x-data="dropdown({ alignRight: true })">
                <button
                    @click="toggle()"
                    x-ref="trigger"
                    class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/>
                    </svg>
                </button>

                <div
                    x-show="open"
                    @click.away="open = false"
                    x-ref="panel"
                    x-init="panelEl = $el"
                    class="fixed z-50 w-48 rounded-lg border border-gray-200 bg-white shadow-lg"
                    style="display: none;"
                >
                    <button
                        wire:click="openResetModal"
                        @click="open = false"
                        class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-700 hover:bg-red-50"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Reset to Defaults
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Widget Grid --}}
    <div
        x-data="widgetGrid(@js($this->widgets->map(fn($w) => ['id' => $w->id, 'x' => $w->grid_x, 'y' => $w->grid_y, 'w' => $w->grid_w, 'h' => $w->grid_h])->toArray()))"
        x-ref="grid"
        class="relative"
    >
        <div class="grid-stack" x-ref="gridContainer">
            @foreach($this->widgets as $widget)
                <div
                    class="grid-stack-item"
                    data-widget-id="{{ $widget->id }}"
                    gs-x="{{ $widget->grid_x }}"
                    gs-y="{{ $widget->grid_y }}"
                    gs-w="{{ $widget->grid_w }}"
                    gs-h="{{ $widget->grid_h }}"
                >
                    <div class="grid-stack-item-content">
                        @livewire('dashboard.widgets.' . str_replace('_', '-', $widget->widget_type), ['widget' => $widget], key('widget-' . $widget->id))
                    </div>
                </div>
            @endforeach
        </div>

        @if($this->widgets->isEmpty())
            <div class="flex min-h-[400px] items-center justify-center">
                <div class="text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">No widgets</h3>
                    <p class="mt-1 text-sm text-gray-500">Get started by adding your first widget.</p>
                    <div class="mt-6">
                        <button
                            wire:click="openAddWidgetModal"
                            class="inline-flex items-center gap-2 rounded-lg border border-purple-600 bg-purple-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-purple-700"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            Add Widget
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Add Widget Modal --}}
    @if($showAddWidgetModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" wire:click.self="closeAddWidgetModal">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-900/50 transition-opacity"></div>

                <div class="relative w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Add Widget</h3>
                        <button wire:click="closeAddWidgetModal" class="text-gray-400 hover:text-gray-600">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach($this->availableWidgetTypes as $type)
                            <button
                                wire:click="addWidget('{{ $type }}')"
                                class="flex items-start gap-3 rounded-lg border border-gray-200 p-4 text-left transition hover:border-purple-300 hover:bg-purple-50"
                            >
                                <div class="flex-1">
                                    <div class="font-medium text-gray-900">{{ ucwords(str_replace('_', ' ', $type)) }}</div>
                                    <div class="mt-1 text-sm text-gray-500">
                                        @switch($type)
                                            @case('stats_overview')
                                                Key metrics at a glance
                                                @break
                                            @case('alert_center')
                                                Prioritized alerts with actions
                                                @break
                                            @case('quick_actions')
                                                Fast access to common operations
                                                @break
                                            @case('sites_needing_attention')
                                                Sites requiring action
                                                @break
                                            @case('recent_activity')
                                                Timeline of recent events
                                                @break
                                            @case('health_distribution')
                                                Site health breakdown
                                                @break
                                            @case('backup_status')
                                                Backup coverage metrics
                                                @break
                                            @case('traffic_analytics')
                                                Traffic overview (optional)
                                                @break
                                        @endswitch
                                    </div>
                                </div>
                                <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Reset Confirmation Modal --}}
    @if($showResetModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" wire:click.self="closeResetModal">
            <div class="flex min-h-screen items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-900/50 transition-opacity"></div>

                <div class="relative w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                    <div class="mb-4 flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100">
                            <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-gray-900">Reset to Defaults</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                This will remove all your widgets and restore the default layout. This action cannot be undone.
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end gap-3">
                        <button
                            wire:click="closeResetModal"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            wire:click="resetToDefaults"
                            class="rounded-lg border border-red-600 bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700"
                        >
                            Reset Dashboard
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
