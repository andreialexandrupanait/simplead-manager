<x-ui.card :padding="false" class="flex flex-col">
    {{-- Card Header --}}
    <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2.5">
        <div class="flex items-center gap-2">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-pink-100">
                <svg class="h-4 w-4 text-pink-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">Client</h3>
        </div>
    </div>

    {{-- Card Content --}}
    <div class="flex flex-1 flex-col p-3">
        @if($site->client)
            <div class="flex items-center gap-3 mb-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-pink-100 shrink-0">
                    <span class="text-sm font-bold text-pink-600">
                        {{ strtoupper(substr($site->client->name, 0, 1)) }}
                    </span>
                </div>
                <div class="min-w-0">
                    <h4 class="truncate text-sm font-semibold text-gray-900">{{ $site->client->name }}</h4>
                    @if($site->client->company)
                        <p class="truncate text-xs text-gray-500">{{ $site->client->company }}</p>
                    @endif
                </div>
            </div>

            <div class="flex-1 space-y-1.5">
                @if($site->client->email)
                    <a href="mailto:{{ $site->client->email }}" class="flex items-center gap-2 text-xs text-purple-600 hover:text-purple-700 hover:underline truncate">
                        <svg class="h-3.5 w-3.5 flex-shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        {{ $site->client->email }}
                    </a>
                @endif
                @if($site->client->phone)
                    <a href="tel:{{ $site->client->phone }}" class="flex items-center gap-2 text-xs text-gray-600 hover:text-gray-900">
                        <svg class="h-3.5 w-3.5 flex-shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                        {{ $site->client->phone }}
                    </a>
                @endif
            </div>

            <div class="mt-3 border-t border-gray-100 pt-3">
                <x-ui.button href="{{ route('clients.show', $site->client) }}" color="purple" size="sm" class="w-full">
                    View Client
                </x-ui.button>
            </div>
        @else
            <div class="py-2 text-center">
                <p class="text-sm text-gray-500">No client assigned</p>
                <x-ui.button wire:click="openAssignClientModal" color="purple" size="sm" class="mt-2">
                    Assign Client
                </x-ui.button>
            </div>
        @endif
    </div>
</x-ui.card>
