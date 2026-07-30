@php
    $pushProgress = $pushTotal > 0
        ? (int) round(count($pluginPushResults) / $pushTotal * 100)
        : 0;
@endphp

<div @if($pluginPushRunning) wire:poll.2s="checkPushProgress" @endif>
    <x-settings.section id="connector"
                        :title="__('WordPress Connector')"
                        :subtitle="__('Push the latest connector plugin version to connected WordPress sites.')">
        <x-slot:actions>
            @if($pluginPushRunning)
                <x-ui.badge variant="blue">
                    {{ __(':done of :total sites processed', ['done' => count($pluginPushResults), 'total' => $pushTotal]) }}
                </x-ui.badge>
            @else
                <x-ui.button type="button" size="sm" variant="secondary"
                             wire:click="openPushSiteSelector"
                             wire:loading.attr="disabled"
                             wire:target="openPushSiteSelector">
                    {{ __('Push to Selected…') }}
                </x-ui.button>

                <x-ui.button type="button" size="sm"
                             wire:click="pushPluginToAllSites"
                             wire:confirm="{{ __('Push the connector plugin to ALL connected sites? One background job is queued per site.') }}"
                             wire:loading.attr="disabled"
                             wire:target="pushPluginToAllSites">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="pushPluginToAllSites" />
                    {{ __('Push to All Sites') }}
                </x-ui.button>
            @endif
        </x-slot:actions>

        @if($pluginPushRunning)
            <div class="mb-4">
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200"
                     role="progressbar"
                     aria-valuenow="{{ $pushProgress }}"
                     aria-valuemin="0"
                     aria-valuemax="100"
                     aria-label="{{ __('Plugin push progress') }}">
                    <div class="h-1.5 rounded-full bg-accent-600 transition-all duration-300"
                         style="width: {{ $pushProgress }}%"></div>
                </div>
            </div>
        @endif

        @if(! empty($pluginPushResults))
            <div class="space-y-2">
                @foreach($pluginPushResults as $result)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 p-3">
                        <p class="min-w-0 truncate text-sm font-medium text-gray-900">{{ $result['site'] }}</p>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-xs {{ $result['status'] === 'success' ? 'text-green-600' : 'text-red-600' }}">
                                {{ $result['message'] }}
                            </span>
                            <x-ui.badge :variant="$result['status'] === 'success' ? 'green' : 'red'">
                                {{ $result['status'] === 'success' ? __('Updated') : __('Failed') }}
                            </x-ui.badge>
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif(! $pluginPushRunning)
            <p class="text-sm text-gray-500">
                {{ __('A push installs the bundled connector build on the sites you choose, one background job per site. Per-site results appear here while it runs.') }}
            </p>
        @endif

        {{-- Changelog --}}
        <div class="mt-6 border-t border-gray-200 pt-4">
            <h3 class="mb-3 text-sm font-medium text-gray-900">{{ __('Changelog') }}</h3>

            <div class="max-h-64 space-y-3 overflow-y-auto pr-1">
                @foreach(config('connector.changelog', []) as $version => $entry)
                    @if($version === 'unreleased')
                        @if(! empty($entry['changes']))
                            <div class="opacity-60">
                                <p class="text-xs font-semibold italic text-gray-500">{{ __('Unreleased') }}</p>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach($entry['changes'] as $change)
                                        <li class="relative pl-3 text-xs text-gray-500 before:absolute before:left-0 before:top-[7px] before:h-1 before:w-1 before:rounded-full before:bg-gray-300 before:content-['']">{{ $change }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @continue
                    @endif

                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-gray-900">v{{ $version }}</span>
                            <span class="text-xs text-gray-500">{{ $entry['date'] ?? '' }}</span>
                        </div>
                        <ul class="mt-1 space-y-0.5">
                            @foreach($entry['changes'] as $change)
                                <li class="relative pl-3 text-xs text-gray-500 before:absolute before:left-0 before:top-[7px] before:h-1 before:w-1 before:rounded-full before:bg-gray-300 before:content-['']">{{ $change }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </x-settings.section>

    {{-- Push site selector --}}
    <x-ui.modal name="push-site-selector" maxWidth="md">
        <h2 class="text-base font-semibold text-gray-900">{{ __('Select Sites to Update') }}</h2>
        <p class="mt-0.5 text-sm text-gray-500">{{ __('Choose which connected sites should receive the plugin update.') }}</p>

        <div class="mt-4">
            <x-ui.input type="search"
                        wire:model.live.debounce.300ms="pushSiteSearch"
                        id="pushSiteSearch"
                        :placeholder="__('Search sites…')"
                        aria-label="{{ __('Search sites') }}" />
        </div>

        <div class="mt-3 flex items-center justify-between">
            <x-ui.checkbox wire:model.live="pushSelectAll" id="pushSelectAll" :label="__('Select all')" />

            @if(count($selectedPushSiteIds) > 0)
                <x-ui.badge variant="blue">
                    {{ trans_choice('{1}:count selected|[2,*]:count selected', count($selectedPushSiteIds), ['count' => count($selectedPushSiteIds)]) }}
                </x-ui.badge>
            @endif
        </div>

        <div class="mt-3 max-h-72 overflow-y-auto rounded-lg border border-gray-200">
            @forelse($this->connectedSites as $site)
                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-gray-50 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                    <input type="checkbox"
                           wire:model.live="selectedPushSiteIds"
                           value="{{ $site->id }}"
                           class="rounded border-gray-300 text-accent-600 focus:ring-accent-500 dark:border-gray-600 dark:bg-gray-700">
                    <x-site-favicon :site="$site" size="sm" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900">{{ $site->name }}</p>
                        <p class="truncate text-xs text-gray-500">{{ $site->domain }}</p>
                    </div>
                </label>
            @empty
                <x-ui.empty-state icon="search"
                                  :title="__('No connected sites found')"
                                  :description="__('Only sites with a working connector can receive a plugin push.')" />
            @endforelse
        </div>

        <div class="mt-4 flex items-center justify-end gap-3">
            <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-push-site-selector')">
                {{ __('Cancel') }}
            </x-ui.button>

            <x-ui.button type="button"
                         wire:click="pushPluginToSelectedSites"
                         wire:confirm="{{ __('Queue a plugin push for the selected sites? One background job is queued per site.') }}"
                         wire:loading.attr="disabled"
                         wire:target="pushPluginToSelectedSites"
                         :disabled="empty($selectedPushSiteIds)">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="pushPluginToSelectedSites" />
                {{ trans_choice('{0}Push|{1}Push to :count site|[2,*]Push to :count sites', count($selectedPushSiteIds), ['count' => count($selectedPushSiteIds)]) }}
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
