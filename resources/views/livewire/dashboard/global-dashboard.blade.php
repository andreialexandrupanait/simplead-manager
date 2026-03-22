<div>
    {{-- Flash Message --}}
    <x-ui.flash-alert type="success" key="message" />

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <x-ui.page-header title="Dashboard" subtitle="Overview of all your sites and infrastructure" />
        <a href="{{ route('sites.create') }}">
            <x-ui.button>
                <x-icons.plus class="h-4 w-4" />
                Add Site
            </x-ui.button>
        </a>
    </div>

    {{-- Section 1: Compact Stats Strip --}}
    @php
        $stats = $this->stats;
        $uptimeColor = 'text-gray-900';
        if ($stats['avg_uptime'] !== null) {
            $uptimeColor = $stats['avg_uptime'] >= 99 ? 'text-green-600' : ($stats['avg_uptime'] >= 95 ? 'text-yellow-600' : 'text-red-600');
        }
        $alertsColor = $stats['total_alerts'] === 0 ? 'text-green-600' : 'text-red-600';
    @endphp
    <x-ui.card :padding="false">
        <div class="grid grid-cols-1 divide-y sm:grid-cols-3 sm:divide-x sm:divide-y-0 divide-gray-100">
            {{-- Sites --}}
            <div class="flex items-center gap-3 px-5 py-3">
                <div class="h-2.5 w-2.5 shrink-0 rounded-full {{ $stats['sites_down'] > 0 ? 'bg-red-500 animate-pulse' : 'bg-green-500' }}"></div>
                <div>
                    <div class="text-lg font-semibold text-gray-900">{{ $stats['total_sites'] }} {{ Str::plural('Site', $stats['total_sites']) }}</div>
                    <div class="text-xs {{ $stats['sites_down'] > 0 ? 'text-red-600 font-medium' : 'text-gray-400' }}">
                        {{ $stats['sites_down'] === 0 ? 'all operational' : $stats['sites_down'] . ' down' }}
                    </div>
                </div>
            </div>

            {{-- Uptime --}}
            <div class="px-5 py-3">
                <div class="text-lg font-semibold {{ $uptimeColor }}">{{ $stats['avg_uptime'] !== null ? $stats['avg_uptime'] . '%' : '—' }}</div>
                <div class="text-xs text-gray-400">uptime &middot; 30d</div>
            </div>

            {{-- Alerts --}}
            <div class="px-5 py-3">
                <div class="text-lg font-semibold {{ $alertsColor }}">{{ $stats['total_alerts'] }}</div>
                <div class="text-xs text-gray-400">
                    @if($stats['total_alerts'] === 0)
                        no issues
                    @else
                        {{ Str::plural('issue', $stats['total_alerts']) }} need attention
                    @endif
                </div>
            </div>
        </div>
    </x-ui.card>

    {{-- Section 2: Sites List View --}}
    <div class="mt-6">
        <div class="mb-3">
            <h2 class="text-lg font-semibold text-gray-900">Sites</h2>
        </div>

        @if(count($selectedSites) > 0)
            {{-- Bulk Action Bar --}}
            <div class="mb-3 sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-purple-200 bg-purple-50 px-4 py-2.5">
                <div class="flex items-center gap-3">
                    {{-- Select All checkbox --}}
                    <input type="checkbox"
                        wire:click="toggleSelectAll"
                        @checked(count(array_intersect($selectedSites, $this->sites->pluck('id')->toArray())) === $this->sites->count())
                        class="h-4 w-4 cursor-pointer rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
                    <span class="text-sm font-medium text-purple-700">
                        {{ count($selectedSites) }} {{ Str::plural('site', count($selectedSites)) }} selected
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    {{-- Set Status dropdown --}}
                    @if($this->siteStatuses->isNotEmpty())
                        <x-ui.dropdown align="left" width="48">
                            <x-slot:trigger>
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                    Set Status
                                    <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                            </x-slot:trigger>
                            @foreach($this->siteStatuses as $status)
                                <button wire:click="bulkSetStatus({{ $status->id }})" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                    <span class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                                    {{ $status->name }}
                                </button>
                            @endforeach
                            <div class="my-1 border-t border-gray-100"></div>
                            <button wire:click="bulkClearStatus" class="block w-full px-4 py-2 text-left text-sm text-gray-500 hover:bg-gray-50">Clear Status</button>
                        </x-ui.dropdown>
                    @endif

                    {{-- Move to Client dropdown --}}
                    <x-ui.dropdown align="left" width="48">
                        <x-slot:trigger>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                Move to Client
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
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkSync">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Sync
                    </x-ui.button>

                    {{-- Backup --}}
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkBackup">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                        Backup
                    </x-ui.button>

                    {{-- Check Uptime --}}
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkCheckUptime">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Check Uptime
                    </x-ui.button>

                    {{-- Delete (danger) --}}
                    <x-ui.button variant="danger" size="sm" wire:click="confirmBulkDelete">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete
                    </x-ui.button>

                    {{-- Deselect all --}}
                    <button wire:click="clearSelection" class="rounded-lg p-1.5 text-purple-400 transition hover:bg-purple-100 hover:text-purple-600" title="Clear selection">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
        @else
        {{-- Search + Filter Pills --}}
        <div class="mb-3 flex flex-wrap items-center gap-2">
            {{-- Client Pill --}}
                @php
                    $clientActive = $this->clientFilter !== null;
                    $clientLabel = 'Client';
                    if ($clientActive) {
                        $selectedClient = $this->clients->firstWhere('id', $this->clientFilter);
                        $clientLabel = $selectedClient ? $selectedClient->name : 'Client';
                    }
                @endphp
                <x-ui.dropdown align="left" width="56">
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $clientActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <span class="max-w-[8rem] truncate">{{ $clientLabel }}</span>
                            <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </x-slot:trigger>

                    <button wire:click="setClientFilter(null)" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ !$clientActive ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                        All Clients
                        @if(!$clientActive)
                            <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </button>
                    @foreach($this->clients as $client)
                        <button wire:click="setClientFilter({{ $client->id }})" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->clientFilter === $client->id ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                            {{ $client->name }} ({{ $client->sites_count }})
                            @if($this->clientFilter === $client->id)
                                <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </button>
                    @endforeach
                </x-ui.dropdown>

                {{-- Health Pill --}}
                @php
                    $healthActive = $this->filter !== 'all';
                    $healthLabels = ['all' => 'Health', 'healthy' => 'Healthy', 'warning' => 'Warning', 'critical' => 'Critical'];
                    $healthLabel = $healthLabels[$this->filter] ?? 'Health';
                @endphp
                <x-ui.dropdown align="left" width="48">
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $healthActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                            {{ $healthLabel }}
                            <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </x-slot:trigger>

                    @foreach(['all' => 'All Health', 'healthy' => 'Healthy', 'warning' => 'Warning', 'critical' => 'Critical'] as $value => $label)
                        <button wire:click="setFilter('{{ $value }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->filter === $value ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                            {{ $label }}
                            @if($this->filter === $value)
                                <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </button>
                    @endforeach
                </x-ui.dropdown>

                {{-- Status Pill --}}
                @if($this->siteStatuses->isNotEmpty())
                    @php
                        $statusActive = $this->statusFilter !== null;
                        $statusLabel = 'Status';
                        if ($statusActive) {
                            $selectedStatus = $this->siteStatuses->firstWhere('id', $this->statusFilter);
                            $statusLabel = $selectedStatus ? $selectedStatus->name : 'Status';
                        }
                    @endphp
                    <x-ui.dropdown align="left" width="56">
                        <x-slot:trigger>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $statusActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                <span class="max-w-[8rem] truncate">{{ $statusLabel }}</span>
                                <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                        </x-slot:trigger>

                        <button wire:click="setStatusFilter(null)" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ !$statusActive ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                            All Statuses
                            @if(!$statusActive)
                                <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </button>
                        @foreach($this->siteStatuses as $status)
                            <button wire:click="setStatusFilter({{ $status->id }})" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->statusFilter === $status->id ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                                <span class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                                    {{ $status->name }} ({{ $status->sites_count }})
                                </span>
                                @if($this->statusFilter === $status->id)
                                    <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                @endif
                            </button>
                        @endforeach
                    </x-ui.dropdown>
                @endif

                {{-- Sort Pill --}}
                @php
                    $sortActive = $this->sort !== 'manual';
                    $sortLabels = ['manual' => 'Manual', 'health-asc' => 'Health ↑', 'health-desc' => 'Health ↓', 'name-asc' => 'Name A-Z', 'name-desc' => 'Name Z-A'];
                    $sortLabel = $sortLabels[$this->sort] ?? 'Sort';
                    $isManualSort = $this->sort === 'manual';
                @endphp
                <x-ui.dropdown align="left" width="48">
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $sortActive ? 'border-purple-300 bg-purple-50 text-purple-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                            {{ $sortLabel }}
                            <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </x-slot:trigger>

                    @foreach(['manual' => 'Manual', 'name-asc' => 'Name A-Z', 'name-desc' => 'Name Z-A', 'health-asc' => 'Health ↑', 'health-desc' => 'Health ↓'] as $value => $label)
                        <button wire:click="setSort('{{ $value }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->sort === $value ? 'bg-purple-50 text-purple-700' : 'text-gray-700 hover:bg-gray-50' }}">
                            {{ $label }}
                            @if($this->sort === $value)
                                <svg class="h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </button>
                    @endforeach
                </x-ui.dropdown>

                {{-- Reorder Button --}}
                @if($this->reordering)
                    <button
                        type="button"
                        onclick="window.dispatchEvent(new CustomEvent('save-sort-order'))"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-green-300 bg-green-50 px-3 py-1.5 text-sm font-medium text-green-700 transition hover:bg-green-100"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Save Order
                    </button>
                    <button
                        type="button"
                        wire:click="cancelReordering"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="startReordering"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/></svg>
                        Reorder
                    </button>
                @endif

            <x-ui.search-input
                wire:model.live.debounce.300ms="search"
                placeholder="Search sites..."
                class="ml-auto w-64"
            />
        </div>
        @endif

        @if($this->sites->isEmpty())
            <x-ui.card>
                <x-ui.empty-state title="No sites yet" description="Add your first site to get started." icon="globe" />
            </x-ui.card>
        @else
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5" x-data="sortableList" x-effect="enabled = @js($this->reordering)" x-on:save-sort-order.window="saveOrder()">
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

            <div class="mt-4">
                {{ $this->sites->links() }}
            </div>
        @endif
    </div>


    {{-- Rename Site Modal --}}
    <x-ui.modal name="rename-site" maxWidth="sm">
        <form wire:submit="renameSite">
            <h2 class="text-lg font-semibold text-gray-900">Rename Site</h2>
            <p class="mt-1 text-sm text-gray-500">Enter a new name for this site.</p>

            <div class="mt-4">
                <label for="renamingSiteName" class="block text-sm font-medium text-gray-700">Site Name</label>
                <x-ui.input wire:model="renamingSiteName" id="renamingSiteName" class="mt-1" />
                @error('renamingSiteName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-rename-site')">
                    Cancel
                </x-ui.button>
                <x-ui.button type="submit">
                    Save
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete Site Modal --}}
    <x-ui.modal name="delete-site" maxWidth="sm">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Delete Site</h2>
            <p class="mt-2 text-sm text-gray-600">
                Are you sure you want to delete <span class="font-medium text-gray-900">{{ $deletingSiteName }}</span>? This action cannot be undone.
            </p>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-delete-site')">
                    Cancel
                </x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="deleteSite">
                    Delete Site
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    {{-- Bulk Delete Modal --}}
    <x-ui.modal name="bulk-delete" maxWidth="sm">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Delete {{ count($selectedSites) }} {{ Str::plural('site', count($selectedSites)) }}</h2>
            <p class="mt-2 text-sm text-gray-600">
                Are you sure you want to delete these sites? This action cannot be undone.
            </p>
            @if(count($selectedSites) > 0)
                <ul class="mt-3 max-h-40 overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                    @foreach(App\Models\Site::whereIn('id', $selectedSites)->pluck('name', 'id') as $id => $name)
                        <li class="py-0.5">{{ $name }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-bulk-delete')">Cancel</x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="bulkDelete">Delete {{ count($selectedSites) }} {{ Str::plural('site', count($selectedSites)) }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
