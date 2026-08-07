<div>
    {{-- Flash Message --}}
    <x-ui.flash-alert type="success" key="message" />

    {{-- Header --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header :title="__('Dashboard')" :subtitle="__('Overview of all your sites and infrastructure')" />
        <a href="{{ route('sites.create') }}">
            <x-ui.button>
                <x-icons.plus class="h-4 w-4" />
                {{ __('Add Site') }}
            </x-ui.button>
        </a>
    </div>

    <x-dashboard.fleet-stats />


    {{-- Section 2: Sites List View --}}
    <div id="sites" class="mt-6">
        <div class="mb-3">
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Sites') }}</h2>
        </div>

        @if(count($selectedSites) > 0)
            {{-- Bulk Action Bar --}}
            <div class="mb-3 sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-accent-200 bg-accent-50 px-4 py-2.5">
                <div class="flex items-center gap-3">
                    {{-- Select All checkbox --}}
                    <input type="checkbox"
                        wire:click="toggleSelectAll"
                        @checked(count(array_intersect($selectedSites, $this->sites->pluck('id')->toArray())) === $this->sites->count())
                        class="h-4 w-4 cursor-pointer rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                    <span class="text-sm font-medium text-accent-700">
                        {{ count($selectedSites) }} {{ Str::plural('site', count($selectedSites)) }} {{ __('selected') }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    {{-- Set Status dropdown --}}
                    @if($this->siteStatuses->isNotEmpty())
                        <x-ui.dropdown align="left" width="48">
                            <x-slot:trigger>
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                    {{ __('Set Status') }}
                                    <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                            </x-slot:trigger>
                            @foreach($this->siteStatuses as $status)
                                <button wire:click="bulkSetStatus({{ $status->id }})" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                    <span aria-hidden="true" class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                                    {{ $status->name }}
                                </button>
                            @endforeach
                            <div class="my-1 border-t border-gray-100"></div>
                            <button wire:click="bulkClearStatus" class="block w-full px-4 py-2 text-left text-sm text-gray-500 hover:bg-gray-50">{{ __('Clear Status') }}</button>
                        </x-ui.dropdown>
                    @endif

                    {{-- Move to Client dropdown --}}
                    <x-ui.dropdown align="left" width="48">
                        <x-slot:trigger>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                {{ __('Move to Client') }}
                                <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                        </x-slot:trigger>
                        @foreach($this->clients as $client)
                            <button wire:click="bulkMoveToClient({{ $client->id }})" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                {{ $client->name }}
                            </button>
                        @endforeach
                    </x-ui.dropdown>

                    {{-- Sync --}}
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkSync" wire:loading.attr="disabled" wire:target="bulkSync">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        <span wire:loading.remove wire:target="bulkSync">{{ __('Sync') }}</span><span wire:loading wire:target="bulkSync">{{ __('Syncing…') }}</span>
                    </x-ui.button>

                    {{-- Backup --}}
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkBackup" wire:loading.attr="disabled" wire:target="bulkBackup">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                        <span wire:loading.remove wire:target="bulkBackup">{{ __('Backup') }}</span><span wire:loading wire:target="bulkBackup">{{ __('Queuing…') }}</span>
                    </x-ui.button>

                    {{-- Check Uptime --}}
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkCheckUptime" wire:loading.attr="disabled" wire:target="bulkCheckUptime">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span wire:loading.remove wire:target="bulkCheckUptime">{{ __('Check Uptime') }}</span><span wire:loading wire:target="bulkCheckUptime">{{ __('Checking…') }}</span>
                    </x-ui.button>

                    {{-- Delete (danger) --}}
                    <x-ui.button variant="danger" size="sm" wire:click="confirmBulkDelete">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        {{ __('Delete') }}
                    </x-ui.button>

                    {{-- Deselect all --}}
                    <button type="button"
                            wire:click="clearSelection"
                            aria-label="{{ __('Clear selection') }}"
                            class="rounded-lg p-2.5 text-accent-400 transition hover:bg-accent-100 hover:text-accent-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-1">
                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
        @else
        {{-- Search + Filter Pills --}}
        <div class="mb-3 flex flex-wrap items-center gap-2">
            @if($this->reordering)
                {{-- Reorder mode: only Save/Cancel --}}
                <span class="text-sm text-gray-500">{{ __('Drag sites to reorder') }}</span>
                <div class="flex items-center gap-2 sm:ml-auto">
                    <button
                        type="button"
                        x-data
                        @click="
                            let c = document.getElementById('sortable-site-list');
                            let ids = [...c.querySelectorAll('[data-site-id]')].map(el => Number(el.dataset.siteId));
                            if (ids.length) $wire.saveReorder(ids);
                        "
                        class="inline-flex items-center gap-1.5 rounded-lg border border-green-300 bg-green-50 px-3 py-1.5 text-sm font-medium text-green-700 transition hover:bg-green-100"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        {{ __('Save Order') }}
                    </button>
                    <button
                        type="button"
                        wire:click="cancelReordering"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                    >
                        {{ __('Cancel') }}
                    </button>
                </div>
            @else
                {{-- Normal mode: filters + sort + reorder + search --}}
                {{-- Client Pill --}}
                @php
                    $clientActive = $this->clientFilter !== null;
                    $clientLabel = __('Client');
                    if ($clientActive) {
                        $selectedClient = $this->clients->firstWhere('id', $this->clientFilter);
                        $clientLabel = $selectedClient ? $selectedClient->name : __('Client');
                    }
                @endphp
                {{-- Four filter pills, previously ~110 lines with the trigger classes written out
                     four times and the same chevron SVG copied four times. --}}
                <x-ui.filter-dropdown :label="$clientLabel" icon="users" :active="$clientActive" width="56">
                    <x-ui.filter-option wire:click="setClientFilter(null)" :selected="! $clientActive">
                        {{ __('All Clients') }}
                    </x-ui.filter-option>
                    @foreach($this->clients as $client)
                        <x-ui.filter-option wire:click="setClientFilter({{ $client->id }})" :selected="$this->clientFilter === $client->id">
                            <span class="truncate">{{ $client->name }}</span>
                            <span class="text-gray-400">({{ $client->sites_count }})</span>
                        </x-ui.filter-option>
                    @endforeach
                </x-ui.filter-dropdown>

                {{-- Health --}}
                @php
                    $healthActive = $this->filter !== 'all';
                    $healthLabels = ['all' => __('Health'), 'healthy' => __('Healthy'), 'warning' => __('Warning'), 'critical' => __('Critical')];
                    $healthLabel = $healthLabels[$this->filter] ?? __('Health');
                @endphp
                <x-ui.filter-dropdown :label="$healthLabel" icon="heart" :active="$healthActive" width="48">
                    @foreach(['all' => __('All Health'), 'healthy' => __('Healthy'), 'warning' => __('Warning'), 'critical' => __('Critical')] as $value => $label)
                        <x-ui.filter-option wire:click="setFilter('{{ $value }}')" :selected="$this->filter === $value">
                            {{ $label }}
                        </x-ui.filter-option>
                    @endforeach
                </x-ui.filter-dropdown>

                {{-- Status --}}
                @if($this->siteStatuses->isNotEmpty())
                    @php
                        $statusActive = $this->statusFilter !== null;
                        $statusLabel = __('Status');
                        if ($statusActive) {
                            $selectedStatus = $this->siteStatuses->firstWhere('id', $this->statusFilter);
                            $statusLabel = $selectedStatus ? $selectedStatus->name : __('Status');
                        }
                    @endphp
                    <x-ui.filter-dropdown :label="$statusLabel" icon="clipboard" :active="$statusActive" width="56">
                        <x-ui.filter-option wire:click="setStatusFilter(null)" :selected="! $statusActive">
                            {{ __('All Statuses') }}
                        </x-ui.filter-option>
                        @foreach($this->siteStatuses as $status)
                            <x-ui.filter-option wire:click="setStatusFilter({{ $status->id }})" :selected="$this->statusFilter === $status->id">
                                <span aria-hidden="true" class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $status->color }}"></span>
                                <span class="truncate">{{ $status->name }}</span>
                                <span class="text-gray-400">({{ $status->sites_count }})</span>
                            </x-ui.filter-option>
                        @endforeach
                    </x-ui.filter-dropdown>
                @endif

                {{-- Sort --}}
                @php
                    $sortActive = $this->sort !== 'manual';
                    $sortLabels = ['manual' => __('Manual'), 'health-asc' => __('Health').' ↑', 'health-desc' => __('Health').' ↓', 'name-asc' => __('Name A-Z'), 'name-desc' => __('Name Z-A')];
                    $sortLabel = $sortLabels[$this->sort] ?? __('Sort');
                @endphp
                <x-ui.filter-dropdown :label="$sortLabel" icon="arrow-up-down" :active="$sortActive" width="48">
                    @foreach(['manual' => __('Manual'), 'name-asc' => __('Name A-Z'), 'name-desc' => __('Name Z-A'), 'health-asc' => __('Health').' ↑', 'health-desc' => __('Health').' ↓'] as $value => $label)
                        <x-ui.filter-option wire:click="setSort('{{ $value }}')" :selected="$this->sort === $value">
                            {{ $label }}
                        </x-ui.filter-option>
                    @endforeach
                </x-ui.filter-dropdown>

                {{-- Reorder Button --}}
                <button
                    type="button"
                    wire:click="startReordering"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/></svg>
                    {{ __('Reorder') }}
                </button>

                <x-ui.search-input
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search sites...') }}"
                    class="w-full sm:ml-auto sm:w-64"
                />
            @endif
        </div>
        @endif

        @if($this->sites->isEmpty())
            <x-ui.card>
                <x-ui.empty-state :title="__('No sites yet')" :description="__('Add your first site to get started.')" icon="globe" />
            </x-ui.card>
        @else
            <div class="overflow-hidden rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700" x-data="sortableList" x-effect="enabled = @js($this->reordering)">
                <div id="sortable-site-list" x-ref="sortableContainer">
                @foreach($this->sites as $site)
                    <x-dashboard.site-row
                        :site="$site"
                        :selected-sites="$selectedSites"
                        :reordering="$this->reordering"
                        :site-statuses="$this->siteStatuses"
                    />
                @endforeach
                </div>
            </div>

            @if(!$this->reordering)
            <div class="mt-4">
                {{ $this->sites->links() }}
            </div>
            @endif
        @endif
    </div>


    {{-- Rename Site Modal --}}
    <x-ui.modal name="rename-site" maxWidth="sm">
        <form wire:submit="renameSite">
            <h2 id="modal-rename-site-title" class="text-lg font-semibold text-gray-900">{{ __('Rename Site') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Enter a new name for this site.') }}</p>

            <div class="mt-4">
                <label for="renamingSiteName" class="block text-sm font-medium text-gray-700">{{ __('Site Name') }}</label>
                <x-ui.input wire:model="renamingSiteName" id="renamingSiteName" class="mt-1" />
                @error('renamingSiteName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-rename-site')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit">
                    {{ __('Save') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete Site Modal --}}
    <x-ui.modal name="delete-site" maxWidth="sm">
        <div>
            <h2 id="modal-delete-site-title" class="text-lg font-semibold text-gray-900">{{ __('Delete Site') }}</h2>
            <p class="mt-2 text-sm text-gray-600">
                {{ __('Are you sure you want to delete') }} <span class="font-medium text-gray-900">{{ $deletingSiteName }}</span>? {{ __('This action cannot be undone.') }}
            </p>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-delete-site')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="deleteSite">
                    {{ __('Delete Site') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Bulk Delete Modal --}}
    <x-ui.modal name="bulk-delete" maxWidth="sm">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Delete') }} {{ count($selectedSites) }} {{ Str::plural('site', count($selectedSites)) }}</h2>
            <p class="mt-2 text-sm text-gray-600">
                {{ __('Are you sure you want to delete these sites? This action cannot be undone.') }}
            </p>
            @if(count($selectedSites) > 0)
                <ul class="mt-3 max-h-40 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                    @foreach(App\Models\Site::whereIn('id', $selectedSites)->pluck('name', 'id') as $id => $name)
                        <li class="py-0.5">{{ $name }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-bulk-delete')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="bulkDelete">{{ __('Delete') }} {{ count($selectedSites) }} {{ Str::plural('site', count($selectedSites)) }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
