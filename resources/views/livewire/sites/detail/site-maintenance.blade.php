<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <x-ui.page-header title="Maintenance Windows" subtitle="Schedule maintenance to pause monitoring during planned downtime" />
        <x-ui.button wire:click="openCreateModal">
            <x-icons.plus class="mr-1.5 h-4 w-4" />
            Schedule Maintenance
        </x-ui.button>
    </div>

    {{-- Active Maintenance Banner --}}
    @if($this->activeMaintenance)
        <div class="mb-6 rounded-lg border border-yellow-200 bg-yellow-50 p-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-yellow-100">
                        <x-icons.wrench class="h-5 w-5 text-yellow-600" />
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-yellow-800">Maintenance Active: {{ $this->activeMaintenance->title }}</p>
                        <p class="text-xs text-yellow-600">
                            Started {{ $this->activeMaintenance->actual_start_at->diffForHumans() }}
                            &middot; Ends {{ $this->activeMaintenance->scheduled_end_at->format('M d, H:i') }}
                        </p>
                        @php
                            $paused = collect(['uptime', 'ssl', 'performance', 'backups', 'links'])
                                ->filter(fn ($t) => $this->activeMaintenance->{"pause_{$t}"});
                        @endphp
                        @if($paused->isNotEmpty())
                            <div class="mt-1 flex gap-1">
                                @foreach($paused as $type)
                                    <span class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">{{ ucfirst($type) }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
                <x-ui.button variant="secondary" wire:click="endNow" wire:confirm="End this maintenance window now?">
                    End Now
                </x-ui.button>
            </div>
        </div>
    @endif

    <div class="space-y-6">
        {{-- Upcoming Windows --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Upcoming</h3>
            @if($this->upcomingWindows->isEmpty())
                <p class="py-4 text-center text-sm text-gray-500">No upcoming maintenance windows.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($this->upcomingWindows as $window)
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $window->title }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $window->scheduled_start_at->format('M d, H:i') }} — {{ $window->scheduled_end_at->format('M d, H:i') }}
                                </p>
                                @php
                                    $paused = collect(['uptime', 'ssl', 'performance', 'backups', 'links'])
                                        ->filter(fn ($t) => $window->{"pause_{$t}"});
                                @endphp
                                @if($paused->isNotEmpty())
                                    <div class="mt-1 flex gap-1">
                                        @foreach($paused as $type)
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ ucfirst($type) }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                <button wire:click="startNow({{ $window->id }})" wire:confirm="Start this maintenance window now?" class="rounded-lg px-2 py-1 text-xs text-green-600 hover:bg-green-50">
                                    Start Now
                                </button>
                                <button wire:click="openEditModal({{ $window->id }})" class="rounded-lg px-2 py-1 text-xs text-gray-500 hover:bg-gray-100">
                                    Edit
                                </button>
                                <button wire:click="cancel({{ $window->id }})" wire:confirm="Cancel this maintenance window?" class="rounded-lg px-2 py-1 text-xs text-red-500 hover:bg-red-50">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        {{-- History --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">History</h3>
            @if($this->pastWindows->isEmpty())
                <p class="py-4 text-center text-sm text-gray-500">No past maintenance windows.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($this->pastWindows as $window)
                        <div class="flex items-center justify-between py-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $window->title }}</p>
                                <div class="flex items-center gap-2 text-xs text-gray-500">
                                    <span>{{ $window->scheduled_start_at->format('M d, H:i') }}</span>
                                    @if($window->status === 'completed')
                                        <x-ui.badge variant="green">Completed</x-ui.badge>
                                        @if($window->actual_start_at && $window->actual_end_at)
                                            <span>{{ (int) $window->actual_start_at->diffInMinutes($window->actual_end_at) }} min</span>
                                        @endif
                                    @else
                                        <x-ui.badge variant="gray">Cancelled</x-ui.badge>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Create/Edit Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="$set('showModal', false)">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold text-gray-900">
                    {{ $editingId ? 'Edit Maintenance Window' : 'Schedule Maintenance' }}
                </h2>

                <form wire:submit="save" class="mt-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Title</label>
                        <x-ui.input wire:model="title" placeholder="e.g. Server upgrade" class="mt-1" />
                        @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Description (optional)</label>
                        <textarea wire:model="description" rows="2" placeholder="Details about this maintenance..."
                            class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-purple-500 focus:ring-1 focus:ring-purple-500"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Start</label>
                            <x-ui.input wire:model="scheduledStartAt" type="datetime-local" class="mt-1" />
                            @error('scheduledStartAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">End</label>
                            <x-ui.input wire:model="scheduledEndAt" type="datetime-local" class="mt-1" />
                            @error('scheduledEndAt') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Pause Monitors</label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="pauseUptime" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="text-sm text-gray-600">Uptime</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="pauseSsl" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="text-sm text-gray-600">SSL</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="pausePerformance" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="text-sm text-gray-600">Performance</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="pauseBackups" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="text-sm text-gray-600">Backups</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="pauseLinks" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="text-sm text-gray-600">Links</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Notifications</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="notifyOnStart" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="text-sm text-gray-600">Notify when maintenance starts</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model="notifyOnEnd" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="text-sm text-gray-600">Notify when maintenance ends</span>
                            </label>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showModal', false)">
                            Cancel
                        </x-ui.button>
                        <x-ui.button type="submit">
                            {{ $editingId ? 'Update' : 'Schedule' }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
