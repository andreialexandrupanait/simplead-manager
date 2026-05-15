<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="{{ __('Updates') }}" subtitle="{{ __('Available updates across all sites') }}" />
    </div>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Total Updates') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-accent-600">{{ $this->stats['plugins'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Plugin Updates') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-green-600">{{ $this->stats['themes'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Theme Updates') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-semibold text-yellow-600">{{ $this->stats['sites'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Sites with Updates') }}</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'plugins' => __('Plugins'), 'themes' => __('Themes')]"
            :selected="$filter"
            wire="filter"
        />

        <div class="flex items-center gap-2 ml-2">
            <span class="text-xs text-gray-500">{{ __('Group by') }}:</span>
            <button wire:click="$set('groupBy', 'site')"
                    class="rounded px-2 py-1 text-xs font-medium transition {{ $groupBy === 'site' ? 'bg-accent-100 text-accent-700 dark:bg-accent-900/30 dark:text-accent-300' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                {{ __('Site') }}
            </button>
            <button wire:click="$set('groupBy', 'item')"
                    class="rounded px-2 py-1 text-xs font-medium transition {{ $groupBy === 'item' ? 'bg-accent-100 text-accent-700 dark:bg-accent-900/30 dark:text-accent-300' : 'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                {{ __('Plugin/Theme') }}
            </button>
        </div>

        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search plugins, themes, or sites...') }}"
            class="w-full sm:ml-auto sm:w-72"
        />
    </div>

    {{-- Updates List --}}
    @if(count($this->updates) === 0)
        <x-ui.card>
            <div class="py-12 text-center">
                <svg class="mx-auto h-12 w-12 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="mt-3 text-sm font-medium text-gray-900 dark:text-white">{{ __('All up to date!') }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ __('No pending updates across your sites.') }}</p>
            </div>
        </x-ui.card>
    @else
        <div class="space-y-4">
            @foreach($this->updates as $group)
                <x-ui.card class="!p-0 overflow-hidden">
                    {{-- Group header --}}
                    <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 px-4 py-3">
                        <div class="flex items-center gap-2">
                            @if($groupBy === 'site')
                                <a href="{{ route('sites.plugins', $group['site_id']) }}" class="text-sm font-semibold text-gray-900 dark:text-white hover:text-accent-600 transition" wire:navigate>
                                    {{ $group['label'] }}
                                </a>
                                <x-ui.badge variant="purple">{{ count($group['items']) }} {{ __('update(s)') }}</x-ui.badge>
                            @else
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group['label'] }}</span>
                                <x-ui.badge :variant="$group['type'] === 'plugin' ? 'purple' : 'green'">{{ ucfirst($group['type']) }}</x-ui.badge>
                                @if(!empty($group['update_version']))
                                    <span class="text-xs text-gray-500">&rarr; v{{ $group['update_version'] }}</span>
                                @endif
                                <x-ui.badge variant="gray">{{ count($group['items']) }} {{ __('site(s)') }}</x-ui.badge>
                            @endif
                        </div>

                        @if($groupBy === 'site')
                            <button
                                wire:click="updateAllForSite({{ $group['site_id'] }})"
                                wire:loading.attr="disabled"
                                wire:confirm="{{ __('Update all plugins and themes on :site?', ['site' => $group['label']]) }}"
                                class="rounded-lg bg-accent-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-accent-700 disabled:opacity-50 transition"
                            >
                                <span wire:loading.remove wire:target="updateAllForSite({{ $group['site_id'] }})">{{ __('Update All') }}</span>
                                <span wire:loading wire:target="updateAllForSite({{ $group['site_id'] }})">{{ __('Updating...') }}</span>
                            </button>
                        @elseif($groupBy === 'item' && $group['type'] === 'plugin')
                            <button
                                wire:click="updatePluginAcrossSites('{{ $group['items'][0]['slug'] ?? '' }}')"
                                wire:loading.attr="disabled"
                                wire:confirm="{{ __('Update :plugin on all :count site(s)?', ['plugin' => $group['label'], 'count' => count($group['items'])]) }}"
                                class="rounded-lg bg-accent-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-accent-700 disabled:opacity-50 transition"
                            >
                                <span wire:loading.remove wire:target="updatePluginAcrossSites('{{ $group['items'][0]['slug'] ?? '' }}')">{{ __('Update All Sites') }}</span>
                                <span wire:loading wire:target="updatePluginAcrossSites('{{ $group['items'][0]['slug'] ?? '' }}')">{{ __('Updating...') }}</span>
                            </button>
                        @endif
                    </div>

                    {{-- Items --}}
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($group['items'] as $item)
                            @php $resultKey = $item['type'] . '_' . $item['id']; @endphp
                            <div class="flex items-center justify-between px-4 py-2.5 transition-colors hover:bg-gray-50 dark:hover:bg-gray-800"
                                 wire:loading.class="!bg-blue-50 dark:!bg-blue-900/20" wire:target="updateSingle('{{ $item['type'] }}', {{ $item['id'] }})">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['name'] }}</span>
                                        <x-ui.badge :variant="$item['type'] === 'plugin' ? 'purple' : 'green'" class="text-[10px]">{{ ucfirst($item['type']) }}</x-ui.badge>
                                        @if($item['is_active'])
                                            <span class="h-1.5 w-1.5 rounded-full bg-green-400" title="{{ __('Active') }}"></span>
                                        @endif
                                        @if($item['auto_update'])
                                            <span class="text-[10px] text-accent-500 font-medium">Auto</span>
                                        @endif
                                    </div>
                                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        @if($groupBy === 'item')
                                            {{ $item['site_name'] }} &mdash;
                                        @endif
                                        v{{ $item['version'] }} <span class="text-yellow-600">&rarr;</span> v{{ $item['update_version'] }}
                                    </div>

                                    {{-- Result message --}}
                                    @if(isset($updateResults[$resultKey]))
                                        @if($updateResults[$resultKey]['success'])
                                            <div class="mt-1 text-xs font-medium text-green-600">{{ $updateResults[$resultKey]['message'] }}</div>
                                        @else
                                            <div class="mt-1 flex items-center gap-1">
                                                <span class="text-xs font-medium text-red-600">{{ $updateResults[$resultKey]['message'] }}</span>
                                                <button wire:click="clearResult('{{ $resultKey }}')" class="text-red-400 hover:text-red-600">
                                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        @endif
                                    @endif
                                </div>

                                <div class="flex items-center gap-2 ml-3 shrink-0">
                                    <button
                                        wire:click="updateSingle('{{ $item['type'] }}', {{ $item['id'] }})"
                                        wire:loading.attr="disabled"
                                        wire:target="updateSingle('{{ $item['type'] }}', {{ $item['id'] }})"
                                        class="rounded-lg bg-accent-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-accent-700 disabled:opacity-50 transition"
                                    >
                                        <span wire:loading.remove wire:target="updateSingle('{{ $item['type'] }}', {{ $item['id'] }})">{{ __('Update') }}</span>
                                        <span wire:loading wire:target="updateSingle('{{ $item['type'] }}', {{ $item['id'] }})">...</span>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
