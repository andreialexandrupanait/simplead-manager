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

    {{-- The fleet at a glance. What used to sit here was the attention band —
         a wall of red before you could see your sites. That lives on /alerts
         now; the count below is the way in. --}}
    <x-dashboard.fleet-stats />

    @php($attention = $this->attentionCount)
    @if($attention > 0)
        <a href="{{ route('alerts.index') }}" wire:navigate
           class="mb-6 mt-4 flex items-center justify-between rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm transition hover:bg-red-100 dark:border-red-500/40 dark:bg-red-500/10">
            <span class="font-medium text-red-700 dark:text-red-400">
                {{ trans_choice(':count site needs attention|:count sites need attention', $attention, ['count' => $attention]) }}
            </span>
            <span class="text-red-600 dark:text-red-400">{{ __('View alerts') }} →</span>
        </a>
    @else
        <div class="mb-6 mt-4"></div>
    @endif

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
            {{-- bg-white: the rows are transparent, so without a background here the
                 page's #FAFAFA showed through the whole list. The grid view already
                 reads white because its cards use x-ui.card. --}}
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white divide-y divide-gray-100 dark:divide-gray-800 dark:border-gray-700 dark:bg-gray-800">
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
                    {{-- The rich fleet row: real hovercards per signal, a health
                         bar and the full ⋮ menu. The dense row it replaces
                         hard-coded three of its eight icons to "not applicable". --}}
                    <x-dashboard.site-row
                        :site="$site"
                        :selected-sites="$selectedSites"
                        :site-statuses="$this->siteStatuses"
                        :key="$site->id"
                    />
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

    {{-- Rename modal — the rich row's ⋮ menu opens this. --}}
    <x-ui.modal name="rename-site" maxWidth="sm">
        <form wire:submit="renameSite">
            <h2 id="modal-rename-site-title" class="text-lg font-semibold text-gray-900">{{ __('Rename site') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('Choose a new name for this site.') }}</p>

            <div class="mt-4">
                <label for="renamingSiteName" class="block text-sm font-medium text-gray-700">{{ __('Name') }}</label>
                <x-ui.input wire:model="renamingSiteName" id="renamingSiteName" class="mt-1" />
                @error('renamingSiteName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-rename-site')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete modal — the same ⋮ menu offers Delete, and until now it called a
         method this page did not have. --}}
    <x-ui.modal name="delete-site" maxWidth="sm">
        <div>
            <h2 id="modal-delete-site-title" class="text-lg font-semibold text-gray-900">{{ __('Delete site') }}</h2>
            <p class="mt-2 text-sm text-gray-600">
                {{ __('Are you sure you want to delete') }}
                <span class="font-medium text-gray-900">{{ $deletingSiteName }}</span>?
                {{ __('This action cannot be undone.') }}
            </p>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-delete-site')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="button" variant="danger" wire:click="deleteSite">
                    {{ __('Delete site') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
