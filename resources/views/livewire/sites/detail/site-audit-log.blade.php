<div>
    {{-- Flash Messages --}}
    @if(session('sync-dispatched'))
        <div class="mb-4 rounded-lg bg-blue-50 p-3 text-sm text-blue-700">{{ session('sync-dispatched') }}</div>
    @endif

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">Audit Log</h2>
        <div class="flex gap-2">
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
    <div class="mb-6">
        <x-ui.card class="!p-4">
            <div class="flex flex-col gap-3 sm:flex-row">
                <div class="flex-1">
                    <input type="text"
                           wire:model.live.debounce.300ms="search"
                           placeholder="Search by user, object, or IP..."
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder:text-gray-400 focus:border-purple-500 focus:ring-purple-500">
                </div>
                <div>
                    <select wire:model.live="userFilter"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500">
                        <option value="all">All Users</option>
                        @foreach($this->users as $user)
                            <option value="{{ $user }}">{{ $user }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <select wire:model.live="actionFilter"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500">
                        <option value="all">All Actions</option>
                        @foreach($this->actionTypes as $type)
                            <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-ui.card>
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
