<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header
            title="{{ __('Backups') }}"
            subtitle="{{ __('Every site, and how recently it could be restored') }}"
        />
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button wire:click="backupAllSites" wire:loading.attr="disabled" wire:confirm="{{ __('This will queue backups for all connected sites with an active backup configuration. Continue?') }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8" /></svg>
                <span wire:loading.remove wire:target="backupAllSites">{{ __('Backup All Sites') }}</span>
                <span wire:loading wire:target="backupAllSites">{{ __('Queuing...') }}</span>
            </x-ui.button>
        </div>
    </div>

    <x-ui.flash-alert type="success" key="backup-success" />
    <x-ui.flash-alert type="error" key="backup-error" />

    {{-- ── The ledger ───────────────────────────────────────────────────────────
         One row per site, one column per night, worst first. This replaced four stat
         tiles and a "stale sites" tab: the tiles reported "Completed: 101" while six
         client sites had no restore point at all, and the tab only listed sites that
         were ALREADY broken, so it could never show one about to break.

         It is driven by the site list, never by the `backups` table — a site with no
         runs has no rows, and a query over rows cannot return a site that has none.
         That is precisely how seventeen sites stayed invisible here. --}}
    @php $summary = $this->fleetSummary; @endphp

    <x-ui.card class="mb-8 overflow-hidden !p-0">
        <div class="flex flex-col gap-1 border-b border-gray-200 px-5 py-4 sm:flex-row sm:items-baseline sm:justify-between">
            <p class="text-sm text-gray-700">
                @if($summary['total'] === 0)
                    {{ __('No sites yet.') }}
                @else
                    @if($summary['failing'] === 0)
                        {{ __('All :n scheduled sites have a recent restore point.', ['n' => $summary['scheduled']]) }}
                    @else
                        {!! __(':count of :n scheduled sites have no recent restore point.', [
                            'count' => '<strong class="font-semibold text-red-700">'.$summary['failing'].'</strong>',
                            'n' => $summary['scheduled'],
                        ]) !!}
                    @endif
                    @if($summary['unscheduled'] > 0)
                        <span class="text-gray-500">{{ trans_choice('{1}One more site has no backup schedule.|[2,*]:count more sites have no backup schedule.', $summary['unscheduled'], ['count' => $summary['unscheduled']]) }}</span>
                    @endif
                @endif
            </p>
            <p class="text-xs text-gray-500">{{ __('Last :n nights, oldest left', ['n' => \App\Services\Backup\FleetLedger::NIGHTS]) }}</p>
        </div>

        @if($this->ledger->isEmpty())
            <x-ui.empty-state
                :title="__('No sites to show')"
                :description="__('Add a site and give it a backup schedule to see it here.')"
                icon="globe"
            />
        @else
            {{-- Column labels. Deliberately quiet: the rows are the content, this is a key. --}}
            <div class="hidden items-center gap-4 border-b border-gray-100 px-5 py-2 text-[11px] font-medium uppercase tracking-wide text-gray-500 lg:flex">
                <span class="min-w-0 flex-1">{{ __('Site') }}</span>
                <span class="w-32 shrink-0 text-right">{{ __('Last restore point') }}</span>
                <span class="w-[62px] shrink-0"></span>
                <span class="w-20 shrink-0 text-right">{{ __('Stored') }}</span>
                <span class="w-28 shrink-0 text-right">{{ __('Next run') }}</span>
            </div>

            <div class="divide-y divide-gray-100">
                @foreach($this->ledger as $row)
                    @php
                        $site = $row['site'];
                        $age = $row['age_days'];
                        $problem = $row['problem'];

                        $ageLabel = match (true) {
                            $row['last_valid_at'] === null => __('never'),
                            $age === 0 => $row['last_valid_at']->isoFormat('HH:mm'),
                            $age === 1 => __('yesterday'),
                            default => trans_choice('{1}:count day|[2,*]:count days', $age, ['count' => $age]),
                        };

                        // Colour only where it changes what you would do. A site backed up last
                        // night is the norm, and the norm should not shout.
                        $ageTone = match (true) {
                            $row['last_valid_at'] === null => 'text-red-700 font-semibold',
                            $problem !== null => 'text-red-700 font-medium',
                            default => 'text-gray-700',
                        };
                    @endphp
                    <div class="px-5 py-3">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <div class="order-1 min-w-0 flex-1 basis-full sm:basis-0">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('sites.backups', $site) }}" class="truncate text-sm font-medium text-gray-900 hover:text-accent-600">
                                        {{ $site->name }}
                                    </a>
                                </div>
                                <p class="truncate text-xs text-gray-500">{{ $site->url }}</p>
                            </div>

                            <div class="order-3 w-32 shrink-0 text-right text-sm tabular-nums sm:order-2 {{ $ageTone }}"
                                 @if($row['last_valid_at']) title="{{ $row['last_valid_at']->toDayDateTimeString() }}" @endif>
                                {{ $ageLabel }}
                            </div>

                            <x-ui.night-ledger :nights="$row['nights']" class="order-4 shrink-0 sm:order-3" />

                            <div class="order-5 w-20 shrink-0 text-right text-xs text-gray-500 tabular-nums sm:order-4">
                                {{ $row['stored_bytes'] > 0 ? \App\Helpers\FormatHelper::bytes($row['stored_bytes'], 0) : '—' }}
                            </div>

                            <div class="order-6 hidden w-28 shrink-0 text-right text-xs text-gray-500 lg:block">
                                @if(! $row['scheduled'])
                                    {{ __('not scheduled') }}
                                @elseif($row['next_run_at'])
                                    {{ $row['next_run_at']->isoFormat('D MMM, HH:mm') }}
                                @else
                                    —
                                @endif
                            </div>
                        </div>

                        {{-- Only a site with a problem gets a sentence. A healthy fleet is silent,
                             so every line of text down this page is something to deal with. --}}
                        @if($problem)
                            <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 pl-0 text-xs sm:pl-4">
                                <x-icons.alert-triangle class="h-3.5 w-3.5 shrink-0 text-red-600" aria-hidden="true" />
                                <span class="text-red-700">{{ $problem['message'] }}</span>
                                @if($problem['action'])
                                    <span class="text-gray-600">{{ $problem['action'] }}</span>
                                @endif

                                @if($site->is_connected && $row['scheduled'])
                                    <button
                                        wire:click="backupStaleSite({{ $site->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="backupStaleSite({{ $site->id }})"
                                        class="ml-auto inline-flex items-center rounded-lg border border-accent-300 px-2.5 py-1 font-medium text-accent-700 transition hover:bg-accent-50 disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="backupStaleSite({{ $site->id }})">{{ __('Back up now') }}</span>
                                        <span wire:loading wire:target="backupStaleSite({{ $site->id }})">{{ __('Queuing...') }}</span>
                                    </button>
                                @elseif(! $site->is_connected)
                                    <a href="{{ route('sites.overview', $site) }}" class="ml-auto inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1 font-medium text-gray-700 transition hover:bg-gray-50">
                                        {{ __('Fix connection') }}
                                    </a>
                                @else
                                    <a href="{{ route('sites.backups', $site) }}" class="ml-auto inline-flex items-center rounded-lg border border-gray-300 px-2.5 py-1 font-medium text-gray-700 transition hover:bg-gray-50">
                                        {{ __('Set a schedule') }}
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    {{-- ── Individual runs ────────────────────────────────────────────────────── --}}
    <h2 class="mb-3 text-base font-semibold text-gray-900">{{ __('Recent runs') }}</h2>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'completed' => __('Completed'), 'failed' => __('Failed'), 'in_progress' => __('In Progress')]"
            :selected="$filter"
            wire="filter"
        />
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search by site name...') }}"
            class="w-full sm:ml-auto sm:w-64"
        />
    </div>

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
            <x-ui.empty-state :title="__('No runs match this filter')" :description="__('Change the filter above, or wait for tonight\'s scheduled runs.')" icon="hard-drive" />
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
                                <x-ui.badge variant="blue">{{ __('Locked') }}</x-ui.badge>
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
                                        <x-ui.badge variant="blue" class="ml-1">{{ __('Locked') }}</x-ui.badge>
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
</div>
