<div @if($pluginPushRunning) wire:poll.2s="checkPushProgress" @endif>
    @include('livewire.settings.partials.settings-tabs')

    {{-- Connector Plugin --}}
    <x-ui.card>
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="text-base font-semibold text-gray-900">{{ __('Connector Plugin') }}</h3>
                <p class="text-sm text-gray-500">{{ __('Push the latest connector plugin version to connected WordPress sites.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                @if($pluginPushRunning)
                    <span class="text-sm text-gray-500">
                        {{ count($pluginPushResults) }}/{{ $pushTotal }} {{ __('sites processed') }}
                    </span>
                @else
                    <x-ui.button size="sm" variant="secondary"
                                 wire:click="openPushSiteSelector">
                        {{ __('Push to Selected...') }}
                    </x-ui.button>
                    <x-ui.button size="sm"
                                 wire:click="pushPluginToAllSites"
                                 wire:confirm="{{ __('Push the connector plugin to ALL connected sites?') }}">
                        {{ __('Push to All Sites') }}
                    </x-ui.button>
                @endif
            </div>
        </div>

        @if($pluginPushRunning)
            <div class="w-full bg-gray-200 rounded-full h-1.5 mb-3">
                <div class="bg-purple-600 h-1.5 rounded-full transition-all duration-300"
                     style="width: {{ $pushTotal > 0 ? round(count($pluginPushResults) / $pushTotal * 100) : 0 }}%"></div>
            </div>
        @endif

        @if(!empty($pluginPushResults))
            <div class="divide-y divide-gray-100 mt-2">
                @foreach($pluginPushResults as $result)
                    <div class="flex items-center justify-between py-2">
                        <span class="text-sm text-gray-700">{{ $result['site'] }}</span>
                        <span class="text-xs {{ $result['status'] === 'success' ? 'text-green-600' : 'text-red-600' }}">
                            {{ $result['message'] }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Changelog --}}
        <div class="mt-6 border-t border-gray-100 pt-4">
            <h4 class="text-sm font-medium text-gray-700 mb-3">{{ __('Changelog') }}</h4>
            <div class="space-y-3 max-h-64 overflow-y-auto">
                @foreach(config('connector.changelog') as $version => $entry)
                    @if($version === 'unreleased')
                        @if(!empty($entry['changes']))
                            <div class="opacity-60">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-gray-500 italic">Unreleased</span>
                                </div>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach($entry['changes'] as $change)
                                        <li class="text-xs text-gray-400 pl-3 relative before:content-[''] before:absolute before:left-0 before:top-[7px] before:h-1 before:w-1 before:rounded-full before:bg-gray-200">{{ $change }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @continue
                    @endif
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-gray-900">v{{ $version }}</span>
                            <span class="text-xs text-gray-400">{{ $entry['date'] }}</span>
                        </div>
                        <ul class="mt-1 space-y-0.5">
                            @foreach($entry['changes'] as $change)
                                <li class="text-xs text-gray-500 pl-3 relative before:content-[''] before:absolute before:left-0 before:top-[7px] before:h-1 before:w-1 before:rounded-full before:bg-gray-300">{{ $change }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </x-ui.card>

    {{-- Push Site Selector Modal --}}
    <x-ui.modal name="push-site-selector" maxWidth="md">
        <h2 class="text-lg font-semibold text-gray-900">{{ __('Select Sites to Update') }}</h2>
        <p class="text-sm text-gray-500 mt-1">{{ __('Choose which connected sites should receive the plugin update.') }}</p>

        <div class="mt-4">
            <input type="text"
                   wire:model.live.debounce.300ms="pushSiteSearch"
                   placeholder="{{ __('Search sites...') }}"
                   class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500" />
        </div>

        <div class="mt-3 flex items-center justify-between">
            <label class="flex items-center gap-2 text-xs text-gray-500">
                <input type="checkbox" wire:model.live="pushSelectAll"
                       class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                {{ __('Select All') }}
            </label>
            @if(count($selectedPushSiteIds) > 0)
                <span class="text-xs text-gray-500">{{ count($selectedPushSiteIds) }} {{ __('selected') }}</span>
            @endif
        </div>

        <div class="mt-3 max-h-72 overflow-y-auto rounded-lg border border-gray-200">
            @forelse($this->connectedSites as $site)
                <label class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                    <input type="checkbox"
                           wire:model.live="selectedPushSiteIds"
                           value="{{ $site->id }}"
                           class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <x-site-favicon :site="$site" size="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ $site->name }}</p>
                        <p class="text-xs text-gray-400 truncate">{{ $site->domain }}</p>
                    </div>
                </label>
            @empty
                <p class="px-3 py-4 text-sm text-gray-400 text-center">{{ __('No connected sites found.') }}</p>
            @endforelse
        </div>

        <div class="mt-4 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-push-site-selector')">
                {{ __('Cancel') }}
            </x-ui.button>
            <x-ui.button type="button"
                         wire:click="pushPluginToSelectedSites"
                         wire:loading.attr="disabled"
                         wire:target="pushPluginToSelectedSites"
                         :disabled="empty($selectedPushSiteIds)">
                <span wire:loading.remove wire:target="pushPluginToSelectedSites">
                    {{ __('Push to') }} {{ count($selectedPushSiteIds) }} {{ __('Site(s)') }}
                </span>
                <span wire:loading wire:target="pushPluginToSelectedSites">{{ __('Pushing...') }}</span>
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
