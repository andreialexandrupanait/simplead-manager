<div>
    {{-- Header with Add Button --}}
    <div class="mb-6 flex items-center justify-between">
        <x-ui.page-header title="Backups" subtitle="Manage site backups and restore points" />
        <x-ui.button wire:click="backupAllSites" wire:loading.attr="disabled" wire:confirm="This will queue backups for all connected sites with an active backup configuration. Continue?">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8" /></svg>
            <span wire:loading.remove wire:target="backupAllSites">Backup All Sites</span>
            <span wire:loading wire:target="backupAllSites">Queuing...</span>
        </x-ui.button>
    </div>

    <x-ui.flash-alert type="success" key="backup-success" />

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 mt-1">Total Backups</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $this->stats['completed'] }}</p>
                <p class="text-xs text-gray-500 mt-1">Completed</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">{{ $this->stats['failed'] }}</p>
                <p class="text-xs text-gray-500 mt-1">Failed</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-purple-600">{{ $this->stats['in_progress'] }}</p>
                <p class="text-xs text-gray-500 mt-1">In Progress</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => 'All', 'completed' => 'Completed', 'failed' => 'Failed', 'in_progress' => 'In Progress']"
            :selected="$filter"
            wire="filter"
        />
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="Search by site name..."
            class="ml-auto w-64"
        />
    </div>

    {{-- Backups Table --}}
    <x-ui.card>
        @if($backups->isEmpty())
            <p class="text-sm text-gray-500 text-center py-8">No backups found.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Site</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Size</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Storage</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($backups as $backup)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-3 text-sm">
                                    @if($backup->site)
                                        <a href="{{ route('sites.backups', $backup->site) }}" class="text-purple-600 hover:text-purple-800 font-medium">
                                            {{ $backup->site->name }}
                                        </a>
                                        <div class="text-xs text-gray-400">{{ $backup->site->domain }}</div>
                                    @else
                                        <span class="text-gray-400">Deleted site</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-900">
                                    {{ $backup->created_at->format('M d, Y H:i') }}
                                    <div class="text-xs text-gray-400">{{ ucfirst(str_replace('_', ' ', $backup->trigger)) }}</div>
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ ucfirst($backup->type) }}</td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->file_size_formatted }}</td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->storageDestination?->name ?? '—' }}</td>
                                <td class="px-3 py-3">
                                    <x-ui.badge :variant="$backup->status_color">{{ ucfirst($backup->status) }}</x-ui.badge>
                                    @if($backup->is_locked)
                                        <x-ui.badge variant="purple" class="ml-1">Locked</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $backups->links() }}
            </div>
        @endif
    </x-ui.card>
</div>
