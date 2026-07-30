@php
    // The five storage backends, as pickable cards. Brand names stay untranslated.
    $storageTypes = [
        'local' => ['label' => __('Local'), 'icon' => 'hard-drive'],
        'dropbox' => ['label' => 'Dropbox', 'icon' => null],
        's3' => ['label' => __('S3 / Other'), 'icon' => 'cloud'],
        'b2' => ['label' => 'Backblaze B2', 'icon' => 'database'],
        'hetzner_objectstorage' => ['label' => 'Hetzner Storage', 'icon' => 'layers'],
    ];
@endphp

{{-- Every string in this modal used to be hardcoded English while the rest of
     the app went through __(). --}}
<x-ui.modal name="storage-form" maxWidth="lg">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">
            {{ $destinationId ? __('Edit Storage Destination') : __('Add Storage Destination') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500">{{ __('Configure where backups will be stored.') }}</p>

        <div class="mt-6 space-y-4">
            <x-ui.form-group :label="__('Name')" for="storage-name" error="form.name" required>
                <x-ui.input id="storage-name" wire:model="form.name" :placeholder="__('e.g. Local Storage, My S3 Bucket')" />
            </x-ui.form-group>

            {{-- Type selector --}}
            <div>
                <p class="mb-2 block text-sm font-medium text-gray-700">{{ __('Storage Type') }}</p>
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-5">
                    @foreach($storageTypes as $typeKey => $typeInfo)
                        @php $isSelected = $form->type === $typeKey; @endphp
                        <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $isSelected ? 'border-accent-500 ring-1 ring-accent-500 bg-accent-50' : 'border-gray-200 hover:border-gray-300' }}">
                            <input type="radio" wire:model.live="form.type" value="{{ $typeKey }}" class="sr-only">
                            <div class="w-full text-center">
                                @if($typeInfo['icon'])
                                    <x-dynamic-component
                                        :component="'icons.' . $typeInfo['icon']"
                                        class="mx-auto mb-1 h-6 w-6 {{ $isSelected ? 'text-accent-600' : 'text-gray-400' }}"
                                        aria-hidden="true"
                                    />
                                @else
                                    <svg aria-hidden="true" class="mx-auto mb-1 h-6 w-6 {{ $isSelected ? 'text-accent-600' : 'text-gray-400' }}" fill="currentColor" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75zm-5.97 4.49L6 14l-.03-.01L0 17.24v1.52l6.03-3.75L12 18.76v-1.52l-5.97-3.25zm11.94 0L12 17.24v1.52l5.97-3.25L24 18.76v-1.52l-6.03-3.25z"/></svg>
                                @endif
                                <span class="text-xs font-medium {{ $isSelected ? 'text-accent-700' : 'text-gray-600' }}">{{ $typeInfo['label'] }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>
                @error('form.type')
                    <p class="mt-1 text-xs text-red-600" role="alert">{{ $message }}</p>
                @enderror
            </div>

            {{-- Local fields --}}
            @if($form->type === 'local')
                <x-ui.form-group
                    :label="__('Storage Path')"
                    for="storage-local-path"
                    error="form.localPath"
                    :hint="__('Absolute path on the server where backups will be stored.')"
                    required
                >
                    <x-ui.input id="storage-local-path" wire:model="form.localPath" placeholder="/path/to/backups" />
                </x-ui.form-group>
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
                        'b2' => __('Application key from Backblaze (B2 Cloud Storage → App Keys).'),
                        'hetzner_objectstorage' => __('Access key from Hetzner Console → Object Storage → Credentials.'),
                        default => __('Access key for the S3-compatible service.'),
                    };
                    $bucketHelp = match($form->type) {
                        'b2' => __('The name of the B2 bucket you created in Backblaze (Browse Files → bucket name). Just the name, no URL.'),
                        'hetzner_objectstorage' => __('The bucket name you created in Hetzner Console → Object Storage. Just the name (e.g. simplead-backups), NOT the full URL.'),
                        default => __('Bucket name only — no URL, no protocol.'),
                    };
                @endphp

                @if($isPreset)
                    <x-ui.alert type="info">
                        {{ __('Using the :provider preset — endpoint and path style are configured automatically from the region you pick below.', ['provider' => $providerLabel]) }}
                    </x-ui.alert>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form-group :label="__('Access Key')" for="storage-s3-key" error="form.s3Key" :hint="$keyHelp">
                        <x-ui.input
                            id="storage-s3-key"
                            wire:model="form.s3Key"
                            :placeholder="$destinationId ? __('(unchanged)') : 'AKIAIOSFODNN7EXAMPLE'"
                        />
                    </x-ui.form-group>
                    <x-ui.form-group :label="__('Secret Key')" for="storage-s3-secret" error="form.s3Secret">
                        <x-ui.input
                            id="storage-s3-secret"
                            type="password"
                            wire:model="form.s3Secret"
                            :placeholder="$destinationId ? __('(unchanged)') : ''"
                        />
                    </x-ui.form-group>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form-group :label="__('Bucket Name')" for="storage-s3-bucket" error="form.s3Bucket" :hint="$bucketHelp" required>
                        <x-ui.input id="storage-s3-bucket" wire:model.live="form.s3Bucket" placeholder="my-backups" />
                    </x-ui.form-group>
                    <x-ui.form-group :label="__('Region')" for="storage-s3-region" error="form.s3Region" required>
                        @if($isPreset)
                            <x-ui.select id="storage-s3-region" wire:model.live="form.s3Region">
                                @foreach($this->regionOptions as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </x-ui.select>
                        @else
                            <x-ui.input id="storage-s3-region" wire:model="form.s3Region" placeholder="us-east-1" />
                        @endif
                    </x-ui.form-group>
                </div>

                @if($isPreset && $form->s3Bucket)
                    @php
                        $endpointInfo = \App\Services\Backup\Storage\StorageFactory::endpointFor($form->type, $form->s3Region);
                        $previewUrl = ($endpointInfo['endpoint'] ?? '').'/'.$form->s3Bucket;
                    @endphp
                    <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-600">
                        {{ __('Files will be uploaded to:') }}
                        <code class="mt-1 block break-all font-mono text-gray-800">{{ $previewUrl }}/{{ $form->s3BasePath ?: '...' }}</code>
                    </div>
                @endif

                @if(! $isPreset)
                    <x-ui.form-group
                        :label="__('Custom Endpoint (Optional)')"
                        for="storage-s3-endpoint"
                        error="form.s3Endpoint"
                        :hint="__('For DigitalOcean Spaces, Wasabi, MinIO, or other S3-compatible services. Leave empty for AWS.')"
                    >
                        <x-ui.input id="storage-s3-endpoint" wire:model="form.s3Endpoint" placeholder="https://nyc3.digitaloceanspaces.com" />
                    </x-ui.form-group>
                @endif

                <x-ui.form-group
                    :label="__('Base Path (Optional)')"
                    for="storage-s3-base-path"
                    error="form.s3BasePath"
                    :hint="__('Prefix for all backup files in the bucket.')"
                >
                    <x-ui.input id="storage-s3-base-path" wire:model="form.s3BasePath" placeholder="backups/" />
                </x-ui.form-group>
            @endif

            {{-- Dropbox fields --}}
            @if($form->type === 'dropbox')
                <x-ui.form-group
                    :label="__('Website backup path')"
                    for="storage-dropbox-base"
                    error="form.dropboxBasePath"
                    :hint="__('Folder in Dropbox where backups will be stored.')"
                >
                    <div class="flex items-center gap-2">
                        <x-ui.input id="storage-dropbox-base" wire:model="form.dropboxBasePath" placeholder="/SimpleAD Backups" class="flex-1" />
                        <x-ui.button
                            type="button" variant="secondary" size="md"
                            wire:click="openFolderBrowser"
                            wire:target="openFolderBrowser"
                            wire:loading.attr="disabled"
                        >
                            <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="openFolderBrowser" />
                            {{ __('Browse') }}
                        </x-ui.button>
                    </div>
                </x-ui.form-group>

                {{-- Inline folder browser --}}
                @if($showFolderBrowser)
                    <div class="rounded-lg border border-gray-200">
                        <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-2">
                            @if($browserCurrentPath !== '')
                                <x-ui.button type="button" variant="ghost" size="xs" wire:click="browseUp" :title="__('Go up')">
                                    <x-icons.chevron-left class="h-4 w-4" />
                                    <span class="sr-only">{{ __('Go up') }}</span>
                                </x-ui.button>
                            @endif
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-700">
                                {{ $browserCurrentPath ?: __('/ (root)') }}
                            </span>
                            <x-ui.spinner
                                size="sm"
                                class="hidden text-gray-400"
                                wire:loading.class.remove="hidden"
                                wire:target="browseTo, browseUp, openFolderBrowser"
                            />
                            <x-ui.button type="button" variant="ghost" size="xs" wire:click="closeFolderBrowser" :title="__('Close')">
                                <x-icons.x class="h-4 w-4" />
                                <span class="sr-only">{{ __('Close') }}</span>
                            </x-ui.button>
                        </div>

                        @if($browserError)
                            <div class="p-3">
                                <x-ui.alert type="error">{{ $browserError }}</x-ui.alert>
                            </div>
                        @else
                            <div class="max-h-56 overflow-y-auto" wire:loading.class="opacity-50" wire:target="browseTo, browseUp, openFolderBrowser">
                                @forelse($browserFolders as $folder)
                                    <div class="group flex items-center gap-2 border-b border-gray-100 px-3 py-2 last:border-b-0 hover:bg-gray-50">
                                        <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                        <button type="button" wire:click="browseTo('{{ $folder['path'] }}')" class="min-w-0 flex-1 truncate text-left text-sm text-gray-700 hover:text-gray-900">
                                            {{ $folder['name'] }}
                                        </button>
                                        <x-ui.button
                                            type="button" variant="ghost" size="xs"
                                            class="opacity-0 transition group-hover:opacity-100"
                                            wire:click="selectFolder('{{ $folder['path'] }}')"
                                        >
                                            {{ __('Select') }}
                                        </x-ui.button>
                                    </div>
                                @empty
                                    <div class="px-3 py-6 text-center text-sm text-gray-500">
                                        {{ __('No subfolders found.') }}
                                    </div>
                                @endforelse
                            </div>

                            <div class="flex items-center justify-between border-t border-gray-200 px-3 py-2">
                                <span class="truncate text-xs text-gray-500">{{ $browserCurrentPath ?: '/' }}</span>
                                <x-ui.button type="button" size="sm" wire:click="selectCurrentFolder">
                                    {{ __('Use This Folder') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                @endif

                <x-ui.form-group
                    :label="__('Website reports path')"
                    for="storage-dropbox-reports"
                    error="form.dropboxReportsPath"
                    :hint="__('Folder in Dropbox for report PDFs. Leave empty to disable cloud upload.')"
                >
                    <div class="flex items-center gap-2">
                        <x-ui.input id="storage-dropbox-reports" wire:model="form.dropboxReportsPath" placeholder="/Reports" class="flex-1" />
                        <x-ui.button
                            type="button" variant="secondary" size="md"
                            wire:click="openFolderBrowser('reports_path')"
                            wire:target="openFolderBrowser"
                            wire:loading.attr="disabled"
                        >
                            {{ __('Browse') }}
                        </x-ui.button>
                    </div>
                </x-ui.form-group>

                <x-ui.form-group
                    :label="__('Application backups path')"
                    for="storage-dropbox-app-backups"
                    error="form.dropboxAppBackupsPath"
                    :hint="__('Folder in Dropbox for SimpleAD application backups. Leave empty to disable.')"
                >
                    <div class="flex items-center gap-2">
                        <x-ui.input id="storage-dropbox-app-backups" wire:model="form.dropboxAppBackupsPath" placeholder="/App Backups" class="flex-1" />
                        <x-ui.button
                            type="button" variant="secondary" size="md"
                            wire:click="openFolderBrowser('app_backups_path')"
                            wire:target="openFolderBrowser"
                            wire:loading.attr="disabled"
                        >
                            {{ __('Browse') }}
                        </x-ui.button>
                    </div>
                </x-ui.form-group>

                <div class="pt-1">
                    <x-ui.button variant="secondary" size="md" :href="route('dropbox.auth')">
                        <svg aria-hidden="true" class="h-4 w-4" fill="#0061FE" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75zm-5.97 4.49L6 14l-.03-.01L0 17.24v1.52l6.03-3.75L12 18.76v-1.52l-5.97-3.25zm11.94 0L12 17.24v1.52l5.97-3.25L24 18.76v-1.52l-6.03-3.25z"/></svg>
                        {{ $destinationId ? __('Reconnect Dropbox') : __('Connect Dropbox') }}
                    </x-ui.button>
                </div>
            @endif

            <x-ui.checkbox wire:model="form.is_default" :label="__('Set as default storage destination')" />
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-storage-form')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button type="submit" wire:target="save" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                {{ $destinationId ? __('Update') : __('Save') }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
