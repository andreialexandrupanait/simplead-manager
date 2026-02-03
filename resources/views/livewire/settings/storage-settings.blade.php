<div class="mt-6 max-w-2xl">
    @if(session('storage-success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('storage-success') }}</div>
    @endif

    @if(session('storage-error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('storage-error') }}</div>
    @endif

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
</div>
