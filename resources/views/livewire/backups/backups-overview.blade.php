<div>
    {{-- Header with Add Button --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="{{ __('Backups') }}" subtitle="{{ __('Manage site backups and restore points') }}" />
        <x-ui.button wire:click="backupAllSites" wire:loading.attr="disabled" wire:confirm="{{ __('This will queue backups for all connected sites with an active backup configuration. Continue?') }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8" /></svg>
            <span wire:loading.remove wire:target="backupAllSites">{{ __('Backup All Sites') }}</span>
            <span wire:loading wire:target="backupAllSites">{{ __('Queuing...') }}</span>
        </x-ui.button>
    </div>

    <x-ui.flash-alert type="success" key="backup-success" />
    <x-ui.flash-alert type="error" key="backup-error" />

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Total Backups') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $this->stats['completed'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Completed') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">{{ $this->stats['failed'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Failed') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-accent-600">{{ $this->stats['in_progress'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('In Progress') }}</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'completed' => __('Completed'), 'failed' => __('Failed'), 'in_progress' => __('In Progress')]"
            :selected="$filter"
            wire="filter"
        />
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search by site name...') }}"
            class="w-full sm:ml-auto sm:w-64"
        />
    </div>

    {{-- Backups Table --}}
    <x-ui.card class="overflow-hidden !p-0"
        x-data="{
            selected: [],
            get allSelected() {
                return this.selected.length === {{ $backups->count() }} && this.selected.length > 0;
            },
            toggleAll() {
                if (this.allSelected) {
                    this.selected = [];
                } else {
                    this.selected = [{{ $backups->pluck('id')->implode(',') }}];
                }
            }
        }"
    >
        {{-- Bulk action bar (desktop only — bulk selection is impractical on touch) --}}
        <div x-show="selected.length > 0" x-cloak class="hidden md:flex items-center gap-3 border-b border-gray-200 bg-accent-50/50 px-5 py-2.5">
            <span class="text-sm font-medium text-accent-700" x-text="selected.length + ' {{ __('selected') }}'"></span>
            <button
                @click="if (confirm('{{ __('Delete') }} ' + selected.length + ' {{ __('backup(s)? This cannot be undone.') }}')) { $wire.bulkDelete(selected).then(() => selected = []) }"
                class="inline-flex items-center rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition"
            >
                <x-icons.x class="mr-1 h-3.5 w-3.5" />
                {{ __('Delete Selected') }}
            </button>
        </div>

        @if($backups->isEmpty())
            <p class="text-sm text-gray-500 text-center py-8">{{ __('No backups found.') }}</p>
        @else
            {{-- Mobile cards --}}
            <div class="md:hidden divide-y divide-gray-200">
                @foreach($backups as $backup)
                    <div class="p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                @if($backup->site)
                                    <a href="{{ route('sites.backups', $backup->site) }}" class="font-medium text-accent-600 hover:text-accent-800 truncate block">
                                        {{ $backup->site->name }}
                                    </a>
                                    <p class="text-xs text-gray-400 truncate">{{ $backup->site->domain }}</p>
                                @else
                                    <span class="text-sm text-gray-400">{{ __('Deleted site') }}</span>
                                @endif
                            </div>
                            <button wire:click="deleteBackup({{ $backup->id }})"
                                    wire:confirm="{{ __('Delete this backup? This cannot be undone.') }}"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition disabled:opacity-50 shrink-0"
                                    title="{{ __('Delete') }}">
                                <x-icons.x class="h-3.5 w-3.5" />
                            </button>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <x-ui.badge variant="blue">{{ ucfirst($backup->type) }}</x-ui.badge>
                            <span class="text-xs text-gray-600">{{ $backup->file_size_formatted }}</span>
                            <x-ui.badge :variant="$backup->status_color">{{ $backup->status->label() }}</x-ui.badge>
                            @if($backup->is_locked)
                                <x-ui.badge variant="purple">{{ __('Locked') }}</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500">
                            {{ $backup->created_at->format('M d, Y H:i') }} · {{ ucfirst(str_replace('_', ' ', $backup->trigger)) }}
                        </p>
                        @if($backup->storageDestination)
                            <p class="mt-0.5 text-xs text-gray-400">{{ __('Storage') }}: {{ $backup->storageDestination->name }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="w-10 px-3 py-2">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll()"
                                       class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Site') }}</th>
                            <x-ui.sortable-th column="created_at" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Date') }}</x-ui.sortable-th>
                            <x-ui.sortable-th column="type" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Type') }}</x-ui.sortable-th>
                            <x-ui.sortable-th column="file_size" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Size') }}</x-ui.sortable-th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Storage') }}</th>
                            <x-ui.sortable-th column="status" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Status') }}</x-ui.sortable-th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($backups as $backup)
                            <tr class="hover:bg-gray-50" :class="selected.includes({{ $backup->id }}) && 'bg-accent-50/50'">
                                <td class="px-3 py-3">
                                    <input type="checkbox" value="{{ $backup->id }}" x-model.number="selected"
                                           class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    @if($backup->site)
                                        <a href="{{ route('sites.backups', $backup->site) }}" class="text-accent-600 hover:text-accent-800 font-medium">
                                            {{ $backup->site->name }}
                                        </a>
                                        <div class="text-xs text-gray-400">{{ $backup->site->domain }}</div>
                                    @else
                                        <span class="text-gray-400">{{ __('Deleted site') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-900">
                                    {{ $backup->created_at->format('M d, Y H:i') }}
                                    <div class="text-xs text-gray-400">{{ ucfirst(str_replace('_', ' ', $backup->trigger)) }}</div>
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">
                                    {{ ucfirst($backup->type) }}
                                    @if($backup->format === 'multipart-v3')
                                        <x-ui.badge variant="blue" class="ml-1" title="{{ __('Streaming multipart upload') }}">⚡</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->file_size_formatted }}</td>
                                <td class="px-3 py-3 text-sm text-gray-700">
                                    {{ $backup->storageDestination?->name ?? '—' }}
                                    @php
                                        $replicaCount = is_array($backup->replicas) ? count($backup->replicas) : 0;
                                        $expectedReplicas = ($backup->site?->backupConfig?->secondary_storage_destination_id) ? 2 : 1;
                                    @endphp
                                    @if($replicaCount >= 2)
                                        <x-ui.badge variant="green" class="ml-1" title="{{ __(':n replicas', ['n' => $replicaCount]) }}">{{ $replicaCount }}×</x-ui.badge>
                                    @elseif($expectedReplicas === 2 && $backup->status->value === 'completed')
                                        <x-ui.badge variant="yellow" class="ml-1" title="{{ __('Secondary replica missing') }}">1/2</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <x-ui.badge :variant="$backup->status_color">{{ $backup->status->label() }}</x-ui.badge>
                                    @if($backup->is_locked)
                                        <x-ui.badge variant="purple" class="ml-1">{{ __('Locked') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <button wire:click="deleteBackup({{ $backup->id }})"
                                            wire:confirm="{{ __('Delete this backup? This cannot be undone.') }}"
                                            wire:loading.attr="disabled"
                                            class="inline-flex items-center rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition disabled:opacity-50"
                                            title="{{ __('Delete') }}">
                                        <x-icons.x class="h-3.5 w-3.5" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($backups->hasPages())
                <div class="border-t border-gray-200 px-5 py-3">
                    {{ $backups->links() }}
                </div>
            @endif
        @endif
    </x-ui.card>
</div>
