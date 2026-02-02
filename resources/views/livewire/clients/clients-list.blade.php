<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Clients</h1>
            <p class="mt-1 text-sm text-gray-500">Manage your client accounts</p>
        </div>
    </div>

    <div class="mb-6">
        <div class="relative max-w-md">
            <x-icons.search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <x-ui.input wire:model.live.debounce.300ms="search" type="text" placeholder="Search clients..." class="pl-10" />
        </div>
    </div>

    @if($clients->count())
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($clients as $client)
                <a href="{{ route('clients.show', $client) }}">
                    <x-ui.card class="hover:shadow-md transition">
                        <h3 class="font-semibold text-gray-900">{{ $client->name }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ $client->email }}</p>
                        <div class="mt-3">
                            <x-ui.badge variant="purple">{{ $client->sites_count }} sites</x-ui.badge>
                        </div>
                    </x-ui.card>
                </a>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $clients->links() }}
        </div>
    @else
        <x-ui.empty-state
            title="No clients found"
            description="Add your first client to get started."
            icon="users"
        />
    @endif
</div>
