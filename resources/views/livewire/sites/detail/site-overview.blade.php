<div {!! $hasRunningJobs ? 'wire:poll.3s="checkJobProgress"' : '' !!}>
    <x-ui.page-header
        title="{{ __('Overview') }}"
        subtitle="{{ __('Site health, status, and key metrics') }}"
    >
        <x-slot:actions>
            @if($site->is_connected)
                <x-ui.wp-admin-button :site="$site" />
                <x-ui.button variant="secondary" size="sm"
                             wire:click="clearCache"
                             wire:confirm="{{ __('Clear all caches on this site?') }}"
                             wire:loading.attr="disabled"
                             wire:target="clearCache">
                    <x-icons.trash class="h-4 w-4" />
                    <span wire:loading.remove wire:target="clearCache">{{ __('Clear Cache') }}</span>
                    <span wire:loading wire:target="clearCache">{{ __('Clearing...') }}</span>
                </x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="syncNow">
                    <x-icons.refresh-cw class="h-4 w-4" />
                    {{ __('Sync Now') }}
                </x-ui.button>
                <x-ui.button variant="secondary" size="sm" wire:click="openConnectModal" title="{{ __('Plugin Settings') }}">
                    <x-icons.settings class="h-4 w-4" />
                </x-ui.button>
            @else
                <x-ui.button variant="primary" size="sm" wire:click="openConnectModal">
                    <x-icons.link class="h-4 w-4" />
                    {{ __('Connect Plugin') }}
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.circuit-breaker-banner :site="$site" />

    <x-ui.job-progress job-key="sync" :jobs="$trackedJobs" title="Syncing site data..." />

    {{-- Wide main column + sticky rail.
         The three equal columns this replaced put Updates — the thing you
         actually come here to act on — into a third of the width, and
         `items-start` on unequal stacks left a ragged bottom edge that read as
         masonry. One breakpoint, one wide column, one rail. --}}
    <div class="grid gap-4 lg:grid-cols-4">

        {{-- Principal (75%) — ce faci pe site --}}
        <div class="lg:col-span-3 space-y-6">
            <livewire:sites.detail.site-plugins :site="$site" :embedded="true" />
            @include('livewire.sites.detail.overview._database-card')
            @include('livewire.sites.detail.overview._analytics-performance-card')
            @include('livewire.sites.detail.overview._search-console-card')
            @include('livewire.sites.detail.overview._recent-activity-card')
        </div>

        {{-- Rail (25%, lipicios) — starea site-ului dintr-o privire.
             „De rezolvat" stă primul: e lista de acțiuni, dar ca un card printre
             celelalte, nu o bandă pe toată lățimea înaintea conținutului. --}}
        <div>
            <div class="sticky top-20 space-y-4">
                <livewire:sites.detail.site-todo-feed :site="$site" :key="'todo-'.$site->id" />
                @include('livewire.sites.detail.overview._health-bar')
                @include('livewire.sites.detail.overview._site-info-card')
                @include('livewire.sites.detail.overview._server-resources-card')
                @include('livewire.sites.detail.overview._uptime-card')
                @include('livewire.sites.detail.overview._backups-card')
                @include('livewire.sites.detail.overview._security-card')
                @include('livewire.sites.detail.overview._reports-card')
                @include('livewire.sites.detail.overview._dns-card')
                @include('livewire.sites.detail.overview._error-logs-card')
                @include('livewire.sites.detail.overview._client-card')
            </div>
        </div>

    </div>

    {{-- Connect Plugin Modal --}}
    <x-ui.modal name="connect-plugin" maxWidth="lg">
        <div class="p-6">
            <h2 id="modal-connect-plugin-title" class="text-lg font-semibold text-gray-900">{{ __('Connect WordPress Plugin') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Follow these steps to connect your WordPress site.') }}</p>

            <div class="mt-4 rounded-lg bg-gray-50 p-4">
                <ol class="space-y-3 text-sm text-gray-700">
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-100 text-xs font-semibold text-accent-600">1</span>
                        <span><a href="{{ route('download.connector-plugin') }}" class="font-medium text-accent-600 hover:text-accent-500 underline">{{ __('Download the connector plugin') }}</a> (.zip file)</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-100 text-xs font-semibold text-accent-600">2</span>
                        <span>{{ __('Install & activate it in your WordPress site (Plugins → Add New → Upload)') }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-100 text-xs font-semibold text-accent-600">3</span>
                        <span>{{ __('Go to') }} <strong>WP Admin &rarr; Settings &rarr; SimpleAD Manager</strong></span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent-100 text-xs font-semibold text-accent-600">4</span>
                        <span>{{ __('Copy the') }} <strong>API Key</strong>, <strong>API Secret</strong>, {{ __('and') }} <strong>API Endpoint</strong> {{ __('into the fields below') }}</span>
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
                           placeholder="{{ __('Paste your API key here') }}" />
                    @error('apiKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="apiSecret" class="block text-sm font-medium text-gray-700">API Secret</label>
                    <x-ui.input wire:model="apiSecret" type="password" id="apiSecret" class="mt-1"
                           placeholder="{{ __('Paste your API secret here') }}" />
                    @error('apiSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center justify-between pt-2">
                    @if($site->is_connected)
                        <button type="button" wire:click="disconnectSite" wire:confirm="{{ __('Are you sure you want to disconnect this site?') }}"
                                class="text-sm font-medium text-red-600 hover:text-red-500">
                            {{ __('Disconnect') }}
                        </button>
                    @else
                        <div></div>
                    @endif

                    <x-ui.button type="submit" variant="primary">
                        {{ __('Save & Connect') }}
                    </x-ui.button>
                </div>
            </form>
        </div>
    </x-ui.modal>
</div>
