<div @if($hasRunningJobs) wire:poll.3s="checkJobProgress" @endif>
    <div class="mb-6 flex items-center justify-between">
        {{-- Safe Update Mode Toggle --}}
        <label class="flex items-center gap-2 cursor-pointer">
            <div class="relative">
                <input type="checkbox" wire:model.live="safeUpdateMode" class="sr-only peer">
                <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-600"></div>
            </div>
            <span class="text-sm font-medium text-gray-700">Safe Update Mode</span>
            @if($safeUpdateMode)
                <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            @endif
        </label>

        <x-ui.button variant="secondary" wire:click="syncNow" wire:loading.attr="disabled">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span wire:loading.remove wire:target="syncNow">Sync Now</span>
            <span wire:loading wire:target="syncNow">Syncing...</span>
        </x-ui.button>
    </div>

    {{-- Flash messages --}}
    <x-ui.flash-alert type="success" key="update-success" />
    <x-ui.flash-alert type="error" key="update-error" />

    {{-- Job Progress --}}
    <x-ui.job-progress job-key="sync" :jobs="$trackedJobs" title="Syncing site data..." />

    {{-- Safe Updates in Progress --}}
    @if($this->activeSafeUpdates->count() > 0)
        <x-ui.card :padding="false" class="mb-6">
            <div class="border-b p-4">
                <h2 class="text-lg font-semibold text-gray-900">Safe Updates in Progress</h2>
            </div>
            <div class="divide-y">
                @foreach($this->activeSafeUpdates as $safeUpdate)
                    <div class="px-4 py-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-gray-900">{{ $safeUpdate->name }}</span>
                            <x-ui.badge :variant="match($safeUpdate->status) {
                                'pending' => 'gray',
                                'backing_up' => 'yellow',
                                'updating' => 'purple',
                                'health_checking' => 'purple',
                                'rolling_back' => 'yellow',
                                'completed' => 'green',
                                'failed' => 'red',
                                default => 'gray',
                            }">
                                {{ ucfirst(str_replace('_', ' ', $safeUpdate->status)) }}
                            </x-ui.badge>
                        </div>
                        <div class="flex items-center gap-1 text-xs text-gray-500">
                            @foreach(['pending', 'backing_up', 'updating', 'health_checking', 'completed'] as $step)
                                @php
                                    $steps = ['pending', 'backing_up', 'updating', 'health_checking', 'completed'];
                                    $currentIndex = array_search($safeUpdate->status, $steps);
                                    $stepIndex = array_search($step, $steps);
                                    $isActive = $currentIndex !== false && $stepIndex <= $currentIndex;
                                @endphp
                                <div class="flex items-center gap-1">
                                    <div class="h-2 w-2 rounded-full {{ $isActive ? 'bg-purple-500' : 'bg-gray-200' }}"></div>
                                    <span class="{{ $isActive ? 'text-purple-600' : '' }}">{{ ucfirst(str_replace('_', ' ', $step)) }}</span>
                                </div>
                                @if(!$loop->last)
                                    <div class="h-px w-4 {{ $isActive ? 'bg-purple-300' : 'bg-gray-200' }}"></div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    {{-- Available Updates --}}
    <x-ui.card :padding="false">
        <div class="flex items-center justify-between border-b p-4">
            <h2 class="text-lg font-semibold text-gray-900">
                Available Updates
                @if($this->availableUpdates->count() > 0)
                    <x-ui.badge variant="yellow" class="ml-1">{{ $this->availableUpdates->count() }}</x-ui.badge>
                @endif
            </h2>
            @if($this->availableUpdates->count() > 1 && !$safeUpdateMode)
                <x-ui.button wire:click="updateAll" wire:loading.attr="disabled" wire:target="updateAll">
                    <span wire:loading.remove wire:target="updateAll">Update All</span>
                    <span wire:loading wire:target="updateAll">Updating...</span>
                </x-ui.button>
            @endif
        </div>

        @if($this->availableUpdates->count() > 0)
            <div class="divide-y">
                @foreach($this->availableUpdates as $update)
                    <div class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                        <div class="flex items-center gap-3">
                            {{-- Type icon --}}
                            @if($update['type'] === 'core')
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100">
                                    <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                                    </svg>
                                </div>
                            @elseif($update['type'] === 'plugin')
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-purple-100">
                                    <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                    </svg>
                                </div>
                            @else
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-green-100">
                                    <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif

                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-gray-900">{{ $update['name'] }}</span>
                                    <x-ui.badge :variant="$update['type'] === 'core' ? 'purple' : ($update['type'] === 'plugin' ? 'purple' : 'green')">
                                        {{ ucfirst($update['type']) }}
                                    </x-ui.badge>
                                </div>
                                <span class="text-xs text-gray-500">
                                    {{ $update['from_version'] ?? '?' }} &rarr; {{ $update['to_version'] ?? '?' }}
                                </span>
                            </div>
                        </div>

                        <div>
                            @if($safeUpdateMode)
                                {{-- Safe Update buttons --}}
                                @if($update['type'] === 'core')
                                    <x-ui.button size="sm" variant="secondary" class="!bg-purple-50 !text-purple-700 !border-purple-200 hover:!bg-purple-100" wire:click="safeUpdateCore" wire:loading.attr="disabled" wire:target="safeUpdateCore">
                                        <svg class="h-3.5 w-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                        <span wire:loading.remove wire:target="safeUpdateCore">Safe Update</span>
                                        <span wire:loading wire:target="safeUpdateCore">Starting...</span>
                                    </x-ui.button>
                                @elseif($update['type'] === 'plugin')
                                    <x-ui.button size="sm" variant="secondary" class="!bg-purple-50 !text-purple-700 !border-purple-200 hover:!bg-purple-100" wire:click="safeUpdatePlugin({{ $update['model_id'] }})" wire:loading.attr="disabled">
                                        <svg class="h-3.5 w-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                        Safe Update
                                    </x-ui.button>
                                @else
                                    <x-ui.button size="sm" variant="secondary" class="!bg-purple-50 !text-purple-700 !border-purple-200 hover:!bg-purple-100" wire:click="safeUpdateTheme({{ $update['model_id'] }})" wire:loading.attr="disabled">
                                        <svg class="h-3.5 w-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                        Safe Update
                                    </x-ui.button>
                                @endif
                            @else
                                {{-- Normal Update buttons --}}
                                @if($update['type'] === 'core')
                                    <x-ui.button size="sm" wire:click="updateCore" wire:loading.attr="disabled" wire:target="updateCore">
                                        <span wire:loading.remove wire:target="updateCore">Update</span>
                                        <span wire:loading wire:target="updateCore">Updating...</span>
                                    </x-ui.button>
                                @elseif($update['type'] === 'plugin')
                                    <x-ui.button size="sm" wire:click="updatePlugin({{ $update['model_id'] }})" wire:loading.attr="disabled">
                                        Update
                                    </x-ui.button>
                                @else
                                    <x-ui.button size="sm" wire:click="updateTheme({{ $update['model_id'] }})" wire:loading.attr="disabled">
                                        Update
                                    </x-ui.button>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-8 text-center">
                <div class="mb-3 inline-flex rounded-full bg-green-100 p-3">
                    <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-gray-900">Everything is up to date</p>
                <p class="mt-1 text-xs text-gray-500">All plugins, themes, and WordPress core are running the latest versions.</p>
            </div>
        @endif
    </x-ui.card>

    {{-- Rollback Points --}}
    @if($this->rollbackPoints->count() > 0)
        <x-ui.card :padding="false" class="mt-6">
            <div class="border-b p-4">
                <h2 class="text-lg font-semibold text-gray-900">Rollback Points</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Version</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($this->rollbackPoints as $point)
                            <tr class="hover:bg-gray-50">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <x-ui.badge :variant="match($point->type) { 'core' => 'purple', 'plugin' => 'purple', 'theme' => 'green', default => 'gray' }">
                                        {{ ucfirst($point->type) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-gray-900">{{ $point->slug }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    {{ $point->to_version }} &rarr; {{ $point->from_version }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    {{ $point->created_at->diffForHumans() }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <x-ui.button size="sm" variant="secondary" wire:click="openRollbackModal({{ $point->id }})">
                                        Rollback
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    {{-- Update History --}}
    <x-ui.card :padding="false" class="mt-6">
        <div class="border-b p-4">
            <h2 class="text-lg font-semibold text-gray-900">Update History</h2>
        </div>

        @if($updateHistory->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Version</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($updateHistory as $log)
                            <tr class="hover:bg-gray-50">
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    {{ $log->performed_at->format('M d, Y H:i') }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <x-ui.badge :variant="match($log->type) { 'core' => 'purple', 'plugin' => 'purple', 'theme' => 'green', default => 'gray' }">
                                        {{ ucfirst($log->type) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-gray-900">{{ $log->name }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    {{ $log->from_version ?? '?' }} &rarr; {{ $log->to_version ?? '?' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if($log->success)
                                        <x-ui.badge variant="green">Success</x-ui.badge>
                                    @else
                                        <span class="group relative">
                                            <x-ui.badge variant="red">Failed</x-ui.badge>
                                            @if($log->error_message)
                                                <span class="absolute bottom-full left-0 mb-1 hidden w-48 rounded bg-gray-900 p-2 text-xs text-white group-hover:block">
                                                    {{ $log->error_message }}
                                                </span>
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    {{ $log->user?->name ?? 'System' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t p-4">
                {{ $updateHistory->links() }}
            </div>
        @else
            <div class="p-8 text-center text-sm text-gray-500">
                No update history yet.
            </div>
        @endif
    </x-ui.card>

    {{-- Rollback Confirmation Modal --}}
    <x-ui.modal name="rollback-confirm" maxWidth="md">
        <h3 class="text-lg font-semibold text-gray-900">Confirm Rollback</h3>
        @php
            $selectedPoint = $rollbackPointId ? $this->rollbackPoints->firstWhere('id', $rollbackPointId) : null;
        @endphp
        @if($selectedPoint)
            <p class="mt-2 text-sm text-gray-600">
                Are you sure you want to rollback <strong>{{ $selectedPoint->slug }}</strong>
                from version <strong>{{ $selectedPoint->to_version }}</strong>
                to <strong>{{ $selectedPoint->from_version }}</strong>?
            </p>
            <div class="mt-3 rounded-lg bg-yellow-50 border border-yellow-200 p-3">
                <p class="text-xs text-yellow-700">
                    This will revert the {{ $selectedPoint->type }} to its previous version. A site sync will be triggered after the rollback.
                </p>
            </div>
        @endif
        <div class="mt-4 flex justify-end gap-3">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal-rollback-confirm')">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="executeRollback">Confirm Rollback</x-ui.button>
        </div>
    </x-ui.modal>
</div>
