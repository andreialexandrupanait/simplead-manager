<x-ui.modal name="configure-monitor" maxWidth="lg">
    <form wire:submit="save">
        <h2 class="text-lg font-semibold text-gray-900">
            {{ $monitorId ? 'Edit Monitor' : 'Add Uptime Monitor' }}
        </h2>
        <p class="mt-1 text-sm text-gray-500">Configure uptime monitoring for a site.</p>

        <div class="mt-6 space-y-4">
            {{-- URL --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">URL</label>
                <x-ui.input wire:model="url" type="url" placeholder="https://example.com" class="mt-1" />
                @error('url') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Type --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">Monitor Type</label>
                <x-ui.select wire:model.live="type" class="mt-1">
                    <option value="http">HTTP(S)</option>
                    <option value="keyword">Keyword</option>
                    <option value="ping">Ping</option>
                </x-ui.select>
            </div>

            {{-- Keyword fields --}}
            @if($type === 'keyword')
                <div>
                    <label class="block text-sm font-medium text-gray-700">Keyword</label>
                    <x-ui.input wire:model="keyword" placeholder="Expected text on the page" class="mt-1" />
                    @error('keyword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex-1">
                        <x-ui.select wire:model="keyword_type" class="mt-1">
                            <option value="exists">Must exist</option>
                            <option value="not_exists">Must not exist</option>
                        </x-ui.select>
                    </div>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="keyword_case_sensitive" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <span class="text-sm text-gray-700">Case sensitive</span>
                    </label>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4">
                {{-- Interval --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700">Check Interval</label>
                    <x-ui.select wire:model="interval_minutes" class="mt-1">
                        <option value="1">Every 1 min</option>
                        <option value="3">Every 3 min</option>
                        <option value="5">Every 5 min</option>
                        <option value="10">Every 10 min</option>
                        <option value="15">Every 15 min</option>
                        <option value="30">Every 30 min</option>
                        <option value="60">Every 60 min</option>
                    </x-ui.select>
                </div>

                {{-- Timeout --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700">Timeout (seconds)</label>
                    <x-ui.input wire:model="timeout" type="number" min="5" max="120" class="mt-1" />
                    @error('timeout') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            @if($type !== 'ping')
                <div class="grid grid-cols-2 gap-4">
                    {{-- HTTP Method --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700">HTTP Method</label>
                        <x-ui.select wire:model="http_method" class="mt-1">
                            <option value="GET">GET</option>
                            <option value="HEAD">HEAD</option>
                            <option value="POST">POST</option>
                        </x-ui.select>
                    </div>

                    {{-- Follow Redirects --}}
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="follow_redirects" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                            <span class="text-sm text-gray-700">Follow redirects</span>
                        </label>
                    </div>
                </div>
            @endif

            {{-- SSL --}}
            <div class="grid grid-cols-2 gap-4">
                <div class="flex items-end pb-1">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="check_ssl" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <span class="text-sm text-gray-700">Monitor SSL certificate</span>
                    </label>
                </div>
                @if($check_ssl)
                    <div>
                        <label class="block text-sm font-medium text-gray-700">SSL expiry warning (days)</label>
                        <x-ui.input wire:model="ssl_expiry_threshold" type="number" min="1" max="90" class="mt-1" />
                    </div>
                @endif
            </div>

            {{-- Alert threshold --}}
            <div>
                <label class="block text-sm font-medium text-gray-700">Alert after consecutive failures</label>
                <x-ui.input wire:model="alert_after_failures" type="number" min="1" max="10" class="mt-1" />
                @error('alert_after_failures') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-configure-monitor')">
                Cancel
            </x-ui.button>
            <x-ui.button type="submit">
                {{ $monitorId ? 'Update Monitor' : 'Create Monitor' }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
