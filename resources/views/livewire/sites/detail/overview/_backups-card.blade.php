<x-ui.card>
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-teal-100">
                <svg class="h-5 w-5 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900">Backups</h3>
        </div>
        <a href="{{ route('sites.backups', $site) }}" class="text-sm text-purple-600 hover:text-purple-700">
            View Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="p-4">
        @if($site->backupConfig)
            {{-- Next Scheduled Backup --}}
            @if($site->backupConfig->next_backup_at)
            <div class="mb-4 rounded-lg bg-teal-50 p-4">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <div class="text-xs font-medium text-teal-900">Next Scheduled</div>
                        <div class="text-sm text-teal-700">
                            {{ $site->backupConfig->next_backup_at->diffForHumans() }}
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- Storage Usage --}}
            @php
                $storageUsed = $site->backups()->sum('file_size');
                $storageLimit = $site->backupConfig->storage_limit_gb * 1024 * 1024 * 1024; // Convert GB to bytes
                $storagePercent = $storageLimit > 0 ? min(($storageUsed / $storageLimit) * 100, 100) : 0;
            @endphp

            <div class="mb-4">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-sm text-gray-600">Storage Used</span>
                    <span class="text-sm font-medium text-gray-900">
                        {{ number_format($storageUsed / 1024 / 1024 / 1024, 2) }} GB / {{ $site->backupConfig->storage_limit_gb }} GB
                    </span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                    <div
                        class="h-full rounded-full transition-all {{ $storagePercent >= 90 ? 'bg-red-500' : ($storagePercent >= 70 ? 'bg-yellow-500' : 'bg-teal-500') }}"
                        style="width: {{ $storagePercent }}%"
                    ></div>
                </div>
            </div>

            {{-- Last Backup --}}
            @if($site->latestCompletedBackup)
            <div class="mb-4 flex items-center justify-between border-t border-gray-100 pt-4">
                <div>
                    <div class="text-sm text-gray-600">Last Backup</div>
                    <div class="mt-1 text-sm font-medium text-gray-900">
                        {{ $site->latestCompletedBackup->created_at->diffForHumans() }}
                    </div>
                </div>
                <div class="flex items-center gap-1 text-green-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span class="text-xs font-medium">Success</span>
                </div>
            </div>
            @endif

            {{-- Run Backup Button --}}
            <div class="border-t border-gray-100 pt-4">
                <x-ui.button wire:click="runBackup" color="teal" size="sm" class="w-full">
                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Run Backup Now
                </x-ui.button>
            </div>
        @else
            <x-ui.empty-state
                title="No backup configured"
                description="Set up automated backups to protect your site data."
            >
                <x-slot:actions>
                    <x-ui.button href="{{ route('sites.backups', $site) }}" color="teal">
                        Configure Backups
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        @endif
    </div>
</x-ui.card>
