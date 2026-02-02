<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Updates</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $site->name }} — Manage WordPress updates</p>
        </div>
        <x-ui.button variant="secondary" wire:click="syncNow" wire:loading.attr="disabled">
            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span wire:loading.remove wire:target="syncNow">Sync Now</span>
            <span wire:loading wire:target="syncNow">Syncing...</span>
        </x-ui.button>
    </div>

    {{-- Flash messages --}}
    @if(session('update-success'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('update-success') }}</div>
    @endif
    @if(session('update-error'))
        <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ session('update-error') }}</div>
    @endif
    @if(session('sync-dispatched'))
        <div class="mb-4 rounded-lg bg-blue-50 p-4 text-sm text-blue-700">{{ session('sync-dispatched') }}</div>
    @endif

    {{-- Available Updates --}}
    <x-ui.card :padding="false">
        <div class="flex items-center justify-between border-b p-4">
            <h2 class="text-lg font-semibold text-gray-900">
                Available Updates
                @if($this->availableUpdates->count() > 0)
                    <span class="ml-1 rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-700">
                        {{ $this->availableUpdates->count() }}
                    </span>
                @endif
            </h2>
            @if($this->availableUpdates->count() > 1)
                <x-ui.button wire:click="updateAll" wire:loading.attr="disabled" wire:target="updateAll">
                    <span wire:loading.remove wire:target="updateAll">Update All</span>
                    <span wire:loading wire:target="updateAll">Updating...</span>
                </x-ui.button>
            @endif
        </div>

        @if($this->availableUpdates->count() > 0)
            <div class="divide-y">
                @foreach($this->availableUpdates as $update)
                    <div class="flex items-center justify-between px-4 py-3 hover:bg-gray-50">
                        <div class="flex items-center gap-3">
                            {{-- Type icon --}}
                            @if($update['type'] === 'core')
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100">
                                    <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                                    </svg>
                                </div>
                            @elseif($update['type'] === 'plugin')
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-purple-100">
                                    <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                    </svg>
                                </div>
                            @else
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-green-100">
                                    <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif

                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-gray-900">{{ $update['name'] }}</span>
                                    <x-ui.badge :variant="$update['type'] === 'core' ? 'purple' : ($update['type'] === 'plugin' ? 'purple' : 'green')">
                                        {{ ucfirst($update['type']) }}
                                    </x-ui.badge>
                                </div>
                                <span class="text-xs text-gray-500">
                                    {{ $update['from_version'] ?? '?' }} &rarr; {{ $update['to_version'] ?? '?' }}
                                </span>
                            </div>
                        </div>

                        <div>
                            @if($update['type'] === 'core')
                                <x-ui.button size="sm" wire:click="updateCore" wire:loading.attr="disabled" wire:target="updateCore">
                                    <span wire:loading.remove wire:target="updateCore">Update</span>
                                    <span wire:loading wire:target="updateCore">Updating...</span>
                                </x-ui.button>
                            @elseif($update['type'] === 'plugin')
                                <x-ui.button size="sm" wire:click="updatePlugin({{ $update['model_id'] }})" wire:loading.attr="disabled">
                                    Update
                                </x-ui.button>
                            @else
                                <x-ui.button size="sm" wire:click="updateTheme({{ $update['model_id'] }})" wire:loading.attr="disabled">
                                    Update
                                </x-ui.button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="p-8 text-center">
                <div class="mb-3 inline-flex rounded-full bg-green-100 p-3">
                    <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <p class="text-sm font-medium text-gray-900">Everything is up to date</p>
                <p class="mt-1 text-xs text-gray-500">All plugins, themes, and WordPress core are running the latest versions.</p>
            </div>
        @endif
    </x-ui.card>

    {{-- Update History --}}
    <x-ui.card :padding="false" class="mt-6">
        <div class="border-b p-4">
            <h2 class="text-lg font-semibold text-gray-900">Update History</h2>
        </div>

        @if($updateHistory->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Version</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach($updateHistory as $log)
                            <tr class="hover:bg-gray-50">
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    {{ $log->performed_at->format('M d, Y H:i') }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <x-ui.badge :variant="match($log->type) { 'core' => 'purple', 'plugin' => 'purple', 'theme' => 'green', default => 'gray' }">
                                        {{ ucfirst($log->type) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-gray-900">{{ $log->name }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    {{ $log->from_version ?? '?' }} &rarr; {{ $log->to_version ?? '?' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if($log->success)
                                        <x-ui.badge variant="green">Success</x-ui.badge>
                                    @else
                                        <span class="group relative">
                                            <x-ui.badge variant="red">Failed</x-ui.badge>
                                            @if($log->error_message)
                                                <span class="absolute bottom-full left-0 mb-1 hidden w-48 rounded bg-gray-900 p-2 text-xs text-white group-hover:block">
                                                    {{ $log->error_message }}
                                                </span>
                                            @endif
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-500">
                                    {{ $log->user?->name ?? 'System' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t p-4">
                {{ $updateHistory->links() }}
            </div>
        @else
            <div class="p-8 text-center text-sm text-gray-500">
                No update history yet.
            </div>
        @endif
    </x-ui.card>
</div>
