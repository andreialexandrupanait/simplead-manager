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

    {{-- Section 1: Mini Stat Cards --}}
    @php
        $stats = $this->stats;
        $trends = $this->trends;

        $uptimeColor = 'text-green-600';
        $uptimeBg = 'bg-green-50';
        $uptimeIcon = 'text-green-500';
        if ($stats['avg_uptime'] !== null) {
            if ($stats['avg_uptime'] < 95) { $uptimeColor = 'text-red-600'; $uptimeBg = 'bg-red-50'; $uptimeIcon = 'text-red-500'; }
            elseif ($stats['avg_uptime'] < 99) { $uptimeColor = 'text-yellow-600'; $uptimeBg = 'bg-yellow-50'; $uptimeIcon = 'text-yellow-500'; }
        }

        $bytes = $stats['backup_storage_bytes'] ?? 0;
        if ($bytes >= 1073741824) {
            $storageLabel = round($bytes / 1073741824, 1) . ' GB';
        } elseif ($bytes >= 1048576) {
            $storageLabel = round($bytes / 1048576, 0) . ' MB';
        } else {
            $storageLabel = '0 MB';
        }

        // Helper: trend arrow HTML (inline, safe to use with {!! !!})
        $trendArrow = function (string $direction, bool $invertColors = false): string {
            if ($direction === 'up') {
                $color = $invertColors ? 'text-red-500' : 'text-green-500';
                return '<svg class="inline-block h-3 w-3 ' . $color . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/></svg>';
            }
            if ($direction === 'down') {
                $color = $invertColors ? 'text-green-500' : 'text-red-500';
                return '<svg class="inline-block h-3 w-3 ' . $color . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>';
            }
            return '<svg class="inline-block h-3 w-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14"/></svg>';
        };
    @endphp
    @php
        // Smart link for Alerts card
        if ($stats['sites_down'] > 0) {
            $alertsLink = route('uptime.index', ['filter' => 'down']);
        } elseif ($stats['failed_backups'] > 0) {
            $alertsLink = route('backups.index', ['filter' => 'failed']);
        } else {
            $alertsLink = route('uptime.index');
        }
    @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {{-- Sites --}}
        <a href="#sites" class="block">
            <x-ui.card :padding="false" class="p-4 transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $stats['sites_down'] > 0 ? 'bg-red-50' : 'bg-green-50' }}">
                        <x-icons.globe class="h-5 w-5 {{ $stats['sites_down'] > 0 ? 'text-red-500' : 'text-green-500' }}" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-base font-semibold text-gray-900">{{ $stats['total_sites'] }}</div>
                        <div class="text-xs text-gray-500">{{ __('Sites') }}</div>
                        <div class="mt-0.5 text-xs font-medium {{ $stats['sites_down'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                            {{ $stats['sites_down'] === 0 ? __('all operational') : $stats['sites_down'] . ' ' . __('down') }}
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Uptime --}}
        <a href="{{ route('uptime.index') }}" class="block">
            <x-ui.card :padding="false" class="p-4 transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $uptimeBg }}">
                        <x-icons.trending-up class="h-5 w-5 {{ $uptimeIcon }}" />
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1">
                            <span class="text-base font-semibold {{ $uptimeColor }}">{{ $stats['avg_uptime'] !== null ? $stats['avg_uptime'] . '%' : '—' }}</span>
                            {!! $trendArrow($trends['uptime']['direction']) !!}
                        </div>
                        <div class="text-xs text-gray-500">{{ __('Uptime') }}</div>
                        <div class="mt-0.5 text-xs text-gray-400">{{ __('last 30 days') }}</div>
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Backup Storage --}}
        @php
            $hasFailed = $stats['failed_backups'] > 0;
            $hasStale = ($stats['stale_backups'] ?? 0) > 0;
            $backupAlert = $hasFailed || $hasStale;
            $iconBg = $backupAlert ? 'bg-red-50' : 'bg-accent-50';
            $iconColor = $backupAlert ? 'text-red-500' : 'text-accent-500';
            $valueColor = $backupAlert ? 'text-red-600' : 'text-accent-600';
        @endphp
        <a href="{{ route('backups.index') }}" class="block">
            <x-ui.card :padding="false" class="p-4 transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $iconBg }}">
                        <x-icons.hard-drive class="h-5 w-5 {{ $iconColor }}" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-base font-semibold {{ $valueColor }}">{{ $storageLabel }}</div>
                        <div class="text-xs text-gray-500">{{ __('Backup Storage') }}</div>
                        <div class="mt-0.5 flex items-center gap-1 text-xs {{ $backupAlert ? 'text-red-500 font-medium' : 'text-gray-400' }}">
                            @if($backupAlert)
                                @php
                                    $parts = [];
                                    if ($hasFailed) $parts[] = $stats['failed_backups'] . ' ' . __('failed (24h)');
                                    if ($hasStale) $parts[] = $stats['stale_backups'] . ' ' . __('stale (>36h)');
                                @endphp
                                {{ implode(', ', $parts) }}
                            @else
                                {{ __('all healthy') }}
                            @endif
                            {!! $trendArrow($trends['failed_backups']['direction'], true) !!}
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Backups Today --}}
        <a href="{{ route('backups.index') }}" class="block">
            <x-ui.card :padding="false" class="p-4 transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50">
                        <x-icons.check-circle class="h-5 w-5 text-blue-500" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-base font-semibold text-blue-600">{{ $stats['backups_today'] }}</div>
                        <div class="text-xs text-gray-500">{{ __('Backups Today') }}</div>
                        <div class="mt-0.5 text-xs text-gray-400">{{ __('completed') }}</div>
                    </div>
                </div>
            </x-ui.card>
        </a>

        {{-- Alerts --}}
        <a href="{{ $alertsLink }}" class="block">
            <x-ui.card :padding="false" class="p-4 transition hover:ring-accent-200">
                <div class="flex items-start gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $stats['total_alerts'] > 0 ? 'bg-red-50' : 'bg-green-50' }}">
                        @if($stats['total_alerts'] > 0)
                            <x-icons.alert-triangle class="h-5 w-5 text-red-500" />
                        @else
                            <x-icons.shield class="h-5 w-5 text-green-500" />
                        @endif
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1">
                            <span class="text-base font-semibold {{ $stats['total_alerts'] > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $stats['total_alerts'] }}</span>
                            {{-- Alerts up = bad (invertColors) --}}
                            {!! $trendArrow($trends['pending_updates']['direction'], true) !!}
                        </div>
                        <div class="text-xs text-gray-500">{{ __('Alerts') }}</div>
                        <div class="mt-0.5 text-xs {{ $stats['total_alerts'] > 0 ? 'text-red-500 font-medium' : 'text-gray-400' }}">
                            @if($stats['total_alerts'] === 0)
                                {{ __('all clear') }}
                            @else
                                @php
                                    $parts = [];
                                    if ($stats['sites_down'] > 0) $parts[] = $stats['sites_down'] . ' ' . __('down');
                                    if ($stats['failed_backups'] > 0) $parts[] = $stats['failed_backups'] . ' ' . __('backup');
                                    if (($stats['stale_backups'] ?? 0) > 0) $parts[] = $stats['stale_backups'] . ' ' . __('stale');
                                @endphp
                                {{ implode(', ', $parts) }}
                            @endif
                        </div>
                    </div>
                </div>
            </x-ui.card>
        </a>
    </div>

    {{-- Backup Health (averaged across configured sites + bottom-N for triage) --}}
    @php $health = $stats['backup_health'] ?? null; @endphp
    @if($health && $health['sites_count'] > 0)
        @php
            $avg = (float) ($health['avg_score'] ?? 0);
            $avgColor = match(true) {
                $avg >= 80 => 'text-green-600',
                $avg >= 50 => 'text-yellow-600',
                $avg >= 25 => 'text-orange-600',
                default => 'text-red-600',
            };
            $avgBg = match(true) {
                $avg >= 80 => 'bg-green-50',
                $avg >= 50 => 'bg-yellow-50',
                $avg >= 25 => 'bg-orange-50',
                default => 'bg-red-50',
            };
        @endphp
        <div class="mt-6">
            <x-ui.card>
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg {{ $avgBg }}">
                            <span class="text-base font-semibold {{ $avgColor }}">{{ (int) round($avg) }}</span>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ __('Backup Health') }}</h3>
                            <p class="text-xs text-gray-500">{{ __('Average score across :n configured sites', ['n' => $health['sites_count']]) }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 text-green-700"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> {{ $health['excellent'] }} {{ __('excellent') }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-yellow-50 px-2 py-0.5 text-yellow-700"><span class="h-1.5 w-1.5 rounded-full bg-yellow-500"></span> {{ $health['ok'] }} {{ __('ok') }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 px-2 py-0.5 text-orange-700"><span class="h-1.5 w-1.5 rounded-full bg-orange-500"></span> {{ $health['warning'] }} {{ __('warning') }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-red-700"><span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> {{ $health['critical'] }} {{ __('critical') }}</span>
                    </div>
                </div>

                @if(! empty($health['bottom']))
                    <div class="mt-4 border-t border-gray-100 pt-3">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500 mb-2">{{ __('Lowest scores — needs attention') }}</div>
                        <div class="space-y-1.5">
                            @foreach($health['bottom'] as $entry)
                                @php
                                    $entryColor = match(true) {
                                        $entry['score'] >= 80 => 'bg-green-100 text-green-700',
                                        $entry['score'] >= 50 => 'bg-yellow-100 text-yellow-700',
                                        $entry['score'] >= 25 => 'bg-orange-100 text-orange-700',
                                        default => 'bg-red-100 text-red-700',
                                    };
                                @endphp
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="inline-flex w-9 shrink-0 justify-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $entryColor }}">{{ $entry['score'] }}</span>
                                    <a href="{{ route('sites.backups', $entry['site_id']) }}" class="font-medium text-gray-800 hover:text-accent-600 truncate">{{ $entry['name'] }}</a>
                                    <span class="truncate text-xs text-gray-500">{{ implode(' · ', $entry['reasons']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>
        </div>
    @endif

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
                                    <span class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
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
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkSync">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        {{ __('Sync') }}
                    </x-ui.button>

                    {{-- Backup --}}
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkBackup">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                        {{ __('Backup') }}
                    </x-ui.button>

                    {{-- Check Uptime --}}
                    <x-ui.button variant="secondary" size="sm" wire:click="bulkCheckUptime">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        {{ __('Check Uptime') }}
                    </x-ui.button>

                    {{-- Delete (danger) --}}
                    <x-ui.button variant="danger" size="sm" wire:click="confirmBulkDelete">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        {{ __('Delete') }}
                    </x-ui.button>

                    {{-- Deselect all --}}
                    <button wire:click="clearSelection" class="rounded-lg p-1.5 text-accent-400 transition hover:bg-accent-100 hover:text-accent-600" title="{{ __('Clear selection') }}">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
                <x-ui.dropdown align="left" width="56">
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $clientActive ? 'border-accent-300 bg-accent-50 text-accent-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <span class="max-w-[8rem] truncate">{{ $clientLabel }}</span>
                            <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </x-slot:trigger>

                    <button wire:click="setClientFilter(null)" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ !$clientActive ? 'bg-accent-50 text-accent-700' : 'text-gray-700 hover:bg-gray-50' }}">
                        {{ __('All Clients') }}
                        @if(!$clientActive)
                            <svg class="h-4 w-4 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        @endif
                    </button>
                    @foreach($this->clients as $client)
                        <button wire:click="setClientFilter({{ $client->id }})" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->clientFilter === $client->id ? 'bg-accent-50 text-accent-700' : 'text-gray-700 hover:bg-gray-50' }}">
                            {{ $client->name }} ({{ $client->sites_count }})
                            @if($this->clientFilter === $client->id)
                                <svg class="h-4 w-4 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </button>
                    @endforeach
                </x-ui.dropdown>

                {{-- Health Pill --}}
                @php
                    $healthActive = $this->filter !== 'all';
                    $healthLabels = ['all' => __('Health'), 'healthy' => __('Healthy'), 'warning' => __('Warning'), 'critical' => __('Critical')];
                    $healthLabel = $healthLabels[$this->filter] ?? __('Health');
                @endphp
                <x-ui.dropdown align="left" width="48">
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $healthActive ? 'border-accent-300 bg-accent-50 text-accent-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                            {{ $healthLabel }}
                            <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </x-slot:trigger>

                    @foreach(['all' => __('All Health'), 'healthy' => __('Healthy'), 'warning' => __('Warning'), 'critical' => __('Critical')] as $value => $label)
                        <button wire:click="setFilter('{{ $value }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->filter === $value ? 'bg-accent-50 text-accent-700' : 'text-gray-700 hover:bg-gray-50' }}">
                            {{ $label }}
                            @if($this->filter === $value)
                                <svg class="h-4 w-4 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </button>
                    @endforeach
                </x-ui.dropdown>

                {{-- Status Pill --}}
                @if($this->siteStatuses->isNotEmpty())
                    @php
                        $statusActive = $this->statusFilter !== null;
                        $statusLabel = __('Status');
                        if ($statusActive) {
                            $selectedStatus = $this->siteStatuses->firstWhere('id', $this->statusFilter);
                            $statusLabel = $selectedStatus ? $selectedStatus->name : __('Status');
                        }
                    @endphp
                    <x-ui.dropdown align="left" width="56">
                        <x-slot:trigger>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $statusActive ? 'border-accent-300 bg-accent-50 text-accent-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                <span class="max-w-[8rem] truncate">{{ $statusLabel }}</span>
                                <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                        </x-slot:trigger>

                        <button wire:click="setStatusFilter(null)" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ !$statusActive ? 'bg-accent-50 text-accent-700' : 'text-gray-700 hover:bg-gray-50' }}">
                            {{ __('All Statuses') }}
                            @if(!$statusActive)
                                <svg class="h-4 w-4 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </button>
                        @foreach($this->siteStatuses as $status)
                            <button wire:click="setStatusFilter({{ $status->id }})" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->statusFilter === $status->id ? 'bg-accent-50 text-accent-700' : 'text-gray-700 hover:bg-gray-50' }}">
                                <span class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $status->color }}"></span>
                                    {{ $status->name }} ({{ $status->sites_count }})
                                </span>
                                @if($this->statusFilter === $status->id)
                                    <svg class="h-4 w-4 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                @endif
                            </button>
                        @endforeach
                    </x-ui.dropdown>
                @endif

                {{-- Sort Pill --}}
                @php
                    $sortActive = $this->sort !== 'manual';
                    $sortLabels = ['manual' => __('Manual'), 'health-asc' => __('Health') . ' ↑', 'health-desc' => __('Health') . ' ↓', 'name-asc' => __('Name A-Z'), 'name-desc' => __('Name Z-A')];
                    $sortLabel = $sortLabels[$this->sort] ?? __('Sort');
                    $isManualSort = $this->sort === 'manual';
                @endphp
                <x-ui.dropdown align="left" width="48">
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition {{ $sortActive ? 'border-accent-300 bg-accent-50 text-accent-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
                            {{ $sortLabel }}
                            <svg class="h-3 w-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </x-slot:trigger>

                    @foreach(['manual' => __('Manual'), 'name-asc' => __('Name A-Z'), 'name-desc' => __('Name Z-A'), 'health-asc' => __('Health') . ' ↑', 'health-desc' => __('Health') . ' ↓'] as $value => $label)
                        <button wire:click="setSort('{{ $value }}')" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm {{ $this->sort === $value ? 'bg-accent-50 text-accent-700' : 'text-gray-700 hover:bg-gray-50' }}">
                            {{ $label }}
                            @if($this->sort === $value)
                                <svg class="h-4 w-4 text-accent-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            @endif
                        </button>
                    @endforeach
                </x-ui.dropdown>

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
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5" x-data="sortableList" x-effect="enabled = @js($this->reordering)">
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
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Rename Site') }}</h2>
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
            <h2 class="text-lg font-semibold text-gray-900">{{ __('Delete Site') }}</h2>
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
