<div>
    @include('livewire.settings.partials.settings-tabs')

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <div class="max-w-2xl">
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
</div>
