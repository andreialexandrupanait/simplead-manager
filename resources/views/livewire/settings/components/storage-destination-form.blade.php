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
                <div class="grid grid-cols-2 gap-3">
                    <label class="relative flex cursor-pointer rounded-lg border p-3 {{ $type === 'local' ? 'border-purple-500 ring-1 ring-purple-500 bg-purple-50' : 'border-gray-200 hover:border-gray-300' }}">
                        <input type="radio" wire:model.live="type" value="local" class="sr-only">
                        <div class="text-center w-full">
                            <svg class="w-6 h-6 mx-auto mb-1 {{ $type === 'local' ? 'text-purple-600' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                            <span class="text-xs font-medium {{ $type === 'local' ? 'text-purple-700' : 'text-gray-600' }}">Local</span>
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
            <x-ui.button type="submit">
                {{ $destinationId ? 'Update' : 'Save' }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
