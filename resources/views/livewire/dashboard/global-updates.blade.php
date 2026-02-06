<div>
    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Updates</h1>
        <p class="mt-1 text-sm text-gray-500">Manage WordPress core, plugin, and theme updates across all sites</p>
    </div>

    {{-- Counts --}}
    <div class="mb-6 grid grid-cols-3 gap-4">
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Core Updates</div>
            <div class="mt-1 text-2xl font-bold {{ $this->counts['core'] > 0 ? 'text-yellow-600' : 'text-gray-900' }}">{{ $this->counts['core'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Plugin Updates</div>
            <div class="mt-1 text-2xl font-bold {{ $this->counts['plugins'] > 0 ? 'text-yellow-600' : 'text-gray-900' }}">{{ $this->counts['plugins'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-sm font-medium text-gray-500">Theme Updates</div>
            <div class="mt-1 text-2xl font-bold {{ $this->counts['themes'] > 0 ? 'text-yellow-600' : 'text-gray-900' }}">{{ $this->counts['themes'] }}</div>
        </x-ui.card>
    </div>

    {{-- Filter bar --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="flex rounded-lg bg-gray-100 p-1">
            @foreach(['all' => 'All', 'core' => 'Core', 'plugins' => 'Plugins', 'themes' => 'Themes'] as $value => $label)
                <button
                    wire:click="$set('typeFilter', '{{ $value }}')"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ $this->typeFilter === $value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Search updates..."
            class="ml-auto w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500"
        >
    </div>

    {{-- Sites with updates --}}
    @if($this->sites->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                title="No pending updates"
                description="All sites are up to date."
                icon="refresh-cw"
            />
        </x-ui.card>
    @else
        <div class="space-y-4">
            @foreach($this->sites as $site)
                <x-ui.card>
                    <div class="mb-3 flex items-center justify-between">
                        <a href="{{ route('sites.updates', $site) }}" class="flex items-center gap-2 hover:text-purple-600">
                            <img src="https://www.google.com/s2/favicons?domain={{ $site->domain }}&sz=32"
                                 alt="" class="h-6 w-6 rounded ring-1 ring-gray-200">
                            <h3 class="text-sm font-semibold text-gray-900">{{ $site->name }}</h3>
                        </a>
                        <a href="{{ route('sites.updates', $site) }}" class="text-xs font-medium text-purple-600 hover:text-purple-800">
                            Manage updates
                        </a>
                    </div>

                    <div class="space-y-2">
                        {{-- Core update --}}
                        @if($site->core_update_version && ($this->typeFilter === 'all' || $this->typeFilter === 'core'))
                            <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <x-ui.badge variant="purple">Core</x-ui.badge>
                                    <span class="text-sm text-gray-800">WordPress Core</span>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $site->wp_version }} &rarr; {{ $site->core_update_version }}
                                </div>
                            </div>
                        @endif

                        {{-- Plugin updates --}}
                        @if($this->typeFilter === 'all' || $this->typeFilter === 'plugins')
                            @foreach($site->sitePlugins as $plugin)
                                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <x-ui.badge variant="yellow">Plugin</x-ui.badge>
                                        <span class="text-sm text-gray-800">{{ $plugin->name }}</span>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ $plugin->version }} &rarr; {{ $plugin->update_version }}
                                    </div>
                                </div>
                            @endforeach
                        @endif

                        {{-- Theme updates --}}
                        @if($this->typeFilter === 'all' || $this->typeFilter === 'themes')
                            @foreach($site->siteThemes as $theme)
                                <div class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <x-ui.badge variant="green">Theme</x-ui.badge>
                                        <span class="text-sm text-gray-800">{{ $theme->name }}</span>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ $theme->version }} &rarr; {{ $theme->update_version }}
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $this->sites->links() }}
        </div>
    @endif
</div>
