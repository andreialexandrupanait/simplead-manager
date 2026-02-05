@props(['site'])

@if($site->backupConfig)
    <div class="flex items-center justify-between">
        <span class="text-sm font-semibold text-gray-900">Backups</span>
        @php
            $bStatus = $site->backupConfig->last_backup_status;
            $bDot = match($bStatus) {
                'completed' => 'bg-green-500',
                'failed' => 'bg-red-500',
                default => 'bg-gray-400',
            };
        @endphp
        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500">
            <span class="h-2 w-2 rounded-full {{ $bDot }}"></span>
            {{ $bStatus ? ucfirst($bStatus) : 'No backups yet' }}
        </span>
    </div>
    @if($site->latestCompletedBackup)
        <div class="mt-3 text-xs">
            <div class="flex items-center justify-between">
                <span class="text-gray-500">Last backup</span>
                <span class="font-medium text-gray-900">{{ $site->latestCompletedBackup->completed_at->diffForHumans() }}</span>
            </div>
            <div class="mt-1 flex items-center justify-between">
                <span class="text-gray-500">Size</span>
                <span class="font-medium text-gray-900">{{ $site->latestCompletedBackup->file_size_formatted }}</span>
            </div>
        </div>
    @endif
    <div class="mt-2 text-xs">
        <div class="flex items-center justify-between">
            <span class="text-gray-500">Schedule</span>
            <span class="font-medium text-gray-900">{{ ucfirst($site->backupConfig->frequency ?? 'Not set') }}</span>
        </div>
        <div class="mt-1 flex items-center justify-between">
            <span class="text-gray-500">Total backups</span>
            <span class="font-medium text-gray-900">{{ $site->backups_count ?? 0 }}</span>
        </div>
        @if($site->backupConfig->next_backup_at)
            <div class="mt-1 flex items-center justify-between">
                <span class="text-gray-500">Next backup</span>
                <span class="font-medium text-gray-900">{{ $site->backupConfig->next_backup_at->format('M j, g:ia') }}</span>
            </div>
        @endif
    </div>
    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
        <button wire:click="runBackup({{ $site->id }})" class="rounded-md bg-purple-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-purple-700">Run Backup</button>
        <a href="{{ route('sites.backups', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">View Backups</a>
    </div>
@else
    <p class="text-sm text-gray-500">No backup configured</p>
    <a href="{{ route('sites.backups', $site) }}" class="mt-2 inline-block text-xs font-medium text-purple-600 hover:text-purple-800">Configure Backups</a>
@endif
