<div>
    {{-- Header with Add Button --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Sites</h1>
            <p class="mt-1 text-sm text-gray-500">Manage all your WordPress sites</p>
        </div>
        <a href="{{ route('sites.create') }}">
            <x-ui.button>
                <x-icons.plus class="h-4 w-4" />
                Add Site
            </x-ui.button>
        </a>
    </div>

    {{-- Search & Filter Bar --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <div class="flex rounded-lg bg-gray-100 p-1">
            @foreach(['all' => 'All', 'healthy' => 'Healthy', 'warning' => 'Warning', 'critical' => 'Critical'] as $value => $label)
                <button wire:click="$set('filter', '{{ $value }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $filter === $value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Search sites..."
            class="ml-auto w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
        >
    </div>

    {{-- Sites Grid --}}
    @if($sites->count())
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($sites as $site)
                <livewire:components.site-card :site="$site" :key="$site->id" />
            @endforeach
        </div>

        <div class="mt-6">
            {{ $sites->links() }}
        </div>
    @else
        <x-ui.empty-state
            title="No sites found"
            description="Get started by adding your first WordPress site."
            icon="globe"
        >
            <x-slot:action>
                <a href="{{ route('sites.create') }}">
                    <x-ui.button>
                        <x-icons.plus class="h-4 w-4" />
                        Add Site
                    </x-ui.button>
                </a>
            </x-slot:action>
        </x-ui.empty-state>
    @endif
</div>
