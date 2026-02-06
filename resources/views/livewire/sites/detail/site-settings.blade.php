<div>
    {{-- Flash messages --}}
    <x-ui.flash-alert type="success" key="settings-saved" />
    <x-ui.flash-alert type="info" key="sync-dispatched" />
    <x-ui.flash-alert type="error" key="wp-admin-error" />

    <x-ui.card>
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-semibold text-gray-900">WordPress Connection</h2>
            <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full {{ $connectionStatus === 'connected' ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                <span class="text-sm {{ $connectionStatus === 'connected' ? 'text-green-700' : 'text-gray-500' }}">
                    {{ $connectionStatus === 'connected' ? 'Connected' : 'Not connected' }}
                </span>
                @if($site->last_synced_at)
                    <span class="text-xs text-gray-400 ml-2">Last synced {{ $site->last_synced_at->diffForHumans() }}</span>
                @endif
            </div>
        </div>

        <div class="space-y-4">
            <div>
                <label for="apiEndpoint" class="block text-sm font-medium text-gray-700 mb-1">API Endpoint</label>
                <x-ui.input
                    wire:model="apiEndpoint"
                    id="apiEndpoint"
                    type="text"
                    placeholder="{{ rtrim($site->url, '/') }}/wp-json/simplead/v1"
                />
                <p class="mt-1 text-xs text-gray-500">Leave empty to auto-detect from site URL.</p>
            </div>

            <div>
                <label for="apiKey" class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
                <x-ui.input
                    wire:model="apiKey"
                    id="apiKey"
                    type="password"
                    placeholder="Enter API key from WordPress plugin"
                />
            </div>

            <div>
                <label for="apiSecret" class="block text-sm font-medium text-gray-700 mb-1">API Secret</label>
                <x-ui.input
                    wire:model="apiSecret"
                    id="apiSecret"
                    type="password"
                    placeholder="Enter API secret from WordPress plugin"
                />
            </div>

            @error('apiKey')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            @error('apiSecret')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            {{-- Test result --}}
            @if($testResult)
                @php
                    [$resultType, $resultMessage] = explode(':', $testResult, 2);
                @endphp
                <div class="rounded-lg p-3 text-sm {{ $resultType === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' }}">
                    {{ $resultMessage }}
                </div>
            @endif

            <div class="flex items-center gap-3 pt-2">
                <x-ui.button wire:click="saveCredentials" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveCredentials">Save Credentials</span>
                    <span wire:loading wire:target="saveCredentials">Saving...</span>
                </x-ui.button>

                <x-ui.button variant="secondary" wire:click="testConnection" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="testConnection">Test Connection</span>
                    <span wire:loading wire:target="testConnection">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Testing...
                    </span>
                </x-ui.button>
            </div>
        </div>
    </x-ui.card>

    {{-- WordPress Info (shown when connected) --}}
    @if($site->is_connected)
        <x-ui.card class="mt-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">WordPress Information</h3>
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 text-sm">
                <div>
                    <dt class="text-gray-500">WP Version</dt>
                    <dd class="mt-1 font-medium text-gray-900">{{ $site->wp_version ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">PHP Version</dt>
                    <dd class="mt-1 font-medium text-gray-900">{{ $site->php_version ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Server</dt>
                    <dd class="mt-1 font-medium text-gray-900">{{ $site->server_software ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">DB Size</dt>
                    <dd class="mt-1 font-medium text-gray-900">{{ $site->db_size_mb ? $site->db_size_mb . ' MB' : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Uploads Size</dt>
                    <dd class="mt-1 font-medium text-gray-900">{{ $site->uploads_size_mb ? $site->uploads_size_mb . ' MB' : '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card class="mt-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
            <div class="flex items-center gap-3">
                <x-ui.button wire:click="openWpAdmin" variant="secondary">
                    <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                    Open WP Admin
                </x-ui.button>

                <x-ui.button wire:click="syncNow" variant="secondary" wire:loading.attr="disabled">
                    <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    <span wire:loading.remove wire:target="syncNow">Sync Now</span>
                    <span wire:loading wire:target="syncNow">Syncing...</span>
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif
</div>
