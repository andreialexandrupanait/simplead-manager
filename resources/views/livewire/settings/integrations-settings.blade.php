<div>
    @include('livewire.settings.partials.settings-tabs')

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="error" />
    <x-ui.flash-alert type="success" key="storage-success" />
    <x-ui.flash-alert type="error" key="storage-error" />

    <div class="space-y-6">
        {{-- Card 1: Storage Destinations --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900">Storage Destinations</h3>
                <x-ui.button variant="secondary" size="sm" x-on:click="$dispatch('open-storage-form')">
                    <x-icons.plus class="mr-1 h-3.5 w-3.5" />
                    Add Storage
                </x-ui.button>
            </div>

            @if($this->destinations->isEmpty())
                <p class="text-sm text-gray-500">No storage destinations configured. Add one to start creating backups.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($this->destinations as $destination)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <div class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center
                                    {{ match($destination->type) {
                                        'local' => 'bg-gray-100 text-gray-600',
                                        'dropbox' => 'bg-blue-100 text-blue-600',
                                        's3' => 'bg-orange-100 text-orange-600',
                                        default => 'bg-gray-100 text-gray-600',
                                    } }}">
                                    @if($destination->type === 'local')
                                        <x-icons.hard-drive class="w-4 h-4" />
                                    @elseif($destination->type === 'dropbox')
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75zm-5.97 4.49L6 14l-.03-.01L0 17.24v1.52l6.03-3.75L12 18.76v-1.52l-5.97-3.25zm11.94 0L12 17.24v1.52l5.97-3.25L24 18.76v-1.52l-6.03-3.25z"/></svg>
                                    @else
                                        <x-icons.cloud class="w-4 h-4" />
                                    @endif
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ $destination->name }}
                                        @if($destination->is_default)
                                            <x-ui.badge variant="purple" class="ml-1">Default</x-ui.badge>
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ ucfirst($destination->type) }}
                                        @if($destination->last_tested_at)
                                            &middot; Last tested {{ $destination->last_tested_at->diffForHumans() }}
                                            @if($destination->last_test_passed)
                                                <span class="text-green-600">Passed</span>
                                            @else
                                                <span class="text-red-600">Failed</span>
                                            @endif
                                        @endif
                                        @if($destination->used_bytes > 0)
                                            &middot; {{ $destination->used_formatted }} used
                                        @endif
                                    </div>
                                    @if($destination->type === 'dropbox')
                                        <div class="mt-1 flex flex-col gap-0.5">
                                            @if($destination->config['base_path'] ?? null)
                                                <div class="flex items-center gap-1.5 text-xs text-gray-400">
                                                    <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                                    <span class="truncate">Backups: {{ $destination->config['base_path'] }}</span>
                                                </div>
                                            @endif
                                            @if($destination->config['reports_path'] ?? null)
                                                <div class="flex items-center gap-1.5 text-xs text-gray-400">
                                                    <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                    <span class="truncate">Reports: {{ $destination->config['reports_path'] }}</span>
                                                </div>
                                            @endif
                                            @if($destination->config['app_backups_path'] ?? null)
                                                <div class="flex items-center gap-1.5 text-xs text-gray-400">
                                                    <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                                                    <span class="truncate">App backups: {{ $destination->config['app_backups_path'] }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    @elseif($destination->type === 'local' && ($destination->config['path'] ?? null))
                                        <div class="mt-1 flex items-center gap-1.5 text-xs text-gray-400">
                                            <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                            <span class="truncate">{{ $destination->config['path'] }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="testDestination({{ $destination->id }})"
                                    class="rounded p-1.5 text-gray-400 hover:text-blue-600 hover:bg-blue-50"
                                    title="Test Connection">
                                    <x-icons.check-circle class="w-4 h-4" />
                                </button>
                                @if(!$destination->is_default)
                                    <button wire:click="setDefault({{ $destination->id }})"
                                        class="rounded p-1.5 text-gray-400 hover:text-purple-600 hover:bg-purple-50"
                                        title="Set as Default">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                                    </button>
                                @endif
                                <button wire:click="$dispatch('open-storage-form', { destinationId: {{ $destination->id }} })"
                                    class="rounded p-1.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                                    title="Edit">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                </button>
                                <button wire:click="deleteDestination({{ $destination->id }})"
                                    wire:confirm="Are you sure you want to delete this storage destination?"
                                    class="rounded p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50"
                                    title="Delete">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Dropbox credentials --}}
            <div class="border-t border-gray-200 mt-5 pt-5">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-sm font-medium text-gray-900">Dropbox API Credentials</h4>
                    @if($dropboxAppKey && $dropboxAppSecret)
                        <x-ui.badge variant="green">Configured</x-ui.badge>
                    @else
                        <x-ui.badge variant="red">Not Configured</x-ui.badge>
                    @endif
                </div>

                <form wire:submit="saveDropboxCredentials" class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">App Key</label>
                        <x-ui.input type="text" wire:model="dropboxAppKey" placeholder="Enter Dropbox App Key" />
                        @error('dropboxAppKey') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">App Secret</label>
                        <x-ui.input type="password" wire:model="dropboxAppSecret" placeholder="Enter Dropbox App Secret" />
                        @error('dropboxAppSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end">
                        <x-ui.button type="submit" wire:loading.attr="disabled" size="sm">
                            Save Credentials
                        </x-ui.button>
                    </div>
                </form>

                <div x-data="{ showInstructions: false }" class="mt-4">
                    <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                        <svg class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        How to get your Dropbox API credentials
                    </button>
                    <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                        <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                            <li>Go to the <a href="https://www.dropbox.com/developers/apps" target="_blank" class="font-medium underline hover:text-blue-900">Dropbox App Console</a></li>
                            <li>Click <strong>Create app</strong> (or select an existing one)</li>
                            <li>Choose <strong>Scoped access</strong> and <strong>Full Dropbox</strong> access type</li>
                            <li>Under the <strong>Permissions</strong> tab, enable: <code class="bg-blue-100 px-1 rounded text-xs">account_info.read</code>, <code class="bg-blue-100 px-1 rounded text-xs">files.metadata.read</code>, <code class="bg-blue-100 px-1 rounded text-xs">files.content.read</code>, <code class="bg-blue-100 px-1 rounded text-xs">files.content.write</code></li>
                            <li>Under the <strong>Settings</strong> tab, add this <strong>Redirect URI</strong>: <code class="bg-blue-100 px-1 rounded text-xs">{{ route('dropbox.callback') }}</code></li>
                            <li>Copy the <strong>App key</strong> and <strong>App secret</strong> from the Settings tab and paste them below</li>
                        </ol>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <livewire:settings.components.storage-destination-form />

        {{-- Card 3: Google --}}
        <x-ui.card wire:key="google-card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900">Google</h3>
                <button wire:click="addAccount"
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium border border-gray-300 bg-white text-gray-700 shadow-sm transition hover:bg-gray-50">
                    <svg class="w-4 h-4" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
                    Add Google Account
                </button>
            </div>

            @if($connections->isEmpty())
                <p class="text-sm text-gray-500">No Google accounts connected. Add one to use Analytics and Search Console integrations.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($connections as $conn)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                @if($conn->avatar_url)
                                    <img src="{{ $conn->avatar_url }}" alt="" class="h-8 w-8 rounded-full">
                                @else
                                    <div class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center bg-red-100 text-red-600">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                    </div>
                                @endif
                                <div>
                                    <div class="text-sm font-medium text-gray-900">{{ $conn->email }}</div>
                                    <div class="text-xs text-gray-500">
                                        Connected {{ $conn->created_at->format('M d, Y') }}
                                        @if($conn->sites_using > 0)
                                            &middot; Used by {{ $conn->sites_using }} {{ Str::plural('site', $conn->sites_using) }}
                                        @endif
                                    </div>
                                    @if($conn->scopes)
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach($conn->scopes as $scope)
                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                                    {{ str_replace('.readonly', '', $scope) === 'analytics' ? 'Analytics' : 'Search Console' }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <button
                                wire:click="confirmDisconnect({{ $conn->id }})"
                                class="rounded p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50"
                                title="Disconnect"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- API Credentials --}}
            <div class="border-t border-gray-200 mt-5 pt-5">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-sm font-medium text-gray-900">API Credentials</h4>
                    @if($googleClientId && $googleClientSecret)
                        <x-ui.badge variant="green">Configured</x-ui.badge>
                    @else
                        <x-ui.badge variant="red">Not Configured</x-ui.badge>
                    @endif
                </div>

                <form wire:submit="saveGoogleCredentials" class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client ID</label>
                        <x-ui.input type="text" wire:model="googleClientId" placeholder="Enter Google Client ID" />
                        @error('googleClientId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Client Secret</label>
                        <x-ui.input type="password" wire:model="googleClientSecret" placeholder="Enter Google Client Secret" />
                        @error('googleClientSecret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end">
                        <x-ui.button type="submit" wire:loading.attr="disabled" size="sm">
                            Save Credentials
                        </x-ui.button>
                    </div>
                </form>

                <div x-data="{ showInstructions: false }" class="mt-4">
                    <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                        <svg class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        How to get your Google API credentials
                    </button>
                    <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                        <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                            <li>Go to the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="font-medium underline hover:text-blue-900">Google Cloud Console &rarr; Credentials</a></li>
                            <li>Create or select an <strong>OAuth 2.0 Client ID</strong> (type: Web application)</li>
                            <li>Under <strong>Authorized redirect URIs</strong>, add: <code class="bg-blue-100 px-1 rounded text-xs">{{ config('services.google.redirect_uri') ?: url('/google/callback') }}</code></li>
                            <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> and paste them below</li>
                        </ol>
                        <p class="mt-2 text-xs text-blue-600">Make sure the <strong>Google Analytics API</strong> and <strong>Google Search Console API</strong> are enabled in your project.</p>
                    </div>
                </div>
            </div>
        </x-ui.card>

        {{-- Card 4: Cloudflare --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900">Cloudflare</h3>
            </div>

            @if($this->cloudflareConnections->isEmpty())
                <p class="text-sm text-gray-500">No Cloudflare connections configured. Add one to manage DNS and security.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($this->cloudflareConnections as $cfConn)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <div class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center bg-orange-100 text-orange-600">
                                    <x-icons.cloud class="w-4 h-4" />
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ $cfConn->account_email ?: 'Connection #' . $cfConn->id }}
                                        <x-ui.badge :variant="$cfConn->is_valid ? 'green' : 'red'" class="ml-1">{{ $cfConn->is_valid ? 'Valid' : 'Invalid' }}</x-ui.badge>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ $cfConn->siteCloudflare->count() }} {{ Str::plural('zone', $cfConn->siteCloudflare->count()) }} connected
                                        @if($cfConn->last_validated_at)
                                            &middot; Tested {{ $cfConn->last_validated_at->diffForHumans() }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="testCloudflareConnection({{ $cfConn->id }})"
                                    class="rounded p-1.5 text-gray-400 hover:text-blue-600 hover:bg-blue-50"
                                    title="Test Connection">
                                    <x-icons.check-circle class="w-4 h-4" />
                                </button>
                                <button wire:click="confirmDeleteCloudflare({{ $cfConn->id }})"
                                    class="rounded p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50"
                                    title="Delete">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Add connection --}}
            <div class="border-t border-gray-200 mt-5 pt-5">
                <h4 class="text-sm font-medium text-gray-900 mb-4">Add Connection</h4>
                <div class="flex items-end gap-3">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700 mb-1">API Token</label>
                        <x-ui.input type="password" wire:model="cfApiToken" placeholder="Enter Cloudflare API token" />
                    </div>
                    <button wire:click="addCloudflareConnection" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium text-white transition hover:opacity-90"
                            style="background-color: #F6821F;">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M16.51 15.45c.15-.51.08-.98-.2-1.34a1.13 1.13 0 0 0-.93-.42H7.64c-.1 0-.18-.04-.22-.12a.24.24 0 0 1 0-.24c.04-.08.12-.15.22-.17a.56.56 0 0 0 .1-.02h8.03c1.08-.05 2.24-.87 2.65-1.95l.52-1.37c.02-.05.03-.1.03-.16 0-.04.02-.08.02-.12a4.35 4.35 0 0 0-8.37-1.43 2.2 2.2 0 0 0-1.53-.36 2.26 2.26 0 0 0-1.93 1.88 3.27 3.27 0 0 0-2.84 3.2c0 .1.01.2.02.3h-.08A2.57 2.57 0 0 0 2 15.67c0 .13.01.25.03.37.02.1.1.17.2.17h13.85c.1 0 .2-.07.23-.17l.2-.59zM19.35 11.06a.17.17 0 0 0-.17.13l-.24.82c-.15.51-.08.98.2 1.34.24.31.63.47 1.07.48l1.53.04c.1 0 .18.04.22.12a.24.24 0 0 1 0 .24c-.04.08-.12.15-.22.17a.56.56 0 0 0-.1.02l-1.58.04c-1.08.05-2.24.87-2.65 1.95l-.14.39c-.03.08.02.15.1.15h5.2c.1 0 .17-.06.2-.15a5.26 5.26 0 0 0-3.42-5.74z"/></svg>
                        Connect
                    </button>
                </div>

                <div x-data="{ showInstructions: false }" class="mt-4">
                    <button @click="showInstructions = !showInstructions" class="text-xs text-gray-400 hover:text-gray-600 flex items-center gap-1">
                        <svg class="h-3.5 w-3.5 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        How to get your Cloudflare API token
                    </button>
                    <div x-show="showInstructions" x-collapse x-cloak class="rounded-lg bg-blue-50 border border-blue-200 p-4 mt-2">
                        <ol class="text-sm text-blue-800 space-y-2 list-decimal list-inside">
                            <li>Log in to your <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" class="font-medium underline hover:text-blue-900">Cloudflare dashboard</a> and go to <strong>My Profile &rarr; API Tokens</strong></li>
                            <li>Click <strong>Create Token</strong></li>
                            <li>Use the <strong>"Edit zone DNS"</strong> template, or create a custom token with these permissions:
                                <ul class="ml-5 mt-1 list-disc text-xs text-blue-700 space-y-0.5">
                                    <li><strong>Zone &rarr; Zone &rarr; Read</strong> (list and view zones)</li>
                                    <li><strong>Zone &rarr; DNS &rarr; Edit</strong> (manage DNS records)</li>
                                    <li><strong>Zone &rarr; Zone Settings &rarr; Read</strong> (SSL, security level, WAF)</li>
                                    <li><strong>Zone &rarr; Firewall Services &rarr; Edit</strong> (firewall rules, access rules)</li>
                                    <li><strong>Zone &rarr; Cache Purge &rarr; Purge</strong> (cache management)</li>
                                    <li><strong>Zone &rarr; Analytics &rarr; Read</strong> (zone analytics)</li>
                                </ul>
                            </li>
                            <li>Under <strong>Zone Resources</strong>, select the zones you want to manage (or "All zones")</li>
                            <li>Click <strong>Continue to summary</strong> &rarr; <strong>Create Token</strong></li>
                            <li>Copy the token and paste it below</li>
                        </ol>
                        <p class="mt-2 text-xs text-blue-600">After connecting, go to any site's <strong>Cloudflare</strong> page to link it to a zone.</p>
                    </div>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Disconnect confirmation modal --}}
    <x-ui.modal name="disconnect-google">
        <h2 class="text-lg font-semibold text-gray-900">Disconnect Google Account</h2>
        <p class="mt-2 text-sm text-gray-600">
            Are you sure you want to disconnect this Google account? This will also remove all associated Analytics and Search Console connections for all sites using this account.
        </p>

        <div class="mt-4 flex justify-end gap-2">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal-disconnect-google')">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="disconnectAccount">Disconnect</x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Delete Cloudflare connection confirmation modal --}}
    <x-ui.modal name="delete-cloudflare">
        <h2 class="text-lg font-semibold text-gray-900">Delete Cloudflare Connection</h2>
        <p class="mt-2 text-sm text-gray-600">
            Are you sure you want to delete this Cloudflare connection? All sites linked through this connection will be disconnected from Cloudflare.
        </p>

        <div class="mt-4 flex justify-end gap-2">
            <x-ui.button variant="secondary" @click="$dispatch('close-modal-delete-cloudflare')">Cancel</x-ui.button>
            <x-ui.button variant="danger" wire:click="deleteCloudflareConnection">Delete</x-ui.button>
        </div>
    </x-ui.modal>
</div>
