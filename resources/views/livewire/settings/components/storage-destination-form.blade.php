<x-ui.modal name="storage-form" maxWidth="lg">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">
            {{ $destinationId ? 'Edit Storage Destination' : 'Add Storage Destination' }}
        </h2>
        <p class="mt-1 text-sm text-gray-500">Configure where backups will be stored.</p>

        <div class="mt-6 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Name</label>
                <x-ui.input wire:model="form.name" placeholder="e.g. Local Storage, My S3 Bucket" class="mt-1" />
                @error('form.name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Type selector --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Storage Type</label>
                <div class="grid grid-cols-3 sm:grid-cols-5 gap-3">
                    <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $form->type === 'local' ? 'border-accent-500 ring-1 ring-accent-500 bg-accent-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="form.type" value="local" class="sr-only">
                        <div class="text-center w-full">
                            <svg aria-hidden="true" class="w-6 h-6 mx-auto mb-1 {{ $form->type === 'local' ? 'text-accent-600' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                            <span class="text-xs font-medium {{ $form->type === 'local' ? 'text-accent-700' : 'text-gray-600' }}">Local</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $form->type === 'dropbox' ? 'border-accent-500 ring-1 ring-accent-500 bg-accent-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="form.type" value="dropbox" class="sr-only">
                        <div class="text-center w-full">
                            <svg aria-hidden="true" class="w-6 h-6 mx-auto mb-1 {{ $form->type === 'dropbox' ? 'text-accent-600' : 'text-gray-400' }}" fill="currentColor" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75zm-5.97 4.49L6 14l-.03-.01L0 17.24v1.52l6.03-3.75L12 18.76v-1.52l-5.97-3.25zm11.94 0L12 17.24v1.52l5.97-3.25L24 18.76v-1.52l-6.03-3.25z"/></svg>
                            <span class="text-xs font-medium {{ $form->type === 'dropbox' ? 'text-accent-700' : 'text-gray-600' }}">Dropbox</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $form->type === 's3' ? 'border-accent-500 ring-1 ring-accent-500 bg-accent-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="form.type" value="s3" class="sr-only">
                        <div class="text-center w-full">
                            <svg aria-hidden="true" class="w-6 h-6 mx-auto mb-1 {{ $form->type === 's3' ? 'text-accent-600' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                            <span class="text-xs font-medium {{ $form->type === 's3' ? 'text-accent-700' : 'text-gray-600' }}">S3 / Other</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $form->type === 'b2' ? 'border-accent-500 ring-1 ring-accent-500 bg-accent-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="form.type" value="b2" class="sr-only">
                        <div class="text-center w-full">
                            <svg aria-hidden="true" class="w-6 h-6 mx-auto mb-1 {{ $form->type === 'b2' ? 'text-red-600' : 'text-gray-400' }}" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H7v-2h4v2zm0-4H7V7h4v5zm6 4h-4v-2h4v2zm0-4h-4V7h4v5z"/></svg>
                            <span class="text-xs font-medium {{ $form->type === 'b2' ? 'text-accent-700' : 'text-gray-600' }}">Backblaze B2</span>
                        </div>
                    </label>
                    <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $form->type === 'hetzner_objectstorage' ? 'border-accent-500 ring-1 ring-accent-500 bg-accent-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="form.type" value="hetzner_objectstorage" class="sr-only">
                        <div class="text-center w-full">
                            <svg aria-hidden="true" class="w-6 h-6 mx-auto mb-1 {{ $form->type === 'hetzner_objectstorage' ? 'text-red-600' : 'text-gray-400' }}" fill="currentColor" viewBox="0 0 24 24"><path d="M3 3h7v7H3V3zm0 11h7v7H3v-7zm11-11h7v7h-7V3zm0 11h7v7h-7v-7z"/></svg>
                            <span class="text-xs font-medium {{ $form->type === 'hetzner_objectstorage' ? 'text-accent-700' : 'text-gray-600' }}">Hetzner Storage</span>
                        </div>
                    </label>
                </div>
            </div>

            {{-- Local fields --}}
            @if($form->type === 'local')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Storage Path</label>
                    <x-ui.input wire:model="form.localPath" placeholder="/path/to/backups" class="mt-1" />
                    @error('form.localPath') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-gray-400">Absolute path on the server where backups will be stored.</p>
                </div>
            @endif

            {{-- S3 / B2 / Hetzner fields (shared) --}}
            @if(in_array($form->type, ['s3', 'b2', 'hetzner_objectstorage']))
                @php
                    $isPreset = in_array($form->type, ['b2', 'hetzner_objectstorage']);
                    $providerLabel = match($form->type) {
                        'b2' => 'Backblaze B2',
                        'hetzner_objectstorage' => 'Hetzner Object Storage',
                        default => 'S3',
                    };
                    $keyHelp = match($form->type) {
                        'b2' => 'Application key from Backblaze (B2 Cloud Storage → App Keys).',
                        'hetzner_objectstorage' => 'Access key from Hetzner Console → Object Storage → Credentials.',
                        default => 'Access key for the S3-compatible service.',
                    };
                @endphp

                @if($isPreset)
                    <div class="rounded-md bg-blue-50 p-3 text-xs text-blue-800">
                        Using {{ $providerLabel }} preset — endpoint and path style will be configured automatically based on the region you pick below.
                    </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Access Key</label>
                        <x-ui.input wire:model="form.s3Key" placeholder="{{ $destinationId ? '(unchanged)' : 'AKIAIOSFODNN7EXAMPLE' }}" class="mt-1" />
                        @error('form.s3Key') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-gray-400">{{ $keyHelp }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Secret Key</label>
                        <x-ui.input wire:model="form.s3Secret" type="password" placeholder="{{ $destinationId ? '(unchanged)' : '' }}" class="mt-1" />
                        @error('form.s3Secret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Bucket Name</label>
                        <x-ui.input wire:model.live="form.s3Bucket" placeholder="my-backups" class="mt-1" />
                        @error('form.s3Bucket') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-gray-400">
                            @if($form->type === 'b2')
                                The name of the B2 bucket you created in Backblaze (Browse Files → bucket name). Just the name, no URL.
                            @elseif($form->type === 'hetzner_objectstorage')
                                The bucket name you created in Hetzner Console → Object Storage. Just the name (e.g. <code class="bg-gray-100 px-1 rounded">simplead-backups</code>), NOT the full URL.
                            @else
                                Bucket name only — no URL, no protocol.
                            @endif
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Region</label>
                        @if($isPreset)
                            <x-ui.select wire:model.live="form.s3Region" class="mt-1">
                                @foreach($this->regionOptions as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </x-ui.select>
                        @else
                            <x-ui.input wire:model="form.s3Region" placeholder="us-east-1" class="mt-1" />
                        @endif
                        @error('form.s3Region') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if($isPreset && $form->s3Bucket)
                    @php
                        $endpointInfo = \App\Services\Backup\Storage\StorageFactory::endpointFor($form->type, $form->s3Region);
                        $previewUrl = ($endpointInfo['endpoint'] ?? '') . '/' . $form->s3Bucket;
                    @endphp
                    <div class="rounded-md bg-gray-50 p-3 text-xs text-gray-600">
                        Files will be uploaded to:
                        <code class="block mt-1 text-gray-800 font-mono break-all">{{ $previewUrl }}/{{ $form->s3BasePath ?: '...' }}</code>
                    </div>
                @endif

                @if(! $isPreset)
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Custom Endpoint (Optional)</label>
                        <x-ui.input wire:model="form.s3Endpoint" placeholder="https://nyc3.digitaloceanspaces.com" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-400">For DigitalOcean Spaces, Wasabi, MinIO, or other S3-compatible services. Leave empty for AWS.</p>
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700">Base Path (Optional)</label>
                    <x-ui.input wire:model="form.s3BasePath" placeholder="backups/" class="mt-1" />
                    <p class="mt-1 text-xs text-gray-400">Prefix for all backup files in the bucket.</p>
                </div>
            @endif

            {{-- Dropbox fields --}}
            @if($form->type === 'dropbox')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Website backup path</label>
                    <div class="mt-1 flex items-center gap-2">
                        <x-ui.input wire:model="form.dropboxBasePath" placeholder="/SimpleAD Backups" class="flex-1" />
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
                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                </button>
                            @endif
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-700">
                                {{ $browserCurrentPath ?: '/ (root)' }}
                            </span>
                            <span wire:loading wire:target="browseTo, browseUp, openFolderBrowser" class="text-gray-400">
                                <svg aria-hidden="true" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            </span>
                            <button type="button" wire:click="closeFolderBrowser" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Close">
                                <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
                                        <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                        <button type="button" wire:click="browseTo('{{ $folder['path'] }}')" class="min-w-0 flex-1 truncate text-left text-sm text-gray-700 hover:text-gray-900">
                                            {{ $folder['name'] }}
                                        </button>
                                        <button type="button" wire:click="selectFolder('{{ $folder['path'] }}')" class="shrink-0 rounded bg-accent-50 px-2 py-0.5 text-xs font-medium text-accent-600 opacity-0 transition hover:bg-accent-100 group-hover:opacity-100">
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

                <div>
                    <label class="block text-sm font-medium text-gray-700">Website reports path</label>
                    <div class="mt-1 flex items-center gap-2">
                        <x-ui.input wire:model="form.dropboxReportsPath" placeholder="/Reports" class="flex-1" />
                        <x-ui.button type="button" variant="secondary" size="md" wire:click="openFolderBrowser('reports_path')">
                            Browse
                        </x-ui.button>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">Folder in Dropbox for report PDFs. Leave empty to disable cloud upload.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Application backups path</label>
                    <div class="mt-1 flex items-center gap-2">
                        <x-ui.input wire:model="form.dropboxAppBackupsPath" placeholder="/App Backups" class="flex-1" />
                        <x-ui.button type="button" variant="secondary" size="md" wire:click="openFolderBrowser('app_backups_path')">
                            Browse
                        </x-ui.button>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">Folder in Dropbox for SimpleAD application backups. Leave empty to disable.</p>
                </div>

                <div class="pt-3">
                    <a href="{{ route('dropbox.auth') }}"
                       class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-300 bg-white text-gray-700 shadow-sm transition hover:bg-gray-50">
                        <svg aria-hidden="true" class="w-4 h-4" fill="#0061FE" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75zm-5.97 4.49L6 14l-.03-.01L0 17.24v1.52l6.03-3.75L12 18.76v-1.52l-5.97-3.25zm11.94 0L12 17.24v1.52l5.97-3.25L24 18.76v-1.52l-6.03-3.25z"/></svg>
                        {{ $destinationId ? 'Reconnect Dropbox' : 'Connect Dropbox' }}
                    </a>
                </div>
            @endif

            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="form.is_default" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                <span class="text-sm text-gray-700">Set as default storage destination</span>
            </label>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-storage-form')">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit">
                {{ $destinationId ? 'Update' : 'Save' }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
