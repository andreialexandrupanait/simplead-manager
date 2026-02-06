<div>
    {{-- Flash Messages --}}
    @if(session('sync-dispatched'))
        <div class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">{{ session('sync-dispatched') }}</div>
    @endif

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Audit Log</h1>
            <p class="mt-1 text-sm text-gray-500">Track user actions and changes made to this site</p>
        </div>
        <div class="flex items-center gap-2">
            <x-ui.button variant="secondary" size="sm" wire:click="exportCsv" wire:loading.attr="disabled">
                <svg class="h-3.5 w-3.5 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="exportCsv" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Export CSV
            </x-ui.button>
            <x-ui.button variant="primary" size="sm" wire:click="syncNow" wire:loading.attr="disabled">
                <svg class="h-4 w-4 animate-spin hidden" wire:loading.class.remove="hidden" wire:target="syncNow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                Sync Now
            </x-ui.button>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        {{-- User filter --}}
        @php
            $userActive = $this->userFilter !== 'all';
            $userLabel = $userActive ? $this->userFilter : 'User';
        @endphp
        <x-ui.dropdown align="left" width="48">
            <x-slot:trigger>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $userActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    <span class="max-w-[8rem] truncate">{{ $userLabel }}</span>
                    <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </x-slot:trigger>
            <button wire:click="$set('userFilter', 'all')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ !$userActive ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                All Users
                @if(!$userActive)
                    <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @endif
            </button>
            @foreach($this->users as $user)
                <button wire:click="$set('userFilter', '{{ $user }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->userFilter === $user ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                    {{ $user }}
                    @if($this->userFilter === $user)
                        <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </button>
            @endforeach
        </x-ui.dropdown>

        {{-- Action filter --}}
        @php
            $actionActive = $this->actionFilter !== 'all';
            $actionLabel = $actionActive ? ucfirst(str_replace('_', ' ', $this->actionFilter)) : 'Action';
        @endphp
        <x-ui.dropdown align="left" width="48">
            <x-slot:trigger>
                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $actionActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span class="max-w-[8rem] truncate">{{ $actionLabel }}</span>
                    <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
            </x-slot:trigger>
            <button wire:click="$set('actionFilter', 'all')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ !$actionActive ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                All Actions
                @if(!$actionActive)
                    <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @endif
            </button>
            @foreach($this->actionTypes as $type)
                <button wire:click="$set('actionFilter', '{{ $type }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->actionFilter === $type ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                    {{ ucfirst(str_replace('_', ' ', $type)) }}
                    @if($this->actionFilter === $type)
                        <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </button>
            @endforeach
        </x-ui.dropdown>

        {{-- Search --}}
        <input type="text"
               wire:model.live.debounce.300ms="search"
               placeholder="Search by user, object, or IP..."
               class="ml-auto w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder:text-gray-400 focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500">
    </div>

    {{-- Logs Table --}}
    <x-ui.card>
        @if($this->logs->count() > 0)
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head>
                        <x-ui.th>Timestamp</x-ui.th>
                        <x-ui.th>User</x-ui.th>
                        <x-ui.th>Action</x-ui.th>
                        <x-ui.th>Object</x-ui.th>
                        <x-ui.th>IP Address</x-ui.th>
                        <x-ui.th></x-ui.th>
                    </x-slot:head>
                    @foreach($this->logs as $log)
                        <tr x-data="{ expanded: false }">
                            <x-ui.td class="whitespace-nowrap">
                                {{ $log->action_at?->format('M d, Y H:i:s') ?? '—' }}
                            </x-ui.td>
                            <x-ui.td>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-900">{{ $log->wp_username ?? '—' }}</span>
                                    @if($log->user_role)
                                        <x-ui.badge variant="gray">{{ $log->user_role }}</x-ui.badge>
                                    @endif
                                </div>
                            </x-ui.td>
                            <x-ui.td>
                                <x-ui.badge :variant="$log->action_color">
                                    {{ $log->action_label }}
                                </x-ui.badge>
                            </x-ui.td>
                            <x-ui.td>
                                @if($log->object_type)
                                    <span class="text-xs text-gray-400">{{ ucfirst($log->object_type) }}:</span>
                                @endif
                                {{ $log->object_title ?? $log->object_id ?? '—' }}
                            </x-ui.td>
                            <x-ui.td class="font-mono text-xs">
                                {{ $log->ip_address ?? '—' }}
                            </x-ui.td>
                            <x-ui.td>
                                @if($log->old_value || $log->new_value)
                                    <button @click="expanded = !expanded" class="text-purple-600 hover:text-purple-700 text-xs">
                                        <span x-text="expanded ? 'Hide' : 'Details'"></span>
                                    </button>
                                @endif
                            </x-ui.td>
                        </tr>
                        @if($log->old_value || $log->new_value)
                            <tr x-show="expanded" x-collapse>
                                <td colspan="6" class="bg-gray-50 px-6 py-3">
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        @if($log->old_value)
                                            <div>
                                                <p class="mb-1 text-xs font-medium text-gray-500">Previous Value</p>
                                                <pre class="max-h-40 overflow-auto rounded bg-white p-2 text-xs text-gray-700">{{ json_encode($log->old_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </div>
                                        @endif
                                        @if($log->new_value)
                                            <div>
                                                <p class="mb-1 text-xs font-medium text-gray-500">New Value</p>
                                                <pre class="max-h-40 overflow-auto rounded bg-white p-2 text-xs text-gray-700">{{ json_encode($log->new_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </div>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </x-ui.table>
            </div>

            <div class="mt-4">
                {{ $this->logs->links() }}
            </div>
        @else
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <x-icons.file-search class="h-12 w-12 text-gray-300 mb-3" />
                <p class="text-sm font-medium text-gray-900">No audit logs yet</p>
                <p class="text-xs text-gray-500 mt-1">Click "Sync Now" to fetch audit logs from WordPress.</p>
            </div>
        @endif
    </x-ui.card>
</div>
