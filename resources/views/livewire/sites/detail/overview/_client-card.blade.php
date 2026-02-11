<x-ui.card>
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-pink-100">
                <svg class="h-5 w-5 text-pink-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
            </div>
            <h3 class="text-base font-semibold text-gray-900">Client</h3>
        </div>
    </div>

    {{-- Card Content --}}
    <div class="p-4">
        @if($site->client)
            {{-- Client Name --}}
            <div class="mb-4 text-center">
                <div class="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-pink-100">
                    <span class="text-2xl font-bold text-pink-600">
                        {{ strtoupper(substr($site->client->name, 0, 1)) }}
                    </span>
                </div>
                <h4 class="text-lg font-semibold text-gray-900">{{ $site->client->name }}</h4>
            </div>

            {{-- Client Details --}}
            <div class="space-y-2 border-t border-gray-100 pt-4">
                @if($site->client->email)
                <div class="flex items-center gap-3">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <a href="mailto:{{ $site->client->email }}" class="text-sm text-purple-600 hover:text-purple-700 hover:underline">
                        {{ $site->client->email }}
                    </a>
                </div>
                @endif

                @if($site->client->phone)
                <div class="flex items-center gap-3">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                    <a href="tel:{{ $site->client->phone }}" class="text-sm text-gray-600 hover:text-gray-900">
                        {{ $site->client->phone }}
                    </a>
                </div>
                @endif

                @if($site->client->company)
                <div class="flex items-center gap-3">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <span class="text-sm text-gray-600">{{ $site->client->company }}</span>
                </div>
                @endif
            </div>

            {{-- View Client Button --}}
            <div class="mt-4 border-t border-gray-100 pt-4">
                <x-ui.button href="{{ route('clients.detail', $site->client) }}" color="purple" size="sm" class="w-full">
                    View Client Details
                </x-ui.button>
            </div>
        @else
            <x-ui.empty-state
                title="No client assigned"
                description="Assign a client to this site for billing and reporting."
            >
                <x-slot:actions>
                    <x-ui.button wire:click="openAssignClientModal" color="purple">
                        Assign Client
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        @endif
    </div>
</x-ui.card>
