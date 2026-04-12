<x-ui.card :padding="false" class="flex flex-col">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-100">
                <svg aria-hidden="true" class="h-4 w-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Backups</h3>
        </div>
        <a href="{{ route('sites.backups', $site) }}" class="text-xs text-accent-600 hover:text-accent-700">
            Details →
        </a>
    </div>

    {{-- Card Content --}}
    <div class="flex flex-1 flex-col p-3">
        @if($site->backupConfig || $site->latestCompletedBackup)
            <div class="flex-1 space-y-2">
                {{-- Last Backup --}}
                @if($site->latestCompletedBackup)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Last Backup</span>
                        <div class="flex items-center gap-1.5">
                            <svg aria-hidden="true" class="h-3.5 w-3.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span class="text-sm text-gray-900">{{ $site->latestCompletedBackup->created_at->diffForHumans() }}</span>
                        </div>
                    </div>
                @endif

                {{-- Next Scheduled --}}
                @if($site->backupConfig?->next_backup_at)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Next Backup</span>
                        <span class="text-sm text-gray-900">{{ $site->backupConfig->next_backup_at->diffForHumans() }}</span>
                    </div>
                @endif

                {{-- Storage --}}
                @php
                    $storageUsed = $this->backupStorageUsed;
                    $destination = $site->backupConfig?->storageDestination;
                    $storageLimit = $destination?->quota_bytes ?? 0;
                @endphp
                @if($storageUsed > 0)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Storage</span>
                        <span class="text-sm text-gray-900">
                            {{ \App\Helpers\FormatHelper::bytes($storageUsed) }}{{ $storageLimit > 0 ? ' / ' . \App\Helpers\FormatHelper::bytes($storageLimit) : '' }}
                        </span>
                    </div>
                @endif
            </div>

            {{-- Run Backup Button --}}
            <div class="mt-3 border-t border-gray-100 pt-3">
                <x-ui.button wire:click="runBackup" color="teal" size="sm" class="w-full" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="runBackup">Run Backup Now</span>
                    <span wire:loading wire:target="runBackup">Starting...</span>
                </x-ui.button>
            </div>
        @else
            <div class="py-2 text-center">
                <p class="text-sm text-gray-500">No backups configured</p>
                <a href="{{ route('sites.backups', $site) }}" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700">
                    Configure Backups →
                </a>
            </div>
        @endif
    </div>
</x-ui.card>
