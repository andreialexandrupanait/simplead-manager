<div>
    {{-- Header with Add Button --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header :title="__('Clients')" :subtitle="__('Manage your clients and their sites')" />
        <a href="{{ route('clients.create') }}">
            <x-ui.button>
                <x-icons.plus class="h-4 w-4" />
                {{ __('Add Client') }}
            </x-ui.button>
        </a>
    </div>

    {{-- Search & Filter Bar --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        {{-- Status Filter Pills --}}
        <div class="flex rounded-lg bg-gray-100 p-1">
            @foreach(['all' => __('All'), 'active' => __('Active'), 'inactive' => __('Inactive'), 'archived' => __('Archived')] as $value => $label)
                <button wire:click="$set('statusFilter', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $statusFilter === $value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                    {{ $label }}
                    <span class="ml-1 text-xs text-gray-400">({{ $this->statusCounts[$value] }})</span>
                </button>
            @endforeach
        </div>

        {{-- Search --}}
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search clients...') }}"
            class="w-full sm:ml-auto sm:w-64"
        />
    </div>

    {{-- Clients Table --}}
    @if($clients->count())
        <x-ui.card class="overflow-hidden !p-0">
            {{-- Mobile cards --}}
            <div class="md:hidden divide-y divide-gray-200">
                @foreach($clients as $client)
                    @php
                        $statusVariant = match($client->status) {
                            'active' => 'green',
                            'inactive' => 'yellow',
                            'archived' => 'gray',
                            default => 'gray',
                        };
                    @endphp
                    <div class="p-3">
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('clients.show', $client) }}" class="flex items-center gap-3 min-w-0">
                                <x-client-avatar :client="$client" size="sm" />
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900 truncate">{{ $client->name }}</p>
                                    @if($client->email)
                                        <p class="text-xs text-gray-500 truncate">{{ $client->email }}</p>
                                    @endif
                                </div>
                            </a>
                            <x-ui.dropdown align="right">
                                <x-slot:trigger>
                                    <button class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 shrink-0">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/>
                                        </svg>
                                    </button>
                                </x-slot:trigger>

                                <a href="{{ route('clients.show', $client) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('View') }}</a>
                                <a href="{{ route('clients.edit', $client) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('Edit') }}</a>
                                <div class="my-1 border-t border-gray-100"></div>
                                @if($client->status !== 'active')
                                    <button wire:click="changeStatus({{ $client->id }}, 'active')" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">{{ __('Set Active') }}</button>
                                @endif
                                @if($client->status !== 'inactive')
                                    <button wire:click="changeStatus({{ $client->id }}, 'inactive')" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">{{ __('Set Inactive') }}</button>
                                @endif
                                @if($client->status !== 'archived')
                                    <button wire:click="changeStatus({{ $client->id }}, 'archived')" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">{{ __('Archive') }}</button>
                                @endif
                                <div class="my-1 border-t border-gray-100"></div>
                                <button wire:click="confirmDelete({{ $client->id }})" class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">{{ __('Delete') }}</button>
                            </x-ui.dropdown>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <x-ui.badge :variant="$statusVariant">{{ ucfirst($client->status) }}</x-ui.badge>
                            <x-ui.badge variant="purple">{{ $client->sites_count }} {{ Str::plural('site', $client->sites_count) }}</x-ui.badge>
                            @if($client->phone)
                                <span class="text-xs text-gray-500">{{ $client->phone }}</span>
                            @endif
                        </div>
                        <p class="mt-1.5 text-xs text-gray-400">{{ __('Created') }}: {{ $client->created_at->format('M j, Y') }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block">
            <x-ui.table>
                <thead>
                    <tr class="bg-gray-50">
                        <x-ui.th class="cursor-pointer" wire:click="sort('name')">
                            <span class="flex items-center gap-1">
                                {{ __('Client') }}
                                @if($sortBy === 'name')
                                    <svg class="h-4 w-4 {{ $sortDir === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                @endif
                            </span>
                        </x-ui.th>
                        <x-ui.th>{{ __('Phone') }}</x-ui.th>
                        <x-ui.th class="cursor-pointer" wire:click="sort('sites_count')">
                            <span class="flex items-center gap-1">
                                {{ __('Sites') }}
                                @if($sortBy === 'sites_count')
                                    <svg class="h-4 w-4 {{ $sortDir === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                @endif
                            </span>
                        </x-ui.th>
                        <x-ui.th class="cursor-pointer" wire:click="sort('status')">
                            <span class="flex items-center gap-1">
                                {{ __('Status') }}
                                @if($sortBy === 'status')
                                    <svg class="h-4 w-4 {{ $sortDir === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                @endif
                            </span>
                        </x-ui.th>
                        <x-ui.th class="cursor-pointer" wire:click="sort('created_at')">
                            <span class="flex items-center gap-1">
                                {{ __('Created') }}
                                @if($sortBy === 'created_at')
                                    <svg class="h-4 w-4 {{ $sortDir === 'desc' ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                @endif
                            </span>
                        </x-ui.th>
                        <x-ui.th class="text-right">{{ __('Actions') }}</x-ui.th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($clients as $client)
                        <tr class="hover:bg-gray-50">
                            <x-ui.td>
                                <a href="{{ route('clients.show', $client) }}" class="flex items-center gap-3">
                                    <x-client-avatar :client="$client" size="sm" />
                                    <div>
                                        <p class="font-medium text-gray-900">{{ $client->name }}</p>
                                        @if($client->email)
                                            <p class="text-xs text-gray-500">{{ $client->email }}</p>
                                        @endif
                                    </div>
                                </a>
                            </x-ui.td>
                            <x-ui.td>
                                @if($client->phone)
                                    <span class="text-sm text-gray-600">{{ $client->phone }}</span>
                                @else
                                    <span class="text-sm text-gray-400">-</span>
                                @endif
                            </x-ui.td>
                            <x-ui.td>
                                <x-ui.badge variant="purple">{{ $client->sites_count }}</x-ui.badge>
                            </x-ui.td>
                            <x-ui.td>
                                @php
                                    $statusVariant = match($client->status) {
                                        'active' => 'green',
                                        'inactive' => 'yellow',
                                        'archived' => 'gray',
                                        default => 'gray',
                                    };
                                @endphp
                                <x-ui.badge :variant="$statusVariant">{{ ucfirst($client->status) }}</x-ui.badge>
                            </x-ui.td>
                            <x-ui.td>
                                <span class="text-sm text-gray-500">{{ $client->created_at->format('M j, Y') }}</span>
                            </x-ui.td>
                            <x-ui.td class="text-right">
                                <x-ui.dropdown align="right">
                                    <x-slot:trigger>
                                        <button class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/>
                                            </svg>
                                        </button>
                                    </x-slot:trigger>

                                    <a href="{{ route('clients.show', $client) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('View') }}</a>
                                    <a href="{{ route('clients.edit', $client) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">{{ __('Edit') }}</a>
                                    <div class="my-1 border-t border-gray-100"></div>
                                    @if($client->status !== 'active')
                                        <button wire:click="changeStatus({{ $client->id }}, 'active')" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">{{ __('Set Active') }}</button>
                                    @endif
                                    @if($client->status !== 'inactive')
                                        <button wire:click="changeStatus({{ $client->id }}, 'inactive')" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">{{ __('Set Inactive') }}</button>
                                    @endif
                                    @if($client->status !== 'archived')
                                        <button wire:click="changeStatus({{ $client->id }}, 'archived')" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">{{ __('Archive') }}</button>
                                    @endif
                                    <div class="my-1 border-t border-gray-100"></div>
                                    <button wire:click="confirmDelete({{ $client->id }})" class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">{{ __('Delete') }}</button>
                                </x-ui.dropdown>
                            </x-ui.td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            </div>{{-- end hidden md:block --}}
        </x-ui.card>

        <div class="mt-6">
            {{ $clients->links() }}
        </div>
    @else
        <x-ui.empty-state
            :title="__('No clients found')"
            :description="__('Add your first client to get started.')"
            icon="users"
        >
            <x-slot:action>
                <a href="{{ route('clients.create') }}">
                    <x-ui.button>
                        <x-icons.plus class="h-4 w-4" />
                        {{ __('Add Client') }}
                    </x-ui.button>
                </a>
            </x-slot:action>
        </x-ui.empty-state>
    @endif

    {{-- Delete Confirmation Modal --}}
    <x-ui.modal name="delete-client" maxWidth="sm">
        <div class="text-center">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
                <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h3 class="mb-2 text-lg font-medium text-gray-900">{{ __('Delete Client') }}</h3>
            <p class="mb-6 text-sm text-gray-500">{{ __('Are you sure you want to delete this client? This action cannot be undone.') }}</p>
            <div class="flex justify-center gap-3">
                <x-ui.button variant="secondary" wire:click="cancelDelete">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" wire:click="delete">{{ __('Delete') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
