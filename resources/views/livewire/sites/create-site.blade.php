<div class="max-w-2xl">
    {{-- Mode Tabs --}}
    <div class="mb-6 flex gap-1 rounded-lg bg-gray-100 p-1">
        @foreach(['connect' => 'Connect Existing', 'bulk' => 'Bulk Add', 'create' => 'Create New', 'migrate' => 'Migrate', 'clone' => 'Clone'] as $key => $label)
            <button
                wire:click="$set('mode', '{{ $key }}')"
                class="flex-1 rounded-md px-3 py-2 text-sm font-medium transition {{ $mode === $key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if($mode === 'connect')
        <x-ui.card>
            <form wire:submit="connectSite">
                <h2 class="text-lg font-semibold text-gray-900">Connect an Existing Site</h2>
                <p class="mt-1 text-sm text-gray-500">Add a WordPress site you'd like to manage.</p>

                <div class="mt-6 space-y-4">
                    {{-- Name --}}
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Site Name</label>
                        <x-ui.input wire:model="name" id="name" placeholder="My Website" class="mt-1" />
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- URL --}}
                    <div>
                        <label for="url" class="block text-sm font-medium text-gray-700">Site URL</label>
                        <x-ui.input wire:model="url" id="url" type="url" placeholder="https://example.com" class="mt-1" />
                        @error('url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Client --}}
                    <div>
                        <label for="clientId" class="block text-sm font-medium text-gray-700">Client</label>
                        <x-ui.select wire:model="clientId" id="clientId" class="mt-1">
                            <option value="">— No client —</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}">{{ $client->name }}</option>
                            @endforeach
                        </x-ui.select>
                        @error('clientId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Notes --}}
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea
                            wire:model="notes"
                            id="notes"
                            rows="3"
                            placeholder="Optional notes about this site..."
                            class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition placeholder:text-gray-400 focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                        ></textarea>
                        @error('notes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100" wire:navigate>
                        Cancel
                    </a>
                    <x-ui.button type="submit">
                        <span wire:loading.remove wire:target="connectSite">Connect Site</span>
                        <span wire:loading wire:target="connectSite">Connecting...</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @elseif($mode === 'bulk')
        <x-ui.card>
            <form wire:submit="bulkAddSites">
                <h2 class="text-lg font-semibold text-gray-900">Bulk Add Sites</h2>
                <p class="mt-1 text-sm text-gray-500">Paste multiple URLs, one per line.</p>

                <div class="mt-6 space-y-4">
                    {{-- URLs --}}
                    <div>
                        <label for="bulkUrls" class="block text-sm font-medium text-gray-700">Site URLs</label>
                        <textarea
                            wire:model="bulkUrls"
                            id="bulkUrls"
                            rows="8"
                            placeholder="https://example.com&#10;another-site.com&#10;mysite.org"
                            class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono shadow-sm transition placeholder:text-gray-400 focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                        ></textarea>
                        <p class="mt-1 text-xs text-gray-400">One URL per line. https:// added automatically if missing.</p>
                        @error('bulkUrls') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Client --}}
                    <div>
                        <label for="bulkClientId" class="block text-sm font-medium text-gray-700">Client <span class="text-gray-400">(optional, applied to all)</span></label>
                        <x-ui.select wire:model="clientId" id="bulkClientId" class="mt-1">
                            <option value="">— No client —</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}">{{ $client->name }}</option>
                            @endforeach
                        </x-ui.select>
                        @error('clientId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100" wire:navigate>
                        Cancel
                    </a>
                    <x-ui.button type="submit">
                        <span wire:loading.remove wire:target="bulkAddSites">Bulk Add Sites</span>
                        <span wire:loading wire:target="bulkAddSites">Adding Sites...</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @else
        <x-ui.card>
            <div class="py-8 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                <h3 class="mt-2 text-sm font-semibold text-gray-900">Coming Soon</h3>
                <p class="mt-1 text-sm text-gray-500">The {{ $mode }} mode is not yet available. Use "Connect Existing" to add your site.</p>
            </div>
        </x-ui.card>
    @endif
</div>
