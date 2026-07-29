<x-ui.module-card title="Backups" icon="hard-drive" tone="dark">
    @if($site->backupConfig || $site->latestCompletedBackup)
        {{-- Last Backup --}}
        @if($site->latestCompletedBackup)
            <x-ui.info-row label="Last Backup">
                <span class="mval-ok">{{ $site->latestCompletedBackup->created_at->diffForHumans() }}</span>
            </x-ui.info-row>
        @endif

        {{-- Next Scheduled --}}
        @if($site->backupConfig?->next_backup_at)
            <x-ui.info-row label="Next Backup">{{ $site->backupConfig->next_backup_at->diffForHumans() }}</x-ui.info-row>
        @endif

        {{-- Storage --}}
        @php
            $storageUsed = $this->backupStorageUsed;
            $destination = $site->backupConfig?->storageDestination;
            $storageLimit = $destination?->quota_bytes ?? 0;
        @endphp
        @if($storageUsed > 0)
            <x-ui.info-row label="Storage">
                {{ \App\Helpers\FormatHelper::bytes($storageUsed) }}{{ $storageLimit > 0 ? ' / ' . \App\Helpers\FormatHelper::bytes($storageLimit) : '' }}
            </x-ui.info-row>
        @endif

        {{-- Run Backup --}}
        <button type="button" class="mact" wire:click="runBackup" wire:loading.attr="disabled" wire:target="runBackup">
            <span wire:loading.remove wire:target="runBackup">Run Backup Now</span>
            <span wire:loading wire:target="runBackup">Starting...</span>
        </button>
    @else
        <div class="py-2 text-center">
            <p class="text-sm text-gray-500">No backups configured</p>
            <a href="{{ route('sites.backups', $site) }}" class="mt-1 inline-block text-xs text-accent-600 hover:text-accent-700">
                Configure Backups →
            </a>
        </div>
    @endif
</x-ui.module-card>
