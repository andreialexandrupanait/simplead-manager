<div>
    {{-- Flash Message --}}
    @if(session('message'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Section 1: Stats Bar --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Total Sites</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->stats['total_sites'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Sites Up</div>
            <div class="mt-1 text-2xl font-bold text-green-600">{{ $this->stats['sites_up'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Sites Down</div>
            <div class="mt-1 text-2xl font-bold {{ $this->stats['sites_down'] > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $this->stats['sites_down'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Clients</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->stats['total_clients'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Avg Uptime</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->stats['avg_uptime'] ? $this->stats['avg_uptime'] . '%' : '—' }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Avg Response</div>
            <div class="mt-1 text-2xl font-bold text-gray-900">{{ $this->stats['avg_response_time'] ? $this->stats['avg_response_time'] . 'ms' : '—' }}</div>
        </x-ui.card>
    </div>

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

                    {{-- Sync --}}
                    <button wire:click="bulkSync" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Sync
                    </button>

                    {{-- Backup --}}
                    <button wire:click="bulkBackup" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                        Backup
                    </button>

                    {{-- Check Uptime --}}
                    <button wire:click="bulkCheckUptime" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Check Uptime
                    </button>

                    {{-- Delete (danger) --}}
                    <button wire:click="confirmBulkDelete" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-600 transition hover:bg-red-50">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete
                    </button>

                    {{-- Deselect all --}}
                    <button wire:click="clearSelection" class="rounded-lg p-1.5 text-purple-400 transition hover:bg-purple-100 hover:text-purple-600" title="Clear selection">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
        @else
        {{-- Search + Filter Pills --}}
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="Search sites..."
                class="w-64 rounded-lg border-gray-300 py-1.5 text-sm focus:border-purple-500 focus:ring-purple-500">
            <div class="flex flex-wrap items-center gap-2">
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
            </div>
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
                    @php
                        // Update badge color
                        $updates = $site->pending_updates_count ?? 0;
                        $updateBadgeColor = $updates === 0 ? 'bg-green-500' : ($updates <= 5 ? 'bg-orange-500' : 'bg-red-500');

                        // Uptime status
                        $uptimeColor = 'text-gray-300';
                        $uptimeTooltip = 'No monitor';
                        if ($site->uptimeMonitor) {
                            if ($site->is_up === true) {
                                $uptimeColor = 'text-green-500';
                                $uptimeTooltip = 'Up — ' . ($site->uptimeMonitor->uptime_30d ? round($site->uptimeMonitor->uptime_30d, 2) . '%' : 'monitoring');
                            } elseif ($site->is_up === false) {
                                $uptimeColor = 'text-red-500';
                                $uptimeTooltip = 'Down' . ($site->uptimeMonitor->last_failure_reason ? ' — ' . $site->uptimeMonitor->last_failure_reason : '');
                            } else {
                                $uptimeColor = 'text-yellow-500';
                                $uptimeTooltip = 'Degraded';
                            }
                        }

                        // SSL status
                        $sslColor = 'text-gray-300';
                        $sslTooltip = 'No certificate';
                        if ($site->sslCertificate) {
                            $cert = $site->sslCertificate;
                            if ($cert->status === 'valid') {
                                $sslColor = 'text-green-500';
                                $sslTooltip = 'SSL valid — ' . ($cert->days_remaining ?? '?') . ' days remaining';
                            } elseif ($cert->status === 'expiring_soon') {
                                $sslColor = 'text-yellow-500';
                                $sslTooltip = 'SSL expiring soon — ' . ($cert->days_remaining ?? '?') . ' days remaining';
                            } else {
                                $sslColor = 'text-red-500';
                                $sslTooltip = 'SSL ' . ($cert->status ?? 'error');
                            }
                        }

                        // Response time
                        $responseColor = 'text-gray-300';
                        $responseTooltip = 'No data';
                        if ($site->uptimeMonitor && $site->uptimeMonitor->avg_response_time) {
                            $rt = $site->uptimeMonitor->avg_response_time;
                            if ($rt < 500) {
                                $responseColor = 'text-green-500';
                            } elseif ($rt <= 2000) {
                                $responseColor = 'text-yellow-500';
                            } else {
                                $responseColor = 'text-red-500';
                            }
                            $responseTooltip = 'Response: ' . $rt . 'ms';
                        }

                        // Performance
                        $perfColor = 'text-gray-300';
                        $perfTooltip = 'No data';
                        if ($site->performanceMonitor && $site->performanceMonitor->latest_mobile_score !== null) {
                            $score = $site->performanceMonitor->latest_mobile_score;
                            if ($score >= 90) {
                                $perfColor = 'text-green-500';
                            } elseif ($score >= 50) {
                                $perfColor = 'text-yellow-500';
                            } else {
                                $perfColor = 'text-red-500';
                            }
                            $perfTooltip = 'Performance: ' . $score . '/100';
                        }

                        // Links
                        $linksColor = 'text-gray-300';
                        $linksTooltip = 'No scan';
                        if ($site->linkMonitor) {
                            $broken = $site->linkMonitor->broken_links ?? 0;
                            if ($broken === 0) {
                                $linksColor = 'text-green-500';
                                $linksTooltip = 'No broken links';
                            } elseif ($broken <= 5) {
                                $linksColor = 'text-yellow-500';
                                $linksTooltip = $broken . ' broken link' . ($broken > 1 ? 's' : '');
                            } else {
                                $linksColor = 'text-red-500';
                                $linksTooltip = $broken . ' broken links';
                            }
                        }

                        // Domain expiry
                        $domainColor = 'text-gray-300';
                        $domainTooltip = 'No monitor';
                        if ($site->domainMonitor && $site->domainMonitor->expires_at) {
                            $daysLeft = (int) now()->diffInDays($site->domainMonitor->expires_at, false);
                            if ($daysLeft < 0) {
                                $domainColor = 'text-red-500';
                                $domainTooltip = 'Domain expired';
                            } elseif ($daysLeft <= 30) {
                                $domainColor = 'text-yellow-500';
                                $domainTooltip = 'Domain expires in ' . $daysLeft . ' days';
                            } else {
                                $domainColor = 'text-green-500';
                                $domainTooltip = 'Domain expires in ' . $daysLeft . ' days';
                            }
                        }

                        // Plugins (update count)
                        $pluginsColor = $updates === 0 ? 'text-green-500' : ($updates <= 5 ? 'text-yellow-500' : 'text-red-500');
                        $pluginsTooltip = $updates === 0 ? 'All plugins up to date' : $updates . ' plugin update' . ($updates > 1 ? 's' : '') . ' available';

                        // Users
                        $usersCount = $site->site_users_count ?? 0;
                        $usersColor = $usersCount > 0 ? 'text-green-500' : 'text-gray-300';
                        $usersTooltip = $usersCount > 0 ? $usersCount . ' user' . ($usersCount !== 1 ? 's' : '') : 'No users synced';

                        // WordPress connected
                        $wpConnColor = $site->is_connected ? 'text-green-500' : 'text-gray-300';
                        $wpConnTooltip = $site->is_connected ? 'WordPress connected' : 'Not connected';

                        // Backup
                        $backupColor = 'text-gray-300';
                        $backupTooltip = 'Not configured';
                        if ($site->backupConfig) {
                            $bc = $site->backupConfig;
                            if ($bc->last_backup_status === 'failed') {
                                $backupColor = 'text-red-500';
                                $backupTooltip = 'Backup failed';
                            } elseif ($bc->last_backup_at && $bc->last_backup_at->diffInDays(now()) > 2) {
                                $backupColor = 'text-yellow-500';
                                $backupTooltip = 'Backup overdue — last ' . $bc->last_backup_at->diffForHumans();
                            } elseif ($bc->last_backup_at) {
                                $backupColor = 'text-green-500';
                                $backupTooltip = 'Backup OK — last ' . $bc->last_backup_at->diffForHumans();
                            }
                        }

                        // WP Version
                        $wpVerColor = 'text-gray-300';
                        $wpVerTooltip = 'Unknown';
                        if ($site->wp_version) {
                            if ($site->core_update_version) {
                                $wpVerColor = 'text-yellow-500';
                                $wpVerTooltip = 'WP ' . $site->wp_version . ' — update to ' . $site->core_update_version . ' available';
                            } else {
                                $wpVerColor = 'text-green-500';
                                $wpVerTooltip = 'WP ' . $site->wp_version . ' — up to date';
                            }
                        }

                        // Health bar
                        $healthScore = $site->health_score ?? 0;
                        $healthWidth = max(0, min(100, $healthScore));
                        $healthBarColor = $healthScore >= 90 ? 'bg-green-500' : ($healthScore >= 70 ? 'bg-yellow-500' : 'bg-red-500');
                    @endphp

                    <div class="group flex items-center gap-3 border-b border-gray-100 px-4 py-2.5 transition hover:bg-gray-50 {{ $site->is_up === false ? 'bg-red-50/30' : '' }}" data-site-id="{{ $site->id }}" wire:key="site-{{ $site->id }}">
                        {{-- Drag Handle --}}
                        @if($this->reordering)
                            <div class="drag-handle flex-shrink-0 cursor-grab text-gray-300 hover:text-gray-500 active:cursor-grabbing">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
                                </svg>
                            </div>
                        @endif

                        {{-- Checkbox --}}
                        <div class="flex h-6 w-6 flex-shrink-0 items-center justify-center">
                            <input
                                type="checkbox"
                                wire:click="toggleSiteSelection({{ $site->id }})"
                                @checked(in_array($site->id, $selectedSites))
                                class="h-4 w-4 cursor-pointer rounded border-gray-300 text-purple-600 focus:ring-purple-500 {{ in_array($site->id, $selectedSites) ? '' : 'opacity-0 group-hover:opacity-100' }} transition"
                            />
                        </div>

                        {{-- Site Identity --}}
                        <div class="min-w-0 flex-1 flex items-center gap-2">
                            <a href="{{ route('sites.overview', $site) }}"
                               class="truncate text-sm font-medium hover:opacity-80"
                               style="color: {{ $site->siteStatus?->color ?? '#111827' }}"
                               @if($site->siteStatus) title="{{ $site->siteStatus->name }}" @endif
                            >{{ $site->domain }}</a>
                        </div>

                        {{-- Updates + Plugin count + Quick actions --}}
                        <div class="flex flex-shrink-0 items-center gap-2">
                            @if($site->is_connected && $updates > 0)
                                <span class="hidden h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold leading-none text-white lg:inline-flex {{ $updateBadgeColor }}" title="{{ $updates }} updates available">
                                    {{ $updates }}
                                </span>
                                <div class="mx-0.5 hidden h-4 w-px bg-gray-200 lg:block"></div>
                            @endif
                            <span class="hidden text-xs text-gray-500 lg:inline" title="{{ $site->site_plugins_count }} plugins">
                                {{ $site->site_plugins_count ?? 0 }}p
                            </span>

                            <button
                                wire:click="syncSite({{ $site->id }})"
                                class="hidden rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-purple-600 lg:inline-flex"
                                title="Sync site"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            </button>

                            <a
                                href="{{ $site->url }}"
                                target="_blank"
                                rel="noopener"
                                class="hidden rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-purple-600 lg:inline-flex"
                                title="Open site"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            </a>

                            {{-- Separator --}}
                            <div class="mx-1 hidden h-4 w-px bg-gray-200 lg:block"></div>
                        </div>

                        {{-- Status Icons (hidden below lg) --}}
                        <div class="hidden items-center gap-1.5 lg:flex">
                            {{-- 1. Uptime --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $uptimeColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.uptime :site="$site" />
                            </x-ui.hovercard>

                            {{-- 2. SSL --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $sslColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.ssl :site="$site" />
                            </x-ui.hovercard>

                            {{-- 3. Response Time --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $responseColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.response-time :site="$site" />
                            </x-ui.hovercard>

                            {{-- 4. Analytics --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $perfColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.analytics :site="$site" />
                            </x-ui.hovercard>

                            {{-- 5. Links --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $linksColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.links :site="$site" />
                            </x-ui.hovercard>

                            {{-- 6. Domain --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $domainColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.domain :site="$site" />
                            </x-ui.hovercard>

                            <div class="mx-0.5 h-4 w-px bg-gray-200"></div>

                            {{-- 7. Plugins/Updates --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $pluginsColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.plugins :site="$site" />
                            </x-ui.hovercard>

                            {{-- 8. Users --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $usersColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.users :site="$site" />
                            </x-ui.hovercard>

                            {{-- 9. WordPress Connected --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $wpConnColor }}" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zM3.5 12c0-1.19.25-2.32.69-3.35l3.81 10.44A8.51 8.51 0 013.5 12zm8.5 8.5c-.83 0-1.64-.12-2.4-.34l2.55-7.41 2.61 7.15c.02.04.04.07.06.1-.89.32-1.84.5-2.82.5zm1.1-12.47c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.83 0-2.24-.11-2.24-.11-.46-.02-.51.68-.05.7 0 0 .43.06.89.08l1.32 3.61-1.85 5.56-3.08-9.17c.51-.03.97-.08.97-.08.46-.05.4-.72-.05-.7 0 0-1.37.11-2.26.11-.16 0-.35 0-.55-.01A8.49 8.49 0 0112 3.5c2.13 0 4.07.78 5.56 2.07-.04 0-.07-.01-.11-.01-1.39 0-2.08 1.07-2.08 1.9 0 .7.38 1.29.78 2 .3.52.65 1.19.65 2.16 0 .67-.26 1.45-.6 2.53l-.79 2.63-2.86-8.75zM16.62 18.77l2.59-7.47c.48-1.21.64-2.17.64-3.03 0-.31-.02-.6-.06-.87A8.48 8.48 0 0120.5 12a8.51 8.51 0 01-3.88 6.77z"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.wordpress :site="$site" />
                            </x-ui.hovercard>

                            <div class="mx-0.5 h-4 w-px bg-gray-200"></div>

                            {{-- 10. Backup --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $backupColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.backup :site="$site" />
                            </x-ui.hovercard>

                            {{-- 11. WP Version --}}
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $wpVerColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/></svg>
                                </x-slot:trigger>
                                <x-hovercards.wp-version :site="$site" />
                            </x-ui.hovercard>

                            {{-- 12. Reports --}}
                            @php $reportsColor = $site->reportSchedules->isNotEmpty() ? 'text-green-500' : 'text-gray-300'; @endphp
                            <x-ui.hovercard>
                                <x-slot:trigger>
                                    <svg class="h-[17px] w-[17px] {{ $reportsColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                </x-slot:trigger>
                                <x-hovercards.reports :site="$site" />
                            </x-ui.hovercard>
                        </div>

                        {{-- Health Bar --}}
                        <x-ui.tooltip :text="'Health: ' . $healthScore . '/100'">
                            <div class="hidden w-16 flex-shrink-0 sm:block">
                                <div class="h-2 w-full rounded-full bg-gray-200">
                                    <div class="h-2 rounded-full {{ $healthBarColor }}" style="width: {{ $healthWidth }}%"></div>
                                </div>
                            </div>
                        </x-ui.tooltip>

                        {{-- Three-dot Dropdown --}}
                        <x-ui.dropdown align="right" width="48">
                            <x-slot:trigger>
                                <button class="rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                                </button>
                            </x-slot:trigger>

                            {{-- Navigation links --}}
                            <a href="{{ route('sites.overview', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Overview</a>
                            <a href="{{ route('sites.plugins', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Plugins</a>
                            <a href="{{ route('sites.backups', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Backups</a>
                            <a href="{{ route('sites.uptime', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Uptime</a>
                            <a href="{{ route('sites.performance', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Performance</a>
                            <a href="{{ route('sites.settings', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Settings</a>

                            {{-- Divider --}}
                            <div class="my-1 border-t border-gray-100"></div>

                            {{-- Action buttons --}}
                            <button wire:click="runBackup({{ $site->id }})" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Run Backup</button>
                            <button wire:click="checkNow({{ $site->id }})" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Check Uptime</button>
                            <button wire:click="syncSite({{ $site->id }})" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Sync Site</button>

                            {{-- Divider --}}
                            <div class="my-1 border-t border-gray-100"></div>

                            {{-- Management actions --}}
                            <button wire:click="startRename({{ $site->id }}, '{{ addslashes($site->name) }}')" class="block w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Rename</button>
                            <a href="{{ route('sites.settings', $site) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Edit Settings</a>

                            {{-- Status assignment --}}
                            @if($this->siteStatuses->isNotEmpty())
                                <div class="my-1 border-t border-gray-100"></div>
                                <div class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">Status</div>
                                @foreach($this->siteStatuses as $status)
                                    <button wire:click="setSiteStatus({{ $site->id }}, {{ $status->id }})" class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">
                                        <span class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                                        {{ $status->name }}
                                        @if($site->site_status_id === $status->id)
                                            <svg class="ml-auto h-4 w-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        @endif
                                    </button>
                                @endforeach
                                <button wire:click="setSiteStatus({{ $site->id }}, null)" class="block w-full px-4 py-2 text-left text-sm text-gray-500 hover:bg-gray-50">Clear Status</button>
                            @endif

                            <div class="my-1 border-t border-gray-100"></div>
                            <button wire:click="confirmDelete({{ $site->id }}, '{{ addslashes($site->name) }}')" class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">Delete Site</button>
                        </x-ui.dropdown>
                    </div>
                @endforeach
                </div>
            </div>

            <div class="mt-4">
                {{ $this->sites->links() }}
            </div>
        @endif
    </div>

    {{-- Section 4: Bottom Action Buttons --}}
    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <a
            href="{{ route('sites.create', ['mode' => 'connect']) }}"
            class="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-4 text-sm font-medium text-gray-500 transition hover:border-purple-400 hover:bg-purple-50 hover:text-purple-600"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            Connect Existing Site
        </a>
        <a
            href="{{ route('sites.create', ['mode' => 'create']) }}"
            class="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-4 text-sm font-medium text-gray-500 transition hover:border-purple-400 hover:bg-purple-50 hover:text-purple-600"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Create New Site
        </a>
        <a
            href="{{ route('sites.create', ['mode' => 'migrate']) }}"
            class="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-4 text-sm font-medium text-gray-500 transition hover:border-purple-400 hover:bg-purple-50 hover:text-purple-600"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
            Migrate Existing Site
        </a>
        <a
            href="{{ route('sites.create', ['mode' => 'clone']) }}"
            class="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed border-gray-300 px-4 py-4 text-sm font-medium text-gray-500 transition hover:border-purple-400 hover:bg-purple-50 hover:text-purple-600"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            Clone A Site
        </a>
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
