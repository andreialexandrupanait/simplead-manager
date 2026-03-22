<div>
    @include('livewire.settings.partials.settings-tabs')

    <form wire:submit="save" class="space-y-6">
        {{-- Application Settings --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Application') }}</h3>
            <div class="space-y-4">
                <x-ui.form-group :label="__('Application Name')" for="appName" error="form.appName">
                    <x-ui.input wire:model="form.appName" id="appName" />
                </x-ui.form-group>

                {{-- Favicon Upload --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Favicon') }}</label>
                    <div class="flex items-center gap-4">
                        <div class="h-12 w-12 rounded-lg {{ ($form->favicon || $faviconPath) ? 'bg-white' : 'bg-purple-500' }} flex items-center justify-center text-white text-sm font-bold overflow-hidden shrink-0">
                            @if($form->favicon)
                                <img src="{{ $form->favicon->temporaryUrl() }}" alt="Preview" class="h-full w-full object-contain">
                            @elseif($faviconPath)
                                <img src="{{ Storage::url($faviconPath) }}" alt="Favicon" class="h-full w-full object-contain">
                            @else
                                SA
                            @endif
                        </div>
                        <div>
                            <input type="file" wire:model="form.favicon" accept="image/*,.svg" class="text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-purple-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-purple-700 hover:file:bg-purple-100">
                            @error('form.favicon') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-gray-400">{{ __('Square image or SVG. Used in browser tab and sidebar icon.') }}</p>
                            @if($faviconPath)
                                <button type="button" wire:click="removeFavicon" class="mt-1 text-xs text-red-600 hover:text-red-700">{{ __('Remove favicon') }}</button>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Logo Upload --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Logo') }}</label>
                    <div class="flex items-center gap-4">
                        <div class="h-10 max-w-[10rem] flex items-center overflow-hidden shrink-0">
                            @if($form->logo)
                                <img src="{{ $form->logo->temporaryUrl() }}" alt="Preview" class="h-full object-contain">
                            @elseif($logoPath)
                                <img src="{{ Storage::url($logoPath) }}" alt="Logo" class="h-full object-contain">
                            @else
                                <span class="text-sm text-gray-400 italic">{{ __('No logo') }}</span>
                            @endif
                        </div>
                        <div>
                            <input type="file" wire:model="form.logo" accept="image/*,.svg" class="text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-purple-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-purple-700 hover:file:bg-purple-100">
                            @error('form.logo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-gray-400">{{ __('Image or SVG. Replaces the application name in the sidebar.') }}</p>
                            @if($logoPath)
                                <button type="button" wire:click="removeLogo" class="mt-1 text-xs text-red-600 hover:text-red-700">{{ __('Remove logo') }}</button>
                            @endif
                        </div>
                    </div>
                </div>

                <x-ui.form-group :label="__('Application URL')" for="appUrl" error="form.appUrl">
                    <x-ui.input wire:model="form.appUrl" id="appUrl" type="url" placeholder="https://your-app.com" />
                </x-ui.form-group>

                <div class="grid grid-cols-2 gap-4">
                    <x-ui.form-group :label="__('Default Timezone')" for="defaultTimezone">
                        <x-ui.select wire:model="form.defaultTimezone" id="defaultTimezone">
                            @foreach(timezone_identifiers_list() as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.form-group>
                    <x-ui.form-group :label="__('Date Format')" for="dateFormat">
                        <x-ui.select wire:model="form.dateFormat" id="dateFormat">
                            <option value="M d, Y">{{ now()->format('M d, Y') }} (M d, Y)</option>
                            <option value="d/m/Y">{{ now()->format('d/m/Y') }} (d/m/Y)</option>
                            <option value="d.m.Y">{{ now()->format('d.m.Y') }} (d.m.Y)</option>
                            <option value="m/d/Y">{{ now()->format('m/d/Y') }} (m/d/Y)</option>
                            <option value="Y-m-d">{{ now()->format('Y-m-d') }} (Y-m-d)</option>
                        </x-ui.select>
                    </x-ui.form-group>
                </div>
            </div>
        </x-ui.card>

        {{-- Monitoring Defaults --}}
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Monitoring Defaults') }}</h3>
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <x-ui.form-group :label="__('Default Check Interval')" for="defaultInterval">
                        <x-ui.select wire:model="form.defaultInterval" id="defaultInterval">
                            <option value="60">1 {{ __('minute') }}</option>
                            <option value="120">2 {{ __('minutes') }}</option>
                            <option value="300">5 {{ __('minutes') }}</option>
                            <option value="600">10 {{ __('minutes') }}</option>
                            <option value="900">15 {{ __('minutes') }}</option>
                            <option value="1800">30 {{ __('minutes') }}</option>
                            <option value="3600">1 {{ __('hour') }}</option>
                        </x-ui.select>
                    </x-ui.form-group>
                    <x-ui.form-group :label="__('Default Timeout (seconds)')" for="defaultTimeout">
                        <x-ui.input wire:model="form.defaultTimeout" id="defaultTimeout" type="number" min="5" max="120" />
                    </x-ui.form-group>
                </div>

                <x-ui.form-group :label="__('Alert After Failures')" for="alertAfterFailures" :hint="__('Consecutive failures before alerting')">
                    <x-ui.input wire:model="form.alertAfterFailures" id="alertAfterFailures" type="number" min="1" max="10" />
                </x-ui.form-group>
            </div>
        </x-ui.card>

        <div class="flex justify-end">
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ __('Save Settings') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
            </x-ui.button>
        </div>
    </form>

    {{-- Site Statuses --}}
    <div class="mt-8">
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Site Statuses') }}</h3>
                    <p class="text-sm text-gray-500">{{ __('Group sites by custom statuses like "Maintenance" or "Miscellaneous".') }}</p>
                </div>
                <x-ui.button size="sm" wire:click="openStatusForm">
                    {{ __('Add Status') }}
                </x-ui.button>
            </div>

            @if($this->siteStatuses->isEmpty())
                <p class="py-4 text-center text-sm text-gray-400">{{ __('No site statuses configured.') }}</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach($this->siteStatuses as $status)
                        <div class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-3">
                                <div class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ $status->color }}"></div>
                                <div>
                                    <span class="text-sm font-medium text-gray-900">{{ $status->name }}</span>
                                    <span class="ml-2 text-xs text-gray-500">{{ $status->sites_count }} {{ Str::plural('site', $status->sites_count) }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button wire:click="openStatusForm({{ $status->id }})" class="rounded-lg px-2 py-1 text-xs text-gray-500 hover:bg-gray-100">
                                    {{ __('Edit') }}
                                </button>
                                <button wire:click="deleteStatus({{ $status->id }})"
                                        wire:confirm="{{ __('Delete this status?') }}"
                                        class="rounded-lg px-2 py-1 text-xs text-red-500 hover:bg-red-50">
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Status Form Modal --}}
    <x-ui.modal name="status-form" maxWidth="sm">
        <form wire:submit="saveStatus">
            <h2 class="text-lg font-semibold text-gray-900">{{ $editingStatusId ? __('Edit Status') : __('Add Status') }}</h2>

            <div class="mt-4 space-y-4">
                <x-ui.form-group :label="__('Name')" for="statusName" error="statusForm.statusName">
                    <x-ui.input wire:model="statusForm.statusName" id="statusName" placeholder="e.g. Maintenance" />
                </x-ui.form-group>

                <x-ui.form-group :label="__('Color')" error="statusForm.statusColor">
                    <div class="flex items-center gap-3">
                        <input type="color" wire:model.live="statusForm.statusColor" class="h-9 w-9 cursor-pointer rounded border border-gray-300 p-0.5">
                        <x-ui.input wire:model.live="statusForm.statusColor" class="flex-1" maxlength="7" placeholder="#6b7280" />
                    </div>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Sort Order')" for="statusSortOrder" error="statusForm.statusSortOrder">
                    <x-ui.input wire:model="statusForm.statusSortOrder" id="statusSortOrder" type="number" min="0" />
                </x-ui.form-group>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-status-form')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveStatus">{{ $editingStatusId ? __('Save Changes') : __('Add Status') }}</span>
                    <span wire:loading wire:target="saveStatus">{{ __('Saving...') }}</span>
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Connector Plugin --}}
    <div class="mt-8">
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Connector Plugin') }}</h3>
                    <p class="text-sm text-gray-500">{{ __('Push the latest connector plugin version to connected WordPress sites.') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <x-ui.button size="sm" variant="secondary"
                                 wire:click="openPushSiteSelector"
                                 wire:loading.attr="disabled"
                                 wire:target="pushPluginToAllSites, pushPluginToSelectedSites">
                        {{ __('Push to Selected...') }}
                    </x-ui.button>
                    <x-ui.button size="sm"
                                 wire:click="pushPluginToAllSites"
                                 wire:loading.attr="disabled"
                                 wire:target="pushPluginToAllSites, pushPluginToSelectedSites"
                                 wire:confirm="{{ __('Push the connector plugin to ALL connected sites?') }}">
                        <span wire:loading.remove wire:target="pushPluginToAllSites, pushPluginToSelectedSites">{{ __('Push to All Sites') }}</span>
                        <span wire:loading wire:target="pushPluginToAllSites, pushPluginToSelectedSites">{{ __('Pushing...') }}</span>
                    </x-ui.button>
                </div>
            </div>

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
                    @foreach(\App\Livewire\Settings\GeneralSettings::CONNECTOR_CHANGELOG as $version => $entry)
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
    </div>

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

    {{-- Danger Zone --}}
    <div class="mt-8">
        <x-ui.card class="border-red-200 ring-red-100">
            <h3 class="text-base font-semibold text-red-600 mb-2">{{ __('Danger Zone') }}</h3>
            <p class="text-sm text-gray-500 mb-4">{{ __('Permanently delete all monitoring check and incident data. This action cannot be undone.') }}</p>
            <x-ui.button variant="danger" wire:click="purgeMonitoringData" wire:confirm="{{ __('Are you sure? This will delete ALL monitoring data and cannot be undone.') }}" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="purgeMonitoringData">{{ __('Purge Monitoring Data') }}</span>
                <span wire:loading wire:target="purgeMonitoringData">{{ __('Purging...') }}</span>
            </x-ui.button>
        </x-ui.card>
    </div>
</div>
