<x-ui.modal name="storage-form" maxWidth="lg">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">
            {{ $destinationId ? 'Edit Storage Destination' : 'Add Storage Destination' }}
        </h2>
        <p class="mt-1 text-sm text-gray-500">Configure where backups will be stored.</p>

        <div class="mt-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Name</label>
                <x-ui.input wire:model="name" placeholder="e.g. Local Storage, My S3 Bucket" class="mt-1" />
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Type selector --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Storage Type</label>
                <div class="grid grid-cols-3 gap-3">
                    <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $type === 'local' ? 'border-purple-500 ring-1 ring-purple-500 bg-purple-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="type" value="local" class="sr-only">
                        <div class="text-center w-full">
                            <svg class="w-6 h-6 mx-auto mb-1 {{ $type === 'local' ? 'text-purple-600' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                            <span class="text-xs font-medium {{ $type === 'local' ? 'text-purple-700' : 'text-gray-600' }}">Local</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $type === 'dropbox' ? 'border-purple-500 ring-1 ring-purple-500 bg-purple-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="type" value="dropbox" class="sr-only">
                        <div class="text-center w-full">
                            <svg class="w-6 h-6 mx-auto mb-1 {{ $type === 'dropbox' ? 'text-purple-600' : 'text-gray-400' }}" fill="currentColor" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75zm-5.97 4.49L6 14l-.03-.01L0 17.24v1.52l6.03-3.75L12 18.76v-1.52l-5.97-3.25zm11.94 0L12 17.24v1.52l5.97-3.25L24 18.76v-1.52l-6.03-3.25z"/></svg>
                            <span class="text-xs font-medium {{ $type === 'dropbox' ? 'text-purple-700' : 'text-gray-600' }}">Dropbox</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $type === 's3' ? 'border-purple-500 ring-1 ring-purple-500 bg-purple-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="type" value="s3" class="sr-only">
                        <div class="text-center w-full">
                            <svg class="w-6 h-6 mx-auto mb-1 {{ $type === 's3' ? 'text-purple-600' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                            <span class="text-xs font-medium {{ $type === 's3' ? 'text-purple-700' : 'text-gray-600' }}">S3 / Compatible</span>
                        </div>
                    </label>
                </div>
            </div>

            {{-- Local fields --}}
            @if($type === 'local')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Storage Path</label>
                    <x-ui.input wire:model="localPath" placeholder="/path/to/backups" class="mt-1" />
                    @error('localPath') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-gray-400">Absolute path on the server where backups will be stored.</p>
                </div>
            @endif

            {{-- Dropbox fields --}}
            @if($type === 'dropbox')
                <div class="rounded-lg bg-blue-50 p-4">
                    <p class="text-sm text-blue-700 mb-3">Connect your Dropbox account to store backups.</p>
                    <a href="{{ route('dropbox.auth') }}" wire:navigate.away class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75z"/></svg>
                        Connect Dropbox
                    </a>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Base Path</label>
                    <div class="mt-1 flex items-center gap-2">
                        <x-ui.input wire:model="dropboxBasePath" placeholder="/SimpleAD Backups" class="flex-1" />
                        <x-ui.button type="button" variant="secondary" size="md" wire:click="openFolderBrowser">
                            Browse
                        </x-ui.button>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">Folder in Dropbox where backups will be stored.</p>
                </div>

                {{-- Inline folder browser --}}
                @if($showFolderBrowser)
                    <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                        {{-- Header --}}
                        <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2">
                            @if($browserCurrentPath !== '')
                                <button type="button" wire:click="browseUp" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Go up">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                </button>
                            @endif
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-700">
                                {{ $browserCurrentPath ?: '/ (root)' }}
                            </span>
                            <span wire:loading wire:target="browseTo, browseUp, openFolderBrowser" class="text-gray-400">
                                <x-ui.spinner size="sm" />
                            </span>
                            <button type="button" wire:click="closeFolderBrowser" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Close">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        {{-- Error state --}}
                        @if($browserError)
                            <div class="p-3">
                                <x-ui.alert type="error">{{ $browserError }}</x-ui.alert>
                            </div>
                        @endif

                        {{-- Folder list --}}
                        @if(! $browserError)
                            <div class="max-h-56 overflow-y-auto" wire:loading.class="opacity-50" wire:target="browseTo, browseUp, openFolderBrowser">
                                @forelse($browserFolders as $folder)
                                    <div class="group flex items-center gap-2 border-b border-gray-100 px-3 py-2 last:border-b-0 hover:bg-gray-50">
                                        <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                        <button type="button" wire:click="browseTo('{{ $folder['path'] }}')" class="min-w-0 flex-1 truncate text-left text-sm text-gray-700 hover:text-gray-900">
                                            {{ $folder['name'] }}
                                        </button>
                                        <button type="button" wire:click="selectFolder('{{ $folder['path'] }}')" class="shrink-0 rounded bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-600 opacity-0 transition hover:bg-purple-100 group-hover:opacity-100">
                                            Select
                                        </button>
                                    </div>
                                @empty
                                    <div class="px-3 py-6 text-center text-sm text-gray-400">
                                        No subfolders found.
                                    </div>
                                @endforelse
                            </div>

                            {{-- Footer --}}
                            <div class="flex items-center justify-between border-t border-gray-200 px-3 py-2">
                                <span class="truncate text-xs text-gray-400">
                                    {{ $browserCurrentPath ?: '/' }}
                                </span>
                                <x-ui.button type="button" size="sm" wire:click="selectCurrentFolder">
                                    Use This Folder
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                @endif
            @endif

            {{-- S3 fields --}}
            @if($type === 's3')
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Access Key</label>
                        <x-ui.input wire:model="s3Key" placeholder="{{ $destinationId ? '(unchanged)' : 'AKIAIOSFODNN7EXAMPLE' }}" class="mt-1" />
                        @error('s3Key') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Secret Key</label>
                        <x-ui.input wire:model="s3Secret" type="password" placeholder="{{ $destinationId ? '(unchanged)' : '' }}" class="mt-1" />
                        @error('s3Secret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Bucket</label>
                        <x-ui.input wire:model="s3Bucket" placeholder="my-backup-bucket" class="mt-1" />
                        @error('s3Bucket') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Region</label>
                        <x-ui.input wire:model="s3Region" placeholder="us-east-1" class="mt-1" />
                        @error('s3Region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Custom Endpoint (Optional)</label>
                    <x-ui.input wire:model="s3Endpoint" placeholder="https://nyc3.digitaloceanspaces.com" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-400">For DigitalOcean Spaces, Backblaze B2, or other S3-compatible services.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Base Path (Optional)</label>
                    <x-ui.input wire:model="s3BasePath" placeholder="backups/" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-400">Prefix for all backup files in the bucket.</p>
                </div>
            @endif

            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="is_default" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                <span class="text-sm text-gray-700">Set as default storage destination</span>
            </label>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-storage-form')">
                Cancel
            </x-ui.button>
            @if($type !== 'dropbox')
                <x-ui.button type="submit">
                    {{ $destinationId ? 'Update' : 'Save' }}
                </x-ui.button>
            @endif
        </div>
    </form>
</x-ui.modal>
