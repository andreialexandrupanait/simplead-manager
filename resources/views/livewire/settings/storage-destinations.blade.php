{{-- Where backups are written. This card used to live on the Integrations
     tab, two screens away from the Application Backup select that is its only
     consumer — so you configured a destination in one place and chose it in
     another.

     Feedback used to arrive as `storage-success`/`storage-error` flashes
     rendered right here, while every neighbouring action on the tab used a
     toast. The component dispatches notify now and the local flash pair is
     gone; the page wrapper renders the central success/error keys. --}}
<div>
    <x-settings.section
        id="destinations"
        :title="__('Storage Destinations')"
        :subtitle="__('Targets a backup can be written to. One of them is the default.')"
    >
        <x-slot:actions>
            <x-ui.button size="sm" variant="secondary" type="button" x-on:click="$dispatch('open-storage-form')">
                <x-icons.plus class="h-4 w-4" />
                {{ __('Add') }}
            </x-ui.button>
        </x-slot:actions>

        @if($this->destinations->isEmpty())
            <x-ui.empty-state
                icon="cloud"
                :title="__('No storage destinations configured')"
                :description="__('Add one to enable backups. Without a destination, backups fall back to local disk.')"
            >
                <x-slot:action>
                    <x-ui.button size="sm" type="button" x-on:click="$dispatch('open-storage-form')">
                        <x-icons.plus class="h-4 w-4" />
                        {{ __('Add destination') }}
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="divide-y divide-gray-100">
                @foreach($this->destinations as $destination)
                    @php
                        $tTest = "testDestination({$destination->id})";
                        $tDefault = "setDefault({$destination->id})";
                        $tDelete = "deleteDestination({$destination->id})";
                        $tActive = "toggleActive({$destination->id})";
                    @endphp
                    <div wire:key="destination-{{ $destination->id }}" class="flex items-center justify-between gap-4 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg
                                {{ match($destination->type) {
                                    'local' => 'bg-gray-100 text-gray-600',
                                    'dropbox' => 'bg-blue-100 text-blue-600',
                                    's3' => 'bg-orange-100 text-orange-600',
                                    'b2' => 'bg-red-100 text-red-600',
                                    'hetzner_objectstorage' => 'bg-red-100 text-red-700',
                                    default => 'bg-gray-100 text-gray-600',
                                } }}">
                                @if($destination->type === 'local')
                                    <x-icons.hard-drive class="h-4 w-4" />
                                @elseif($destination->type === 'dropbox')
                                    <svg aria-hidden="true" class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M6 2l6 3.75L6 9.5 0 5.75zm12 0l6 3.75-6 3.75-6-3.75zM0 13.25L6 9.5l6 3.75L6 17zm12-3.75l6-3.75 6 3.75-6 3.75zm-5.97 4.49L6 14l-.03-.01L0 17.24v1.52l6.03-3.75L12 18.76v-1.52l-5.97-3.25zm11.94 0L12 17.24v1.52l5.97-3.25L24 18.76v-1.52l-6.03-3.25z"/></svg>
                                @else
                                    <x-icons.cloud class="h-4 w-4" />
                                @endif
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $destination->name }}
                                    @if($destination->is_default)
                                        <x-ui.badge variant="blue" class="ml-1">{{ __('Default') }}</x-ui.badge>
                                    @endif
                                    @unless($destination->is_active)
                                        {{-- Off is a state worth seeing at a glance: a retired
                                             destination still holds backups and still appears here. --}}
                                        <x-ui.badge variant="gray" class="ml-1">{{ __('Switched off') }}</x-ui.badge>
                                    @endunless
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ match($destination->type) {
                                        'local' => __('Local'),
                                        'dropbox' => 'Dropbox',
                                        's3' => 'S3 / Compatible',
                                        'b2' => 'Backblaze B2',
                                        'hetzner_objectstorage' => 'Hetzner Object Storage',
                                        default => ucfirst($destination->type),
                                    } }}
                                    @if($destination->last_tested_at)
                                        &middot; {{ __('Tested') }} {{ $destination->last_tested_at->diffForHumans() }}
                                        @if($destination->last_test_passed)
                                            <span class="text-green-600">{{ __('Passed') }}</span>
                                        @else
                                            <span class="text-red-600">{{ __('Failed') }}</span>
                                        @endif
                                    @endif
                                    @if($destination->used_bytes > 0)
                                        &middot; {{ $destination->used_formatted }} {{ __('used') }}
                                    @endif
                                </div>
                                @if($destination->type === 'dropbox')
                                    <div class="mt-1 flex flex-col gap-0.5">
                                        @if($destination->config['base_path'] ?? null)
                                            <div class="flex items-center gap-1.5 text-xs text-gray-500">
                                                <svg aria-hidden="true" class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                                <span class="truncate">{{ __('Backups') }}: {{ $destination->config['base_path'] }}</span>
                                            </div>
                                        @endif
                                        @if($destination->config['reports_path'] ?? null)
                                            <div class="flex items-center gap-1.5 text-xs text-gray-500">
                                                <x-icons.file-text class="h-3 w-3 shrink-0" />
                                                <span class="truncate">{{ __('Reports') }}: {{ $destination->config['reports_path'] }}</span>
                                            </div>
                                        @endif
                                        @if($destination->config['app_backups_path'] ?? null)
                                            <div class="flex items-center gap-1.5 text-xs text-gray-500">
                                                <x-icons.database class="h-3 w-3 shrink-0" />
                                                <span class="truncate">{{ __('App backups') }}: {{ $destination->config['app_backups_path'] }}</span>
                                            </div>
                                        @endif
                                    </div>
                                @elseif($destination->type === 'local' && ($destination->config['path'] ?? null))
                                    <div class="mt-1 flex items-center gap-1.5 text-xs text-gray-500">
                                        <svg aria-hidden="true" class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                        <span class="truncate">{{ $destination->config['path'] }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-0.5">
                            {{-- A live connection test against S3/B2/Dropbox: it
                                 can take seconds, and used to look inert. --}}
                            <x-ui.button
                                variant="ghost" size="xs"
                                wire:click="testDestination({{ $destination->id }})"
                                wire:target="{{ $tTest }}"
                                wire:loading.attr="disabled"
                                :title="__('Test Connection')"
                            >
                                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tTest }}" />
                                <x-icons.check-circle class="h-4 w-4" wire:loading.remove wire:target="{{ $tTest }}" />
                                <span class="sr-only">{{ __('Test Connection') }}</span>
                            </x-ui.button>

                            {{-- Switching a destination off is how you retire a provider you no
                                 longer use: deleting is impossible once it holds backups, and
                                 leaving it active means something may still choose it. --}}
                            <x-ui.button
                                variant="ghost" size="xs"
                                wire:click="toggleActive({{ $destination->id }})"
                                wire:target="{{ $tActive }}"
                                wire:loading.attr="disabled"
                                :title="$destination->is_active ? __('Switch off') : __('Switch on')"
                            >
                                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tActive }}" />
                                <svg aria-hidden="true" class="h-4 w-4 {{ $destination->is_active ? 'text-green-600' : 'text-gray-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" wire:loading.remove wire:target="{{ $tActive }}">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.636 5.636a9 9 0 1012.728 0M12 3v9" />
                                </svg>
                                <span class="sr-only">{{ $destination->is_active ? __('Switch off') : __('Switch on') }}</span>
                            </x-ui.button>

                            @if(! $destination->is_default)
                                <x-ui.button
                                    variant="ghost" size="xs"
                                    wire:click="setDefault({{ $destination->id }})"
                                    wire:target="{{ $tDefault }}"
                                    wire:loading.attr="disabled"
                                    :title="__('Set as Default')"
                                >
                                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tDefault }}" />
                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" wire:loading.remove wire:target="{{ $tDefault }}"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>
                                    <span class="sr-only">{{ __('Set as Default') }}</span>
                                </x-ui.button>
                            @endif

                            <x-ui.button
                                variant="ghost" size="xs"
                                type="button"
                                wire:click="$dispatch('open-storage-form', { destinationId: {{ $destination->id }} })"
                                :title="__('Edit')"
                            >
                                <x-icons.pencil class="h-4 w-4" />
                                <span class="sr-only">{{ __('Edit') }}</span>
                            </x-ui.button>

                            <x-ui.button
                                variant="ghost" size="xs"
                                wire:click="deleteDestination({{ $destination->id }})"
                                wire:target="{{ $tDelete }}"
                                wire:loading.attr="disabled"
                                wire:confirm="{{ __('Are you sure you want to delete this storage destination?') }}"
                                :title="__('Delete')"
                            >
                                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="{{ $tDelete }}" />
                                <x-icons.trash class="h-4 w-4 text-red-500" wire:loading.remove wire:target="{{ $tDelete }}" />
                                <span class="sr-only">{{ __('Delete') }}</span>
                            </x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-settings.section>

    <livewire:settings.components.storage-destination-form />
</div>
