<x-ui.modal name="restore-confirmation" maxWidth="lg">
    @if($backup)
        <div @if($preRestoreBackupId && $preRestoreStatus && !in_array($preRestoreStatus, ['completed', 'failed'])) wire:poll.2s="checkPreRestoreStatus" @endif>
            <div class="flex items-center gap-3 mb-4">
                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-yellow-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Restore Backup</h2>
                    <p class="text-sm text-gray-500">This action will overwrite your current site data.</p>
                </div>
            </div>

            {{-- Backup details --}}
            <div class="rounded-lg bg-gray-50 p-4 mb-4">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Date</span>
                        <span class="text-gray-900 font-medium">{{ $backup->created_at->format('M d, Y H:i') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Type</span>
                        <span class="text-gray-900 font-medium">{{ ucfirst($backup->type) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Size</span>
                        <span class="text-gray-900 font-medium">{{ $backup->file_size_formatted }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Storage</span>
                        <span class="text-gray-900 font-medium">{{ $backup->storageDestination?->name ?? '—' }}</span>
                    </div>
                    @if($backup->wp_version)
                        <div class="flex justify-between">
                            <span class="text-gray-500">WordPress Version</span>
                            <span class="text-gray-900 font-medium">{{ $backup->wp_version }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Warnings --}}
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 mb-4">
                <h4 class="text-sm font-medium text-red-800 mb-2">This restore will:</h4>
                <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                    @if($backup->includes_database)
                        <li>Overwrite the entire database with the backup version</li>
                    @endif
                    @if($backup->includes_files)
                        <li>Replace wp-content files with the backup version</li>
                    @endif
                    <li>Potentially cause brief downtime during the restore process</li>
                    <li>This action cannot be automatically undone</li>
                </ul>
            </div>

            {{-- Auto-backup toggle --}}
            <label class="flex items-center gap-2 mb-3">
                <input type="checkbox" wire:model.live="backupBeforeRestore"
                    class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                    @if($preRestoreBackupId) disabled @endif>
                <span class="text-sm text-gray-700">Create a safety backup before restoring</span>
            </label>

            {{-- Confirmation checkbox --}}
            <label class="flex items-center gap-2 mb-4">
                <input type="checkbox" wire:model.live="confirmed" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                <span class="text-sm text-gray-700">I understand this will overwrite the current site data and cannot be undone.</span>
            </label>

            {{-- Pre-restore backup progress --}}
            @if($preRestoreBackupId && $preRestoreStatus)
                <div class="rounded-lg bg-purple-50 border border-purple-200 p-3 mb-4">
                    @if(in_array($preRestoreStatus, ['pending', 'in_progress']))
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-sm text-purple-700">Creating safety backup before restore...</span>
                        </div>
                    @elseif($preRestoreStatus === 'failed')
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            <span class="text-sm text-red-700">Pre-restore backup failed.</span>
                            <button wire:click="restoreAnyway" class="ml-auto text-xs font-medium text-red-600 hover:text-red-800 underline">
                                Restore Anyway
                            </button>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Actions --}}
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-restore-confirmation')">
                    Cancel
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="danger"
                    wire:click="restore"
                    :disabled="!$confirmed || ($preRestoreBackupId && $preRestoreStatus && !in_array($preRestoreStatus, ['completed', 'failed']))"
                >
                    @if($preRestoreBackupId && in_array($preRestoreStatus, ['pending', 'in_progress']))
                        Waiting for backup...
                    @else
                        Restore Backup
                    @endif
                </x-ui.button>
            </div>
        </div>
    @endif
</x-ui.modal>
