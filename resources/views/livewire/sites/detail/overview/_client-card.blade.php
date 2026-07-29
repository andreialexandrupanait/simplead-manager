<x-ui.module-card title="Client" icon="users" tone="dark">
    @if($site->client)
        <div class="mb-3 flex items-center gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent-50">
                <span class="text-sm font-bold text-accent-600">
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

        <div class="space-y-1.5">
            @if($site->client->email)
                <a href="mailto:{{ $site->client->email }}" class="flex items-center gap-2 truncate text-xs text-accent-600 hover:text-accent-700 hover:underline">
                    <svg aria-hidden="true" class="h-3.5 w-3.5 flex-shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    {{ $site->client->email }}
                </a>
            @endif
            @if($site->client->phone)
                <a href="tel:{{ $site->client->phone }}" class="flex items-center gap-2 text-xs text-gray-600 hover:text-gray-900">
                    <svg aria-hidden="true" class="h-3.5 w-3.5 flex-shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                    {{ $site->client->phone }}
                </a>
            @endif
        </div>

        <div class="mt-3">
            <x-ui.button href="{{ route('clients.show', $site->client) }}" variant="secondary" size="sm" class="w-full">
                View Client
            </x-ui.button>
        </div>
    @else
        <div class="py-2 text-center">
            <p class="text-sm text-gray-500">No client assigned</p>
            <x-ui.button wire:click="openAssignClientModal" variant="primary" size="sm" class="mt-2" wire:loading.attr="disabled">
                Assign Client
            </x-ui.button>
        </div>
    @endif
</x-ui.module-card>
