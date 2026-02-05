<div>
    @include('livewire.settings.partials.settings-tabs')

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif
    @if(session('storage-success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('storage-success') }}</div>
    @endif
    @if(session('storage-error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('storage-error') }}</div>
    @endif

    <div class="space-y-6">
        {{-- Section 1: Storage Destinations --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900">Storage Destinations</h3>
                <x-ui.button size="sm" x-on:click="$dispatch('open-storage-form')">
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
                                {{-- Type icon --}}
                                <div class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center
                                    {{ match($destination->type) {
                                        'local' => 'bg-gray-100 text-gray-600',
                                        'dropbox' => 'bg-blue-100 text-blue-600',
                                        's3' => 'bg-orange-100 text-orange-600',
                                        default => 'bg-gray-100 text-gray-600',
                                    } }}">
                                    @if($destination->type === 'local')
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                                    @elseif($destination->type === 'dropbox')
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75zm-5.97 4.49L6 14l-.03-.01L0 17.24v1.52l6.03-3.75L12 18.76v-1.52l-5.97-3.25zm11.94 0L12 17.24v1.52l5.97-3.25L24 18.76v-1.52l-6.03-3.25z"/></svg>
                                    @else
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                                    @endif
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ $destination->name }}
                                        @if($destination->is_default)
                                            <span class="ml-1 inline-flex items-center rounded-full bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700">Default</span>
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
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="testDestination({{ $destination->id }})"
                                    class="rounded p-1.5 text-gray-400 hover:text-blue-600 hover:bg-blue-50"
                                    title="Test Connection">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
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
        </x-ui.card>

        <livewire:settings.components.storage-destination-form />

        {{-- Section 2: Google Accounts --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900">Google Accounts</h3>
                <x-ui.button wire:click="addAccount" size="sm">
                    <svg class="mr-1 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Account
                </x-ui.button>
            </div>

            @if($connections->isEmpty())
                <x-ui.empty-state
                    title="No Google accounts connected"
                    description="Connect a Google account to use Google Analytics and Search Console integrations."
                    icon="globe"
                />
            @else
                <div class="space-y-3">
                    @foreach($connections as $conn)
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    @if($conn->avatar_url)
                                        <img src="{{ $conn->avatar_url }}" alt="" class="h-10 w-10 rounded-full">
                                    @else
                                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-purple-100 text-purple-700">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                        </div>
                                    @endif
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">{{ $conn->email }}</div>
                                        <div class="mt-0.5 text-xs text-gray-500">
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
                                    class="text-sm text-gray-400 hover:text-red-600 transition"
                                >
                                    Disconnect
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        {{-- Section 3: Cloudflare Connections --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900">Cloudflare Connections</h3>
            </div>

            {{-- Setup instructions --}}
            <div x-data="{ showInstructions: false }" class="mb-4">
                <button @click="showInstructions = !showInstructions" class="text-sm text-purple-600 hover:text-purple-700 flex items-center gap-1 mb-3">
                    <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-90': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    How to get your Cloudflare API token
                </button>
                <div x-show="showInstructions" x-collapse class="rounded-lg bg-blue-50 border border-blue-200 p-4 mb-4">
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

            {{-- Add connection form --}}
            <div class="mb-4 flex items-end gap-3">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">API Token</label>
                    <input type="password" wire:model="cfApiToken"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500"
                        placeholder="Enter Cloudflare API token">
                </div>
                <x-ui.button wire:click="addCloudflareConnection" wire:loading.attr="disabled" size="sm">
                    Connect
                </x-ui.button>
            </div>

            @if($this->cloudflareConnections->isEmpty())
                <p class="text-sm text-gray-500">No Cloudflare connections configured.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($this->cloudflareConnections as $cfConn)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <div class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center bg-orange-100 text-orange-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" /></svg>
                                </div>
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        {{ $cfConn->account_email ?: 'Connection #' . $cfConn->id }}
                                        @if($cfConn->is_valid)
                                            <span class="ml-1 inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Valid</span>
                                        @else
                                            <span class="ml-1 inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">Invalid</span>
                                        @endif
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
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
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
