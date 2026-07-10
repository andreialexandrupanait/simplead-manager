<div>
    {{-- Header with Add Button --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="{{ __('Sites') }}" subtitle="{{ __('Manage all your WordPress sites') }}" />
        <a href="{{ route('sites.create') }}">
            <x-ui.button>
                <x-icons.plus class="h-4 w-4" />
                {{ __('Add Site') }}
            </x-ui.button>
        </a>
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
    </div>

    {{-- Sites Grid --}}
    @if($sites->count())
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach($sites as $site)
                <livewire:components.site-card :site="$site" :key="$site->id" />
            @endforeach
        </div>

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
