<div>
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-4">
            <div class="flex flex-1 items-center gap-4">
                @if($client->logo)
                    <img src="{{ Storage::disk('public')->url($client->logo) }}" alt="{{ $client->name }}" class="h-14 w-14 rounded-full object-contain border">
                @else
                    <x-client-avatar :client="$client" size="lg" />
                @endif
                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold text-gray-900">{{ $client->name }}</h1>
                        @php
                            $statusVariant = match($client->status) {
                                'active' => 'green',
                                'inactive' => 'yellow',
                                'archived' => 'gray',
                                default => 'gray',
                            };
                        @endphp
                        <x-ui.badge :variant="$statusVariant">{{ ucfirst($client->status) }}</x-ui.badge>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-4 text-sm text-gray-500">
                        @if($client->email)
                            <a href="mailto:{{ $client->email }}" class="flex items-center gap-1 hover:text-gray-700">
                                <x-icons.mail class="h-4 w-4" />
                                {{ $client->email }}
                            </a>
                        @endif
                        @if($client->phone)
                            <a href="tel:{{ $client->phone }}" class="flex items-center gap-1 hover:text-gray-700">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                {{ $client->phone }}
                            </a>
                        @endif
                        @if($client->website)
                            <a href="{{ $client->website }}" target="_blank" rel="noopener" class="flex items-center gap-1 hover:text-gray-700">
                                <x-icons.globe class="h-4 w-4" />
                                {{ parse_url($client->website, PHP_URL_HOST) }}
                            </a>
                        @endif
                    </div>
                </div>
                <div class="flex gap-2">
                    @if($client->portal_enabled && $client->portal_token)
                        <a href="{{ route('client-portal.show', $client->portal_token) }}" target="_blank" rel="noopener">
                            <x-ui.button variant="secondary">
                                <svg class="mr-1.5 h-4 w-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                {{ __('Open Portal') }}
                            </x-ui.button>
                        </a>
                    @endif
                    <a href="{{ route('clients.edit', $client) }}">
                        <x-ui.button variant="secondary">{{ __('Edit') }}</x-ui.button>
                    </a>
                    <x-ui.button variant="danger" wire:click="confirmDelete">{{ __('Delete') }}</x-ui.button>
                </div>
            </div>
        </div>
    </div>

    {{-- Main Content --}}
    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Details Card --}}
        <x-ui.card>
            <h2 class="mb-4 text-lg font-medium text-gray-900">{{ __('Details') }}</h2>
            <dl class="space-y-3">
                @if($client->company)
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">{{ __('Company') }}</dt>
                        <dd class="text-sm font-medium text-gray-900">{{ $client->company }}</dd>
                    </div>
                @endif
                @if($client->vat_number)
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">{{ __('VAT Number') }}</dt>
                        <dd class="text-sm font-medium text-gray-900">{{ $client->vat_number }}</dd>
                    </div>
                @endif
                @if($client->registration_number)
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">{{ __('Registration Number') }}</dt>
                        <dd class="text-sm font-medium text-gray-900">{{ $client->registration_number }}</dd>
                    </div>
                @endif
                @if($client->address || $client->city || $client->country)
                    <div class="flex justify-between">
                        <dt class="text-sm text-gray-500">{{ __('Address') }}</dt>
                        <dd class="text-right text-sm font-medium text-gray-900">
                            @if($client->address){{ $client->address }}<br>@endif
                            {{ collect([$client->city, $client->country])->filter()->implode(', ') }}
                        </dd>
                    </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-sm text-gray-500">{{ __('Created') }}</dt>
                    <dd class="text-sm font-medium text-gray-900">{{ $client->created_at->format('M j, Y') }}</dd>
                </div>
            </dl>

            @if($client->notes)
                <div class="mt-6 border-t border-gray-100 pt-4">
                    <h3 class="mb-2 text-sm font-medium text-gray-700">{{ __('Notes') }}</h3>
                    <p class="text-sm text-gray-600 whitespace-pre-line">{{ $client->notes }}</p>
                </div>
            @endif
        </x-ui.card>

        {{-- Sites Card --}}
        <x-ui.card>
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-medium text-gray-900">{{ __('Sites') }}</h2>
                <a href="{{ route('sites.create') }}?client_id={{ $client->id }}" class="text-sm font-medium text-accent hover:text-accent-hover">
                    + {{ __('Add Site') }}
                </a>
            </div>
            @if($client->sites->count())
                <div class="space-y-3">
                    @foreach($client->sites as $site)
                        <a href="{{ route('sites.overview', $site) }}" class="flex items-center justify-between rounded-lg border p-3 hover:bg-gray-50 transition">
                            <div class="flex items-center gap-3">
                                <x-site-favicon :site="$site" />
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ $site->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $site->domain }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if($site->ssl_valid)
                                    <x-ui.badge variant="green">SSL</x-ui.badge>
                                @endif
                                <x-ui.badge :variant="$site->is_up ? 'green' : 'red'">
                                    {{ $site->is_up ? __('Online') : __('Offline') }}
                                </x-ui.badge>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500">{{ __('No sites assigned to this client.') }}</p>
            @endif
        </x-ui.card>
    </div>

    {{-- Client Portal --}}
    <x-ui.card class="mt-6">
        <h3 class="text-base font-semibold text-gray-900 mb-3">{{ __('Client Portal') }}</h3>
        <p class="text-sm text-gray-500 mb-3">{{ __('Share a link with your client so they can view reports and site status without logging in.') }}</p>

        <div class="flex items-center gap-3">
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:click="togglePortal" @checked($client->portal_enabled)
                       class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                <span class="text-sm text-gray-700">{{ __('Enable portal') }}</span>
            </label>
        </div>

        @if($client->portal_enabled && $client->portal_token)
            <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3">
                <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('Portal URL') }}</label>
                <div class="flex items-center gap-2">
                    <code class="flex-1 rounded bg-white border px-3 py-1.5 text-xs font-mono text-gray-900 select-all truncate">{{ route('client-portal.show', $client->portal_token) }}</code>
                    <button wire:click="regeneratePortalToken" wire:confirm="{{ __('Regenerate token? The old link will stop working.') }}"
                            class="text-xs text-gray-500 hover:text-purple-600 whitespace-nowrap">{{ __('Regenerate') }}</button>
                </div>
            </div>
        @endif
    </x-ui.card>

    {{-- Delete Confirmation Modal --}}
    <x-ui.modal name="delete-client" maxWidth="sm">
        <div class="text-center">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
                <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h3 class="mb-2 text-lg font-medium text-gray-900">{{ __('Delete Client') }}</h3>
            <p class="mb-6 text-sm text-gray-500">{{ __('Are you sure you want to delete ":name"? This action cannot be undone.', ['name' => $client->name]) }}</p>
            <div class="flex justify-center gap-3">
                <x-ui.button variant="secondary" @click="$dispatch('close-modal-delete-client')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete">{{ __('Delete') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
