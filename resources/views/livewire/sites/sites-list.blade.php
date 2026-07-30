<div>
    {{-- Header with Add Button --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="{{ __('Sites') }}" subtitle="{{ __('Manage all your WordPress sites') }}" />
        <div class="flex items-center gap-3">
            <a href="{{ route('dashboard.classic') }}"
               class="text-sm text-gray-500 hover:text-accent-600 dark:text-gray-400 dark:hover:text-accent-400">
                {{ __('Classic dashboard') }}
            </a>
            <a href="{{ route('sites.create') }}">
                <x-ui.button>
                    <x-icons.plus class="h-4 w-4" />
                    {{ __('Add Site') }}
                </x-ui.button>
            </a>
        </div>
    </div>

    {{-- SPEC §4.5 — Panou: three bands, no charts, no decorative widgets --}}
    @php($panou = $this->panou)
    @php($attentionSites = collect($panou['attention'])->sum(fn ($g) => count($g['sites'])))
    <div class="mb-6">
        {{-- Summary — three numbers, in order --}}
        <div class="mb-4 flex flex-wrap gap-8 border-b border-gray-200 pb-3 dark:border-gray-700">
            <div>
                <p class="text-xl font-medium {{ $attentionSites > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100' }}">{{ $attentionSites }}</p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('need attention') }}</p>
            </div>
            <div>
                <p class="text-xl font-medium text-gray-900 dark:text-gray-100">{{ $panou['awaiting'] }}</p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('await approval') }}</p>
            </div>
            <div>
                <p class="text-xl font-medium text-green-600 dark:text-green-400">{{ $panou['resting'] }}</p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('healthy') }}</p>
            </div>
        </div>

        {{-- Band 1 — a card per client, ordered by severity --}}
        @forelse($panou['attention'] as $group)
            <div class="mb-2 rounded-lg border bg-white p-3 shadow-sm dark:bg-gray-800/40
                        {{ $group['severity'] === 0 ? 'border-red-300 dark:border-red-500/40' : 'border-gray-200 dark:border-gray-700' }}">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
                        {{ $group['client'] }}
                        <span class="text-[11px] font-normal text-gray-400">· {{ trans_choice(':n site|:n sites', count($group['sites']), ['n' => count($group['sites'])]) }}</span>
                    </p>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px]
                                 {{ $group['severity'] === 0 ? 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' }}">
                        {{ $group['severity'] === 0 ? __('critical') : __('attention') }}
                    </span>
                </div>
                @foreach($group['sites'] as $s)
                    <div class="flex items-center gap-2 py-0.5 text-[13px]">
                        <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $s['severity'] === 0 ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                        <a href="{{ $s['url'] }}" class="font-medium text-gray-800 hover:text-accent-600 dark:text-gray-100">{{ $s['name'] }}</a>
                        <span class="truncate text-gray-400">— {{ $s['reasons'] }}</span>
                    </div>
                @endforeach
            </div>
        @empty
            <div class="mb-2 rounded-lg border border-gray-200 p-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                {{ __('Nothing needs attention right now.') }}
            </div>
        @endforelse

        {{-- Band 2 — what awaits approval (only when there is something) --}}
        @if($panou['awaiting'] > 0)
            <div class="mb-2 flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm dark:border-gray-700 dark:bg-gray-800/40">
                <span class="text-gray-600 dark:text-gray-300">{{ __('Awaiting approval') }}</span>
                <a href="{{ route('updates.index') }}" class="font-medium text-accent-600 hover:underline dark:text-accent-400">
                    {{ trans_choice(':count update|:count updates', $panou['awaiting'], ['count' => $panou['awaiting']]) }}
                </a>
            </div>
        @endif

        {{-- Band 3 — the rest, one collapsed line --}}
        <div class="rounded-lg bg-gray-50 px-4 py-2.5 text-sm text-gray-500 dark:bg-gray-800/40 dark:text-gray-400">
            {{ trans_choice(':count site operating normally|:count sites operating normally', $panou['resting'], ['count' => $panou['resting']]) }}
        </div>
    </div>

    {{-- SPEC §4.4 — primary tabs: Toate · Actualizări · Alerte · Planuri --}}
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-gray-200 dark:border-gray-700">
        @php($tabs = ['all' => __('All'), 'updates' => __('Updates'), 'alerts' => __('Alerts'), 'plans' => __('Plans')])
        @foreach($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    class="-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition
                           {{ $tab === $key
                               ? 'border-accent-500 text-accent-600 dark:text-accent-400'
                               : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}">
                {{ $label }}
                @if($key === 'updates' && $this->tabCounts['updates'] > 0)
                    <span class="rounded-full bg-gray-100 px-1.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $this->tabCounts['updates'] }}</span>
                @elseif($key === 'alerts' && $this->tabCounts['alerts'] > 0)
                    <span class="rounded-full bg-red-100 px-1.5 text-xs text-red-700 dark:bg-red-500/15 dark:text-red-400">{{ $this->tabCounts['alerts'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Search & Filter Bar --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'healthy' => __('Healthy'), 'warning' => __('Warning'), 'critical' => __('Critical')]"
            :selected="$filter"
            wire="filter"
        />
        @if($this->availableTags->isNotEmpty())
            <select wire:model.live="tagId"
                class="rounded-lg border-gray-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                <option value="">{{ __('All tags') }}</option>
                @foreach($this->availableTags as $tag)
                    <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                @endforeach
            </select>
        @endif
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search sites...') }}"
            class="w-full sm:ml-auto sm:w-64"
        />

        {{-- SPEC §4.4 — grupare pe client --}}
        <button type="button" wire:click="toggleGroupByClient"
                aria-pressed="{{ $groupBy === 'client' ? 'true' : 'false' }}"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm transition
                       {{ $groupBy === 'client'
                           ? 'border-accent-500 bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400'
                           : 'border-gray-200 text-gray-500 hover:text-gray-700 dark:border-gray-700 dark:text-gray-400' }}">
            <x-icons.users class="h-4 w-4" />
            {{ $groupBy === 'client' ? __('Grouped: client') : __('Group: client') }}
        </button>

        {{-- Comutator vedere listă / grid --}}
        <div class="inline-flex shrink-0 rounded-lg border border-gray-200 p-0.5 dark:border-gray-700" role="group" aria-label="{{ __('View mode') }}">
            <button type="button" wire:click="setViewMode('list')"
                    aria-pressed="{{ $viewMode === 'list' ? 'true' : 'false' }}"
                    title="{{ __('List view') }}"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-md transition
                           {{ $viewMode === 'list'
                               ? 'bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400'
                               : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300' }}">
                <x-icons.menu class="h-4 w-4" />
            </button>
            <button type="button" wire:click="setViewMode('grid')"
                    aria-pressed="{{ $viewMode === 'grid' ? 'true' : 'false' }}"
                    title="{{ __('Grid view') }}"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-md transition
                           {{ $viewMode === 'grid'
                               ? 'bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400'
                               : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-300' }}">
                <x-icons.layout-dashboard class="h-4 w-4" />
            </button>
        </div>
    </div>

    {{-- Sites --}}
    @if($sites->count())
        @if($viewMode === 'list')
            {{-- Vedere LISTĂ densă (SPEC §4.1), grupată opțional pe client (§4.4) --}}
            <div class="overflow-hidden rounded-lg border border-gray-200 divide-y divide-gray-100 dark:divide-gray-800 dark:border-gray-700">
                @php($currentClient = null)
                @foreach($sites as $site)
                    @if($groupBy === 'client')
                        @php($clientName = $site->client?->name ?? __('No client'))
                        @if($clientName !== $currentClient)
                            @php($currentClient = $clientName)
                            <div class="bg-gray-50 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
                                {{ $currentClient }}
                            </div>
                        @endif
                    @endif
                    <x-site-row :site="$site" :key="$site->id" />
                @endforeach
            </div>

            {{-- Bară acțiuni în masă (SPEC §4.4) --}}
            <x-toolbar :count="count($selectedSites)" :plans="$this->availablePlans" />
        @else
            {{-- Vedere GRID (existentă) --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @foreach($sites as $site)
                    <livewire:components.site-card :site="$site" :key="$site->id" />
                @endforeach
            </div>
        @endif

        <div class="mt-6">
            {{ $sites->links() }}
        </div>
    @else
        <x-ui.empty-state
            title="{{ __('No sites found') }}"
            description="{{ __('Get started by adding your first WordPress site.') }}"
            icon="globe"
        >
            <x-slot:action>
                <a href="{{ route('sites.create') }}">
                    <x-ui.button>
                        <x-icons.plus class="h-4 w-4" />
                        {{ __('Add Site') }}
                    </x-ui.button>
                </a>
            </x-slot:action>
        </x-ui.empty-state>
    @endif
</div>
