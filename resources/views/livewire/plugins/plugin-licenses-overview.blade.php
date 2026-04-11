<div>
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="{{ __('Plugin Licenses') }}" subtitle="{{ __('Premium plugins and license tracking per site') }}" />
        <x-ui.button wire:click="scanLicenses" wire:loading.attr="disabled" wire:confirm="{{ __('This will update the connector on all sites and re-scan plugins. Continue?') }}">
            <span wire:loading.remove wire:target="scanLicenses">{{ __('Scan All Sites') }}</span>
            <span wire:loading wire:target="scanLicenses">{{ __('Scanning...') }}</span>
        </x-ui.button>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $this->stats['premium_plugins'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Premium Plugins') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $this->stats['with_license'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('With License') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold {{ $this->stats['no_license'] > 0 ? 'text-yellow-600' : 'text-green-600' }}">{{ $this->stats['no_license'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('No License') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $this->stats['active'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Active') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ $this->stats['expiring'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Expiring') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">{{ $this->stats['expired'] }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ __('Expired') }}</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => __('All Premium'), 'licensed' => __('With License'), 'no_license' => __('No License'), 'expiring' => __('Expiring'), 'expired' => __('Expired')]"
            :selected="$filter"
            wire="filter"
        />
        <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search plugins or sites...') }}" class="w-full sm:ml-auto sm:w-64" />
    </div>

    {{-- Per-site groups --}}
    @forelse($this->sites as $group)
        <x-ui.card class="!p-0 overflow-hidden mb-4">
            {{-- Site header --}}
            <div class="flex items-center justify-between bg-gray-50/50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-700 px-4 py-2.5">
                <div class="flex items-center gap-2">
                    @if($group['site_id'])
                        <a href="{{ route('sites.plugins', $group['site_id']) }}" class="text-sm font-semibold text-gray-900 dark:text-white hover:text-purple-600 transition" wire:navigate>
                            {{ $group['site_name'] }}
                        </a>
                    @else
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group['site_name'] }}</span>
                    @endif
                    <x-ui.badge variant="purple">{{ $group['total_count'] }} {{ __('premium') }}</x-ui.badge>
                    @if($group['licensed_count'] > 0)
                        <x-ui.badge variant="green">{{ $group['licensed_count'] }} {{ __('licensed') }}</x-ui.badge>
                    @endif
                    @if($group['total_count'] - $group['licensed_count'] > 0)
                        <x-ui.badge variant="yellow">{{ $group['total_count'] - $group['licensed_count'] }} {{ __('unlicensed') }}</x-ui.badge>
                    @endif
                </div>
            </div>

            {{-- Plugins --}}
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($group['plugins'] as $plugin)
                    <div class="flex items-center justify-between px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $plugin->name }}</span>
                                @if($plugin->is_active)
                                    <span class="h-1.5 w-1.5 rounded-full bg-green-400" title="{{ __('Active') }}"></span>
                                @endif
                                <span class="text-xs text-gray-400">v{{ $plugin->version }}</span>
                            </div>
                            <div class="text-xs text-gray-400">
                                {{ $plugin->author ?? '' }}
                                @if($plugin->plugin_uri)
                                    &mdash; <a href="{{ $plugin->plugin_uri }}" target="_blank" class="text-purple-500 hover:underline">{{ __('Website') }}</a>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-3 shrink-0 ml-3">
                            @if($plugin->license_key)
                                <div class="text-right">
                                    <div class="flex items-center gap-1.5">
                                        @if($plugin->isLicenseExpired())
                                            <x-ui.badge variant="red">{{ __('Expired') }}</x-ui.badge>
                                        @elseif($plugin->isLicenseExpiring())
                                            <x-ui.badge variant="yellow">{{ __('Expiring') }}</x-ui.badge>
                                        @elseif($plugin->license_status === 'active')
                                            <x-ui.badge variant="green">{{ __('Active') }}</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="gray">{{ ucfirst($plugin->license_status ?? 'unknown') }}</x-ui.badge>
                                        @endif
                                        <span class="text-[10px] font-mono text-gray-400" title="{{ __('License key (masked)') }}">{{ Str::limit($plugin->license_key, 16) }}</span>
                                    </div>
                                    @if($plugin->license_expires_at)
                                        <div class="text-[10px] {{ $plugin->isLicenseExpired() ? 'text-red-500' : ($plugin->isLicenseExpiring() ? 'text-yellow-500' : 'text-gray-400') }}">
                                            {{ $plugin->license_expires_at->format('M j, Y') }}
                                        </div>
                                    @endif
                                </div>
                            @else
                                <span class="text-xs text-gray-300 dark:text-gray-600">{{ __('No license detected') }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @empty
        <x-ui.card>
            <div class="py-12 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if($search)
                        {{ __('No premium plugins found matching your search.') }}
                    @else
                        {{ __('No premium plugins detected. Click "Scan All Sites" to update connector and detect premium plugins.') }}
                    @endif
                </p>
            </div>
        </x-ui.card>
    @endforelse
</div>
