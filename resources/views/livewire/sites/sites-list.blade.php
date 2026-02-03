<div>
    <div class="mb-6 flex justify-end">
        <a href="{{ route('sites.create') }}">
            <x-ui.button>
                <x-icons.plus class="h-4 w-4" />
                Add Site
            </x-ui.button>
        </a>
    </div>

    {{-- Search & Filter Bar --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center">
        <div class="relative flex-1">
            <x-icons.search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <x-ui.input wire:model.live.debounce.300ms="search" type="text" placeholder="Search sites..." class="pl-10" />
        </div>
        <div class="flex gap-2">
            <button wire:click="$set('filter', 'all')"
                    class="rounded-lg px-3 py-2 text-sm font-medium transition {{ $filter === 'all' ? 'bg-purple-100 text-purple-700' : 'text-gray-600 hover:bg-gray-100' }}">
                All
            </button>
            <button wire:click="$set('filter', 'healthy')"
                    class="rounded-lg px-3 py-2 text-sm font-medium transition {{ $filter === 'healthy' ? 'bg-green-100 text-green-700' : 'text-gray-600 hover:bg-gray-100' }}">
                Healthy
            </button>
            <button wire:click="$set('filter', 'warning')"
                    class="rounded-lg px-3 py-2 text-sm font-medium transition {{ $filter === 'warning' ? 'bg-yellow-100 text-yellow-700' : 'text-gray-600 hover:bg-gray-100' }}">
                Warning
            </button>
            <button wire:click="$set('filter', 'critical')"
                    class="rounded-lg px-3 py-2 text-sm font-medium transition {{ $filter === 'critical' ? 'bg-red-100 text-red-700' : 'text-gray-600 hover:bg-gray-100' }}">
                Critical
            </button>
        </div>
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
