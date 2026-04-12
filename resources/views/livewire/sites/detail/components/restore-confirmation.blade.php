<x-ui.modal name="restore-confirmation" maxWidth="3xl">
    @if($backup)
        <div @if($preRestoreBackupId && $preRestoreStatus && !in_array($preRestoreStatus, ['completed', 'failed'])) wire:poll.2s="checkPreRestoreStatus" @endif>
            <div class="flex items-center gap-3 mb-4">
                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-yellow-100 flex items-center justify-center">
                    <svg aria-hidden="true" class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Restore Backup') }}</h2>
                    <p class="text-sm text-gray-500">{{ __('This action will overwrite your current site data.') }}</p>
                </div>
            </div>

            {{-- Backup details --}}
            <div class="rounded-lg bg-gray-50 p-4 mb-4">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Date') }}</span>
                        <span class="text-gray-900 font-medium">{{ $backup->created_at->format('M d, Y H:i') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Type') }}</span>
                        <span class="text-gray-900 font-medium">{{ ucfirst($backup->type) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Size') }}</span>
                        <span class="text-gray-900 font-medium">{{ $backup->file_size_formatted }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">{{ __('Storage') }}</span>
                        <span class="text-gray-900 font-medium">{{ $backup->storageDestination?->name ?? '—' }}</span>
                    </div>
                    @if($backup->wp_version)
                        <div class="flex justify-between">
                            <span class="text-gray-500">{{ __('WordPress Version') }}</span>
                            <span class="text-gray-900 font-medium">{{ $backup->wp_version }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Restore mode toggle --}}
            <div class="flex rounded-lg bg-gray-100 p-1 mb-4">
                <button type="button"
                    wire:click="setRestoreMode('full')"
                    class="flex-1 py-2 px-3 text-sm font-medium rounded-md transition-colors {{ $restoreMode === 'full' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                    {{ __('Full Restore') }}
                </button>
                <button type="button"
                    wire:click="setRestoreMode('selective')"
                    class="flex-1 py-2 px-3 text-sm font-medium rounded-md transition-colors {{ $restoreMode === 'selective' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                    {{ __('Selective Restore') }}
                </button>
            </div>

            @if($restoreMode === 'full')
                {{-- Full restore warnings --}}
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 mb-4">
                    <h4 class="text-sm font-medium text-red-800 mb-2">{{ __('This restore will:') }}</h4>
                    <ul class="text-sm text-red-700 space-y-1 list-disc list-inside">
                        @if($backup->includes_database)
                            <li>{{ __('Overwrite the entire database with the backup version') }}</li>
                        @endif
                        @if($backup->includes_files)
                            <li>{{ __('Replace wp-content files with the backup version') }}</li>
                        @endif
                        <li>{{ __('Potentially cause brief downtime during the restore process') }}</li>
                        <li>{{ __('This action cannot be automatically undone') }}</li>
                    </ul>
                </div>
            @else
                {{-- Selective restore interface --}}
                @if($loadingFileList && !$fileListLoaded)
                    <div class="flex items-center justify-center py-12 mb-4" wire:poll.500ms="loadFileList">
                        <div class="text-center">
                            <x-ui.spinner size="lg" class="text-accent-600 mx-auto" />
                            <p class="mt-3 text-sm text-gray-500">{{ __('Loading backup contents...') }}</p>
                            <p class="text-xs text-gray-400 mt-1">{{ __('This may take a moment for large backups') }}</p>
                        </div>
                    </div>
                @elseif($fileListError)
                    <div class="rounded-lg border border-red-200 bg-red-50 p-4 mb-4">
                        <div class="flex items-start gap-3">
                            <svg aria-hidden="true" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-red-800">{{ __('Failed to load backup contents') }}</p>
                                <p class="text-sm text-red-700 mt-1 break-words">{{ $fileListError }}</p>
                                <button wire:click="loadFileList" class="mt-2 text-sm font-medium text-red-600 hover:text-red-800 underline">
                                    {{ __('Try again') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @elseif($fileListLoaded)
                    <div class="space-y-3 mb-4">
                        {{-- Database toggle --}}
                        @if($hasDatabase)
                            <label class="flex items-center gap-3 p-3 rounded-lg border {{ $restoreDatabase ? 'border-accent-200 bg-accent-50' : 'border-gray-200 bg-white' }} cursor-pointer transition-colors">
                                <input type="checkbox" wire:model.live="restoreDatabase"
                                    class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                                <div>
                                    <span class="text-sm font-medium text-gray-900">{{ __('Restore Database') }}</span>
                                    <p class="text-xs text-gray-500">{{ __('Overwrite the current database with the backup version') }}</p>
                                </div>
                            </label>
                        @endif

                        {{-- File browser --}}
                        @if($hasFiles)
                            <div class="rounded-lg border border-gray-200 bg-white" x-data="fileTreeBrowser(@js($fileTree))">
                                <div class="p-3 border-b border-gray-100">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-medium text-gray-900">{{ __('Restore Files') }}</span>
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="text-gray-500" x-text="selectedCount + ' of {{ $totalFileCount }} files selected'"></span>
                                            <button type="button" @click="selectAll()" class="text-accent-600 hover:text-accent-800 font-medium">{{ __('Select All') }}</button>
                                            <span class="text-gray-300">|</span>
                                            <button type="button" @click="clearAll()" class="text-accent-600 hover:text-accent-800 font-medium">{{ __('Clear') }}</button>
                                        </div>
                                    </div>

                                    {{-- Search --}}
                                    <div class="relative">
                                        <svg aria-hidden="true" class="absolute left-2.5 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                        <input type="text" x-model.debounce.200ms="search" placeholder="{{ __('Search files...') }}"
                                            class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-md focus:ring-accent-500 focus:border-accent-500">
                                    </div>
                                </div>

                                {{-- File tree --}}
                                <div class="max-h-80 overflow-y-auto p-2">
                                    @if(count($fileTree) > 0)
                                        <x-backup.file-tree-node :nodes="$fileTree" />
                                    @else
                                        <p class="text-sm text-gray-500 text-center py-4">{{ __('No files found in this backup') }}</p>
                                    @endif
                                </div>

                                @if($fileListTruncated)
                                    <div class="px-3 py-2 border-t border-gray-100 bg-yellow-50">
                                        <p class="text-xs text-yellow-700">{{ __('File list truncated at 15,000 files. Some files may not be shown.') }}</p>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- Warning if nothing selected --}}
                        @if(!$hasDatabase && !$hasFiles)
                            <div class="rounded-lg border border-yellow-200 bg-yellow-50 p-3">
                                <p class="text-sm text-yellow-700">{{ __('This backup contains no restorable content.') }}</p>
                            </div>
                        @endif
                    </div>

                    {{-- Selective restore warning --}}
                    <div class="rounded-lg border border-yellow-200 bg-yellow-50 p-3 mb-4" x-data x-show="$wire.restoreMode === 'selective'">
                        <p class="text-sm text-yellow-700">
                            <span class="font-medium">{{ __('Note:') }}</span> {{ __("Selective restore will only restore the items you've selected. Other data will remain unchanged.") }}
                        </p>
                    </div>
                @endif
            @endif

            {{-- Auto-backup toggle --}}
            <label class="flex items-center gap-2 mb-3">
                <input type="checkbox" wire:model.live="backupBeforeRestore"
                    class="rounded border-gray-300 text-accent-600 focus:ring-accent-500"
                    @if($preRestoreBackupId) disabled @endif>
                <span class="text-sm text-gray-700">{{ __('Create a safety backup before restoring') }}</span>
            </label>

            {{-- Confirmation checkbox --}}
            <label class="flex items-center gap-2 mb-4">
                <input type="checkbox" wire:model.live="confirmed" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                <span class="text-sm text-gray-700">{{ __('I understand this will overwrite the current site data and cannot be undone.') }}</span>
            </label>

            {{-- Pre-restore backup progress --}}
            @if($preRestoreBackupId && $preRestoreStatus)
                <div class="rounded-lg bg-accent-50 border border-accent-200 p-3 mb-4">
                    @if(in_array($preRestoreStatus, ['pending', 'in_progress']))
                        <div class="flex items-center gap-2">
                            <x-ui.spinner size="sm" class="text-accent-600" />
                            <span class="text-sm text-accent-700">{{ __('Creating safety backup before restore...') }}</span>
                        </div>
                    @elseif($preRestoreStatus === 'failed')
                        <div class="flex items-center gap-2">
                            <svg aria-hidden="true" class="h-4 w-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            <span class="text-sm text-red-700">{{ __('Pre-restore backup failed.') }}</span>
                            <button wire:click="restoreAnyway" class="ml-auto text-xs font-medium text-red-600 hover:text-red-800 underline">
                                {{ __('Restore Anyway') }}
                            </button>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Actions --}}
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-restore-confirmation')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="danger"
                    wire:click="restore"
                    :disabled="!$confirmed || ($preRestoreBackupId && $preRestoreStatus && !in_array($preRestoreStatus, ['completed', 'failed']))"
                >
                    @if($preRestoreBackupId && in_array($preRestoreStatus, ['pending', 'in_progress']))
                        {{ __('Waiting for backup...') }}
                    @elseif($restoreMode === 'selective')
                        {{ __('Restore Selected') }}
                    @else
                        {{ __('Restore Backup') }}
                    @endif
                </x-ui.button>
            </div>
        </div>
    @endif
</x-ui.modal>

@script
<script>
Alpine.data('fileTreeBrowser', (initialTree) => ({
    search: '',
    selected: {},
    expanded: {},
    dirIndex: {},
    allFiles: [],
    selectedCount: 0,

    init() {
        this.buildIndex(initialTree);
        this.$watch('selected', () => {
            this.syncToLivewire();
        });
    },

    buildIndex(nodes) {
        this.dirIndex = {};
        this.allFiles = [];
        this._buildRecursive(nodes);
    },

    _buildRecursive(nodes) {
        nodes.forEach(node => {
            if (node.type === 'dir') {
                this.dirIndex[node.path] = [];
                this._collectFiles(node.children, node.path);
            } else {
                this.allFiles.push(node.path);
            }
        });
    },

    _collectFiles(nodes, dirPath) {
        nodes.forEach(node => {
            if (node.type === 'dir') {
                this.dirIndex[node.path] = [];
                this._collectFiles(node.children, node.path);
                // Merge child files into parent
                this.dirIndex[node.path].forEach(f => {
                    this.dirIndex[dirPath].push(f);
                });
            } else {
                this.allFiles.push(node.path);
                this.dirIndex[dirPath].push(node.path);
            }
        });
    },

    toggleFile(path) {
        if (this.selected[path]) {
            delete this.selected[path];
        } else {
            this.selected[path] = true;
        }
        this.selected = { ...this.selected };
    },

    toggleDir(path) {
        const files = this.dirIndex[path] || [];
        const allSelected = files.every(f => this.selected[f]);

        if (allSelected) {
            files.forEach(f => delete this.selected[f]);
        } else {
            files.forEach(f => this.selected[f] = true);
        }
        this.selected = { ...this.selected };
    },

    toggleExpand(path) {
        this.expanded[path] = !this.expanded[path];
    },

    dirState(path) {
        const files = this.dirIndex[path] || [];
        if (files.length === 0) return 'none';
        const selectedCount = files.filter(f => this.selected[f]).length;
        if (selectedCount === 0) return 'none';
        if (selectedCount === files.length) return 'all';
        return 'some';
    },

    dirFileCount(path) {
        return (this.dirIndex[path] || []).length;
    },

    selectAll() {
        this.allFiles.forEach(f => this.selected[f] = true);
        this.selected = { ...this.selected };
    },

    clearAll() {
        this.selected = {};
    },

    matchesSearch(path) {
        if (!this.search) return true;
        return path.toLowerCase().includes(this.search.toLowerCase());
    },

    syncToLivewire() {
        const paths = Object.keys(this.selected).filter(k => this.selected[k]);
        this.selectedCount = paths.length;
        this.$wire.set('selectedFiles', paths);
    }
}));
</script>
@endscript
