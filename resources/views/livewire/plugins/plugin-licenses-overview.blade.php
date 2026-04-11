<div>
    <div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <x-ui.page-header title="{{ __('Plugin Licenses') }}" subtitle="{{ __('Manage licenses across all sites') }}" />
        <x-ui.button wire:click="scanLicenses" wire:loading.attr="disabled" wire:confirm="{{ __('This will update the connector plugin on all sites and scan for licenses. Continue?') }}">
            <span wire:loading.remove wire:target="scanLicenses">{{ __('Scan Licenses') }}</span>
            <span wire:loading wire:target="scanLicenses">{{ __('Scanning...') }}</span>
        </x-ui.button>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $this->stats['total'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Total Licensed') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-green-600">{{ $this->stats['active'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Active') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ $this->stats['expiring'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Expiring Soon') }}</p>
            </div>
        </x-ui.card>
        <x-ui.card>
            <div class="text-center">
                <p class="text-2xl font-bold text-red-600">{{ $this->stats['expired'] }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('Expired') }}</p>
            </div>
        </x-ui.card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <x-ui.filter-tabs
            :options="['all' => __('All'), 'active' => __('Active'), 'expiring' => __('Expiring'), 'expired' => __('Expired')]"
            :selected="$filter"
            wire="filter"
        />
        <x-ui.search-input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search plugins or sites...') }}"
            class="w-full sm:ml-auto sm:w-64"
        />
    </div>

    {{-- Table --}}
    <x-ui.card class="!p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Plugin') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Site') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Expires') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Version') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($licenses as $plugin)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $plugin->name }}</div>
                            <div class="text-xs text-gray-500">{{ $plugin->slug }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if($plugin->site)
                                <a href="{{ route('sites.plugins', $plugin->site) }}" class="text-sm text-purple-600 hover:underline" wire:navigate>{{ $plugin->site->name }}</a>
                            @else
                                <span class="text-sm text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($plugin->isLicenseExpired())
                                <x-ui.badge variant="red">{{ __('Expired') }}</x-ui.badge>
                            @elseif($plugin->isLicenseExpiring())
                                <x-ui.badge variant="yellow">{{ __('Expiring') }}</x-ui.badge>
                            @elseif($plugin->license_status === 'active')
                                <x-ui.badge variant="green">{{ __('Active') }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="gray">{{ ucfirst($plugin->license_status ?? 'unknown') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($plugin->license_expires_at)
                                <span class="text-sm {{ $plugin->isLicenseExpired() ? 'text-red-600 font-medium' : ($plugin->isLicenseExpiring() ? 'text-yellow-600' : 'text-gray-700 dark:text-gray-300') }}">
                                    {{ $plugin->license_expires_at->format('M j, Y') }}
                                </span>
                                <div class="text-xs text-gray-400">{{ $plugin->license_expires_at->diffForHumans() }}</div>
                            @else
                                <span class="text-sm text-gray-400">{{ __('No expiry') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-sm text-gray-700 dark:text-gray-300">v{{ $plugin->version }}</span>
                            @if($plugin->has_update)
                                <span class="text-xs text-yellow-600 ml-1">&rarr; v{{ $plugin->update_version }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-gray-500">
                            {{ __('No licensed plugins found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.card>

    @if($licenses->hasPages())
        <div class="mt-4">{{ $licenses->links() }}</div>
    @endif
</div>
