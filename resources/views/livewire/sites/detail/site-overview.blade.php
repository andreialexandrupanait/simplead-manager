<div {!! $hasRunningJobs ? 'wire:poll.1s="checkJobProgress"' : '' !!}>
    <x-ui.page-header
        title="Overview"
        subtitle="Site health, status, and key metrics"
    >
        <x-slot:actions>
            @if($site->is_connected)
                <button wire:click="openWpAdmin"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition">
                    <x-icons.globe class="h-4 w-4" />
                    Open WP Admin
                </button>
                <button wire:click="clearCache"
                        wire:confirm="Clear all caches on this site?"
                        wire:loading.attr="disabled"
                        wire:target="clearCache"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <span wire:loading.remove wire:target="clearCache">Clear Cache</span>
                    <span wire:loading wire:target="clearCache">Clearing...</span>
                </button>
                <button wire:click="syncNow"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition">
                    <x-icons.refresh-cw class="h-4 w-4" />
                    Sync Now
                </button>
                <button wire:click="openConnectModal"
                        class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition"
                        title="Plugin Settings">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                </button>
            @else
                <button wire:click="openConnectModal"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 transition">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                    </svg>
                    Connect Plugin
                </button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.circuit-breaker-banner :site="$site" />

    <x-ui.job-progress job-key="sync" :jobs="$trackedJobs" title="Syncing site data..." />

    {{-- Two-column layout --}}
    <div class="grid gap-4 lg:grid-cols-4">

        {{-- Main Content (left, 3/4 width) --}}
        <div class="lg:col-span-3 space-y-6">
            <livewire:sites.detail.site-plugins :site="$site" :embedded="true" />
            @include('livewire.sites.detail.overview._database-card')
            @include('livewire.sites.detail.overview._analytics-performance-card')
            @include('livewire.sites.detail.overview._search-console-card')
        </div>

        {{-- Overview Sidebar (right, 1/3 width) --}}
        <div>
            <div class="sticky top-20 space-y-4">
                @include('livewire.sites.detail.overview._health-bar')
                @include('livewire.sites.detail.overview._site-info-card')
                @include('livewire.sites.detail.overview._server-resources-card')
                @include('livewire.sites.detail.overview._uptime-card')
                @include('livewire.sites.detail.overview._backups-card')
                @include('livewire.sites.detail.overview._security-card')
                @include('livewire.sites.detail.overview._reports-card')
                @include('livewire.sites.detail.overview._client-card')
            </div>
        </div>

    </div>

    {{-- Connect Plugin Modal --}}
    <x-ui.modal name="connect-plugin" maxWidth="lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-900">Connect WordPress Plugin</h2>
            <p class="mt-1 text-sm text-gray-500">Follow these steps to connect your WordPress site.</p>

            <div class="mt-4 rounded-lg bg-gray-50 p-4">
                <ol class="space-y-3 text-sm text-gray-700">
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-600">1</span>
                        <span><a href="{{ route('download.connector-plugin') }}" class="font-medium text-indigo-600 hover:text-indigo-500 underline">Download the connector plugin</a> (.zip file)</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-600">2</span>
                        <span>Install &amp; activate it in your WordPress site (Plugins &rarr; Add New &rarr; Upload)</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-600">3</span>
                        <span>Go to <strong>WP Admin &rarr; Settings &rarr; SimpleAD Manager</strong></span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-600">4</span>
                        <span>Copy the <strong>API Key</strong>, <strong>API Secret</strong>, and <strong>API Endpoint</strong> into the fields below</span>
                    </li>
                </ol>
            </div>

            <form wire:submit="saveCredentials" class="mt-5 space-y-4">
                <div>
                    <label for="apiEndpoint" class="block text-sm font-medium text-gray-700">API Endpoint</label>
                    <x-ui.input wire:model="apiEndpoint" type="text" id="apiEndpoint" class="mt-1"
                           placeholder="https://example.com/wp-json/simplead/v1" />
                    @error('apiEndpoint') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="apiKey" class="block text-sm font-medium text-gray-700">API Key</label>
                    <x-ui.input wire:model="apiKey" type="text" id="apiKey" class="mt-1"
                           placeholder="Paste your API key here" />
                    @error('apiKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="apiSecret" class="block text-sm font-medium text-gray-700">API Secret</label>
                    <x-ui.input wire:model="apiSecret" type="password" id="apiSecret" class="mt-1"
                           placeholder="Paste your API secret here" />
                    @error('apiSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center justify-between pt-2">
                    @if($site->is_connected)
                        <button type="button" wire:click="disconnectSite" wire:confirm="Are you sure you want to disconnect this site?"
                                class="text-sm font-medium text-red-600 hover:text-red-500">
                            Disconnect
                        </button>
                    @else
                        <div></div>
                    @endif

                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 transition">
                        Save &amp; Connect
                    </button>
                </div>
            </form>
        </div>
    </x-ui.modal>
</div>
