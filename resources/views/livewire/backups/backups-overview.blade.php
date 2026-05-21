<div>
    {{-- Header with Add Button --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="{{ __('Backups') }}" subtitle="{{ __('Manage site backups and restore points') }}" />
        <x-ui.button wire:click="backupAllSites" wire:loading.attr="disabled" wire:confirm="{{ __('This will queue backups for all connected sites with an active backup configuration. Continue?') }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8" /></svg>
            <span wire:loading.remove wire:target="backupAllSites">{{ __('Backup All Sites') }}</span>
            <span wire:loading wire:target="backupAllSites">{{ __('Queuing...') }}</span>
        </x-ui.button>
    </div>

    <x-ui.flash-alert type="success" key="backup-success" />
    <x-ui.flash-alert type="error" key="backup-error" />

    {{-- Backup Health (averaged across configured sites + bottom-N for triage) --}}
    @php $health = $this->backupHealth; @endphp
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
        <x-ui.card class="mb-6">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg {{ $avgBg }}">
                        <span class="text-base font-semibold {{ $avgColor }}">{{ (int) round($avg) }}</span>
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Backup Health') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Average score across :n configured sites', ['n' => $health['sites_count']]) }}</p>
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
                <div class="mt-4 border-t border-gray-100 dark:border-gray-700 pt-3">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ __('Lowest scores — needs attention') }}</div>
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
                                <a href="{{ route('sites.backups', $entry['site_id']) }}" class="font-medium text-gray-800 dark:text-gray-100 hover:text-accent-600 dark:hover:text-accent-400 truncate">{{ $entry['name'] }}</a>
                                <span class="truncate text-xs text-gray-500 dark:text-gray-400">{{ implode(' · ', $entry['reasons']) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-ui.card>
    @endif

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-gray-900">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Total Backups') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-green-600">{{ $this->stats['completed'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Completed') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-red-600">{{ $this->stats['failed'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Failed') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-accent-600">{{ $this->stats['in_progress'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('In Progress') }}</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        @php
            $staleLabel = __('Stale').($this->stats['stale'] > 0 ? ' ('.$this->stats['stale'].')' : '');
        @endphp
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'completed' => __('Completed'), 'failed' => __('Failed'), 'in_progress' => __('In Progress'), 'stale' => $staleLabel]"
            :selected="$filter"
            wire="filter"
        />
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search by site name...') }}"
            class="w-full sm:ml-auto sm:w-64"
        />
    </div>

    {{-- Stale Sites view: site-level health, not backup rows --}}
    @if($filter === 'stale')
        <x-ui.card class="overflow-hidden !p-0">
            @if($this->staleSites->isEmpty())
                <div class="px-6 py-12 text-center">
                    <svg class="mx-auto h-10 w-10 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="mt-3 text-sm font-medium text-gray-700">{{ __('No stale sites — all backups are within the last 36h.') }}</p>
                </div>
            @else
                <div class="border-b border-yellow-200 bg-yellow-50 px-5 py-3 text-sm text-yellow-800">
                    <strong>{{ $this->staleSites->count() }}</strong>
                    {{ __('site(s) have backup enabled but no successful backup in the last 36 hours (or ever). Investigate each row below.') }}
                </div>

                {{-- Mobile cards --}}
                <div class="md:hidden divide-y divide-gray-200">
                    @foreach($this->staleSites as $site)
                        @php
                            $monitoringDisabled = $site->healthState?->is_monitoring_disabled === true;
                            $cause = ! $site->is_connected
                                ? __('Plugin disconnected')
                                : ($monitoringDisabled
                                    ? __('Monitoring disabled — needs re-enable')
                                    : ($site->last_backup_at === null ? __('Never ran') : __('Job stuck / errored')));
                        @endphp
                        <div class="p-4 space-y-2">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('sites.backups', $site) }}" class="text-accent-600 hover:text-accent-800 font-medium text-sm">{{ $site->name }}</a>
                                    <div class="text-xs text-gray-400 truncate">{{ $site->url }}</div>
                                </div>
                                @if($site->is_connected)
                                    <x-ui.badge variant="green">{{ __('Connected') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="red">{{ __('Disconnected') }}</x-ui.badge>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                <span>{{ __('Last backup') }}:
                                    @if($site->last_backup_at)
                                        <span class="text-gray-700">{{ $site->last_backup_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-red-600 font-medium">{{ __('Never') }}</span>
                                    @endif
                                </span>
                                <span>{{ __('Last sync') }}:
                                    <span class="text-gray-700">{{ $site->last_synced_at?->diffForHumans() ?? '—' }}</span>
                                </span>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-medium {{ $site->is_connected ? ($monitoringDisabled ? 'text-orange-600' : 'text-yellow-600') : 'text-red-600' }}">{{ $cause }}</span>
                                @if($site->is_connected && $monitoringDisabled)
                                    <button
                                        wire:click="reEnableMonitoring({{ $site->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="reEnableMonitoring({{ $site->id }})"
                                        class="inline-flex items-center rounded-lg border border-orange-300 bg-white px-3 py-1.5 text-xs font-medium text-orange-700 hover:bg-orange-50 disabled:opacity-50 transition"
                                    >
                                        <span wire:loading.remove wire:target="reEnableMonitoring({{ $site->id }})">{{ __('Re-enable monitoring') }}</span>
                                        <span wire:loading wire:target="reEnableMonitoring({{ $site->id }})">{{ __('Re-enabling...') }}</span>
                                    </button>
                                @elseif($site->is_connected)
                                    <button
                                        wire:click="backupStaleSite({{ $site->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="backupStaleSite({{ $site->id }})"
                                        class="inline-flex items-center rounded-lg border border-accent-300 bg-white px-3 py-1.5 text-xs font-medium text-accent-700 hover:bg-accent-50 disabled:opacity-50 transition"
                                    >
                                        <span wire:loading.remove wire:target="backupStaleSite({{ $site->id }})">{{ __('Backup Now') }}</span>
                                        <span wire:loading wire:target="backupStaleSite({{ $site->id }})">{{ __('Queuing...') }}</span>
                                    </button>
                                @else
                                    <a href="{{ route('sites.overview', $site) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition">
                                        {{ __('Fix Connection') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Desktop table --}}
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Site') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Last Backup') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Last Sync') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Connector') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Likely Cause') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($this->staleSites as $site)
                                @php
                                    $monitoringDisabled = $site->healthState?->is_monitoring_disabled === true;
                                    $cause = ! $site->is_connected
                                        ? __('Plugin disconnected')
                                        : ($monitoringDisabled
                                            ? __('Monitoring disabled — needs re-enable')
                                            : ($site->last_backup_at === null ? __('Never ran') : __('Job stuck / errored')));
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-3 text-sm">
                                        <a href="{{ route('sites.backups', $site) }}" class="text-accent-600 hover:text-accent-800 font-medium">{{ $site->name }}</a>
                                        <div class="text-xs text-gray-400 truncate">{{ $site->url }}</div>
                                    </td>
                                    <td class="px-3 py-3 text-sm text-gray-700">
                                        @if($site->last_backup_at)
                                            <span title="{{ $site->last_backup_at->toDateTimeString() }}">{{ $site->last_backup_at->diffForHumans() }}</span>
                                        @else
                                            <span class="text-red-600 font-medium">{{ __('Never') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-sm text-gray-700">
                                        {{ $site->last_synced_at?->diffForHumans() ?? '—' }}
                                    </td>
                                    <td class="px-3 py-3 text-sm">
                                        <x-ui.badge :variant="$site->is_connected ? 'green' : 'red'">
                                            {{ $site->is_connected ? __('Connected') : __('Disconnected') }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="px-3 py-3 text-sm font-medium {{ $site->is_connected ? ($monitoringDisabled ? 'text-orange-600' : 'text-yellow-600') : 'text-red-600' }}">{{ $cause }}</td>
                                    <td class="px-3 py-3 text-right">
                                        @if($site->is_connected && $monitoringDisabled)
                                            <button
                                                wire:click="reEnableMonitoring({{ $site->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="reEnableMonitoring({{ $site->id }})"
                                                class="inline-flex items-center rounded-lg border border-orange-300 bg-white px-3 py-1.5 text-xs font-medium text-orange-700 hover:bg-orange-50 disabled:opacity-50 transition"
                                            >
                                                <span wire:loading.remove wire:target="reEnableMonitoring({{ $site->id }})">{{ __('Re-enable monitoring') }}</span>
                                                <span wire:loading wire:target="reEnableMonitoring({{ $site->id }})">{{ __('Re-enabling...') }}</span>
                                            </button>
                                        @elseif($site->is_connected)
                                            <button
                                                wire:click="backupStaleSite({{ $site->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="backupStaleSite({{ $site->id }})"
                                                class="inline-flex items-center rounded-lg border border-accent-300 bg-white px-3 py-1.5 text-xs font-medium text-accent-700 hover:bg-accent-50 disabled:opacity-50 transition"
                                            >
                                                <span wire:loading.remove wire:target="backupStaleSite({{ $site->id }})">{{ __('Backup Now') }}</span>
                                                <span wire:loading wire:target="backupStaleSite({{ $site->id }})">{{ __('Queuing...') }}</span>
                                            </button>
                                        @else
                                            <a href="{{ route('sites.overview', $site) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition">
                                                {{ __('Fix Connection') }}
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    @else

    {{-- Backups Table --}}
    <x-ui.card class="overflow-hidden !p-0"
        x-data="{
            selected: [],
            get allSelected() {
                return this.selected.length === {{ $backups->count() }} && this.selected.length > 0;
            },
            toggleAll() {
                if (this.allSelected) {
                    this.selected = [];
                } else {
                    this.selected = [{{ $backups->pluck('id')->implode(',') }}];
                }
            }
        }"
    >
        {{-- Bulk action bar (desktop only — bulk selection is impractical on touch) --}}
        <div x-show="selected.length > 0" x-cloak class="hidden md:flex items-center gap-3 border-b border-gray-200 bg-accent-50/50 px-5 py-2.5">
            <span class="text-sm font-medium text-accent-700" x-text="selected.length + ' {{ __('selected') }}'"></span>
            <button
                @click="if (confirm('{{ __('Delete') }} ' + selected.length + ' {{ __('backup(s)? This cannot be undone.') }}')) { $wire.bulkDelete(selected).then(() => selected = []) }"
                class="inline-flex items-center rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition"
            >
                <x-icons.x class="mr-1 h-3.5 w-3.5" />
                {{ __('Delete Selected') }}
            </button>
        </div>

        @if($backups->isEmpty())
            <p class="text-sm text-gray-500 text-center py-8">{{ __('No backups found.') }}</p>
        @else
            {{-- Mobile cards --}}
            <div class="md:hidden divide-y divide-gray-200">
                @foreach($backups as $backup)
                    <div class="p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                @if($backup->site)
                                    <a href="{{ route('sites.backups', $backup->site) }}" class="font-medium text-accent-600 hover:text-accent-800 truncate block">
                                        {{ $backup->site->name }}
                                    </a>
                                    <p class="text-xs text-gray-400 truncate">{{ $backup->site->domain }}</p>
                                @else
                                    <span class="text-sm text-gray-400">{{ __('Deleted site') }}</span>
                                @endif
                            </div>
                            <button wire:click="deleteBackup({{ $backup->id }})"
                                    wire:confirm="{{ __('Delete this backup? This cannot be undone.') }}"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition disabled:opacity-50 shrink-0"
                                    title="{{ __('Delete') }}">
                                <x-icons.x class="h-3.5 w-3.5" />
                            </button>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            <x-ui.badge variant="blue">{{ ucfirst($backup->type) }}</x-ui.badge>
                            <span class="text-xs text-gray-600">{{ $backup->file_size_formatted }}</span>
                            <x-ui.badge :variant="$backup->status_color">{{ $backup->status->label() }}</x-ui.badge>
                            @if($backup->is_locked)
                                <x-ui.badge variant="purple">{{ __('Locked') }}</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-1.5 text-xs text-gray-500">
                            {{ $backup->created_at->format('M d, Y H:i') }} · {{ ucfirst(str_replace('_', ' ', $backup->trigger)) }}
                        </p>
                        @if($backup->storageDestination)
                            <p class="mt-0.5 text-xs text-gray-400">{{ __('Storage') }}: {{ $backup->storageDestination->name }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Desktop table --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="w-10 px-3 py-2">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll()"
                                       class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Site') }}</th>
                            <x-ui.sortable-th column="created_at" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Date') }}</x-ui.sortable-th>
                            <x-ui.sortable-th column="type" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Type') }}</x-ui.sortable-th>
                            <x-ui.sortable-th column="file_size" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Size') }}</x-ui.sortable-th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Storage') }}</th>
                            <x-ui.sortable-th column="status" :sortBy="$sortBy" :sortDir="$sortDir">{{ __('Status') }}</x-ui.sortable-th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($backups as $backup)
                            <tr class="hover:bg-gray-50" :class="selected.includes({{ $backup->id }}) && 'bg-accent-50/50'">
                                <td class="px-3 py-3">
                                    <input type="checkbox" value="{{ $backup->id }}" x-model.number="selected"
                                           class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                                </td>
                                <td class="px-3 py-3 text-sm">
                                    @if($backup->site)
                                        @php
                                            $score = $this->siteHealthScores[$backup->site->id] ?? null;
                                            $scoreColor = match(true) {
                                                $score === null => 'bg-gray-100 text-gray-500',
                                                $score >= 80 => 'bg-green-100 text-green-700',
                                                $score >= 50 => 'bg-yellow-100 text-yellow-700',
                                                $score >= 25 => 'bg-orange-100 text-orange-700',
                                                default => 'bg-red-100 text-red-700',
                                            };
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            @if($score !== null)
                                                <span class="inline-flex w-9 shrink-0 justify-center rounded px-1.5 py-0.5 text-xs font-semibold {{ $scoreColor }}" title="{{ __('Backup health score') }}">{{ $score }}</span>
                                            @endif
                                            <div class="min-w-0">
                                                <a href="{{ route('sites.backups', $backup->site) }}" class="text-accent-600 hover:text-accent-800 font-medium">
                                                    {{ $backup->site->name }}
                                                </a>
                                                <div class="text-xs text-gray-400 truncate">{{ $backup->site->domain }}</div>
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-gray-400">{{ __('Deleted site') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-900">
                                    {{ $backup->created_at->format('M d, Y H:i') }}
                                    <div class="text-xs text-gray-400">{{ ucfirst(str_replace('_', ' ', $backup->trigger)) }}</div>
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">
                                    {{ ucfirst($backup->type) }}
                                    @if($backup->format === 'multipart-v3')
                                        <x-ui.badge variant="blue" class="ml-1" title="{{ __('Streaming multipart upload') }}">⚡</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-sm text-gray-700">{{ $backup->file_size_formatted }}</td>
                                <td class="px-3 py-3 text-sm text-gray-700">
                                    {{ $backup->storageDestination?->name ?? '—' }}
                                    @php
                                        $replicaCount = is_array($backup->replicas) ? count($backup->replicas) : 0;
                                        $expectedReplicas = ($backup->site?->backupConfig?->secondary_storage_destination_id) ? 2 : 1;
                                    @endphp
                                    @if($replicaCount >= 2)
                                        <x-ui.badge variant="green" class="ml-1" title="{{ __(':n replicas', ['n' => $replicaCount]) }}">{{ $replicaCount }}×</x-ui.badge>
                                    @elseif($expectedReplicas === 2 && $backup->status->value === 'completed')
                                        <x-ui.badge variant="yellow" class="ml-1" title="{{ __('Secondary replica missing') }}">1/2</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <x-ui.badge :variant="$backup->status_color">{{ $backup->status->label() }}</x-ui.badge>
                                    @if($backup->is_locked)
                                        <x-ui.badge variant="purple" class="ml-1">{{ __('Locked') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <button wire:click="deleteBackup({{ $backup->id }})"
                                            wire:confirm="{{ __('Delete this backup? This cannot be undone.') }}"
                                            wire:loading.attr="disabled"
                                            class="inline-flex items-center rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition disabled:opacity-50"
                                            title="{{ __('Delete') }}">
                                        <x-icons.x class="h-3.5 w-3.5" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($backups->hasPages())
                <div class="border-t border-gray-200 px-5 py-3">
                    {{ $backups->links() }}
                </div>
            @endif
        @endif
    </x-ui.card>
    @endif
</div>
