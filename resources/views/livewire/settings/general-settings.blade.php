<div>
    @include('livewire.settings.partials.settings-tabs')

    <form wire:submit="save" class="space-y-6">
        {{-- Branding --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent-50 shadow-sm ring-1 ring-accent-200">
                        <x-icons.image class="h-5 w-5 text-accent-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Branding') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Application name, URL, and branding assets') }}</p>
                    </div>
                </div>
                <x-ui.badge variant="purple">{{ $form->appName ?: 'SimpleAd' }}</x-ui.badge>
            </div>

            <div class="space-y-4">
                <x-ui.form-group :label="__('Application Name')" for="appName" error="form.appName">
                    <x-ui.input wire:model="form.appName" id="appName" />
                </x-ui.form-group>

                {{-- Favicon & Logo Upload (2 columns) --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- Favicon --}}
                    <div class="rounded-lg border border-gray-200 p-4">
                        <label class="block text-sm font-medium text-gray-700 mb-3">{{ __('Favicon') }}</label>
                        <div class="flex items-center gap-4">
                            <div class="h-12 w-12 rounded-lg {{ ($form->favicon || $faviconPath) ? 'bg-white border border-gray-200' : 'bg-accent-500' }} flex items-center justify-center text-white text-sm font-bold overflow-hidden shrink-0">
                                @if($form->favicon)
                                    <img src="{{ $form->favicon->temporaryUrl() }}" alt="Preview" class="h-full w-full object-contain">
                                @elseif($faviconPath)
                                    <img src="{{ Storage::url($faviconPath) }}" alt="Favicon" class="h-full w-full object-contain">
                                @else
                                    SA
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <input type="file" wire:model="form.favicon" accept="image/*,.svg" class="w-full text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-accent-50 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-accent-700 hover:file:bg-accent-100">
                                @error('form.favicon') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-gray-400">{{ __('Square image or SVG.') }}</p>
                                @if($faviconPath)
                                    <button type="button" wire:click="removeFavicon" class="mt-1 text-xs text-red-600 hover:text-red-700">{{ __('Remove') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Logo --}}
                    <div class="rounded-lg border border-gray-200 p-4">
                        <label class="block text-sm font-medium text-gray-700 mb-3">{{ __('Logo') }}</label>
                        <div class="flex items-center gap-4">
                            <div class="h-12 w-12 rounded-lg border border-gray-200 flex items-center justify-center overflow-hidden shrink-0">
                                @if($form->logo)
                                    <img src="{{ $form->logo->temporaryUrl() }}" alt="Preview" class="h-full w-full object-contain">
                                @elseif($logoPath)
                                    <img src="{{ Storage::url($logoPath) }}" alt="Logo" class="h-full w-full object-contain">
                                @else
                                    <span class="text-xs text-gray-400">&mdash;</span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <input type="file" wire:model="form.logo" accept="image/*,.svg" class="w-full text-sm text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-accent-50 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-accent-700 hover:file:bg-accent-100">
                                @error('form.logo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-gray-400">{{ __('Replaces app name in sidebar.') }}</p>
                                @if($logoPath)
                                    <button type="button" wire:click="removeLogo" class="mt-1 text-xs text-red-600 hover:text-red-700">{{ __('Remove') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Accent Color --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Accent Color') }}</label>
                    <div class="flex items-center gap-3">
                        <input type="color"
                               wire:model.live="form.accentColor"
                               id="accentColor"
                               class="h-10 w-14 cursor-pointer rounded-lg border border-gray-300 p-1"
                               value="{{ $form->accentColor ?? '#8D5CF5' }}">
                        <input type="text"
                               wire:model.live="form.accentColor"
                               class="w-28 rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono shadow-sm focus:border-accent-500 focus:ring-accent-500"
                               placeholder="#8D5CF5"
                               maxlength="7">
                        <div class="flex items-center gap-2">
                            <span class="inline-block h-8 w-8 rounded-lg shadow-sm" style="background-color: {{ $form->accentColor ?? '#8D5CF5' }}"></span>
                            @if($form->accentColor)
                                <button type="button" wire:click="$set('form.accentColor', null)" class="text-xs text-gray-500 hover:text-red-600">{{ __('Reset') }}</button>
                            @endif
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-gray-400">{{ __('Primary color used across the interface. Leave empty for default purple.') }}</p>
                    @error('form.accentColor') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <x-ui.form-group :label="__('Application URL')" for="appUrl" error="form.appUrl">
                    <x-ui.input wire:model="form.appUrl" id="appUrl" type="url" placeholder="https://your-app.com" />
                </x-ui.form-group>
            </div>
        </x-ui.card>

        {{-- Regional --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 shadow-sm ring-1 ring-blue-200">
                        <x-icons.globe class="h-5 w-5 text-blue-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Regional') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Timezone and date format preferences') }}</p>
                    </div>
                </div>
                <x-ui.badge variant="blue">{{ now()->setTimezone($form->defaultTimezone)->format($form->dateFormat) }}</x-ui.badge>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
        </x-ui.card>

        {{-- Monitoring Defaults --}}
        <x-ui.card>
            @php
                $intervalLabel = match((int) $form->defaultInterval) {
                    60 => '1 ' . __('minute'),
                    120 => '2 ' . __('minutes'),
                    300 => '5 ' . __('minutes'),
                    600 => '10 ' . __('minutes'),
                    900 => '15 ' . __('minutes'),
                    1800 => '30 ' . __('minutes'),
                    3600 => '1 ' . __('hour'),
                    default => $form->defaultInterval . 's',
                };
            @endphp
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-green-50 shadow-sm ring-1 ring-green-200">
                        <x-icons.activity class="h-5 w-5 text-green-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Monitoring Defaults') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Default check intervals and alert thresholds for new sites') }}</p>
                    </div>
                </div>
                <x-ui.badge variant="green">{{ $intervalLabel }}</x-ui.badge>
            </div>

            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.form-group :label="__('Dashboard Sites Per Page')" for="dashboardPerPage" :hint="__('Number of sites shown on the dashboard (10-200)')">
                        <x-ui.input wire:model="form.dashboardPerPage" id="dashboardPerPage" type="number" min="10" max="200" />
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Sites List Per Page')" for="sitesPerPage" :hint="__('Number of sites shown on the Sites page (10-200)')">
                        <x-ui.input wire:model="form.sitesPerPage" id="sitesPerPage" type="number" min="10" max="200" />
                    </x-ui.form-group>
                </div>
            </div>
        </x-ui.card>

        {{-- Security --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-yellow-50 shadow-sm ring-1 ring-yellow-200">
                        <x-icons.shield class="h-5 w-5 text-yellow-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Security') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Authentication policy') }}</p>
                    </div>
                </div>
                <x-ui.badge variant="{{ $mfaRequired ? 'green' : 'gray' }}">{{ $mfaRequired ? __('Enforced') : __('Optional') }}</x-ui.badge>
            </div>

            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model="mfaRequired" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                <span class="text-sm text-gray-700">{{ __('Require two-factor authentication for all users') }}</span>
            </label>
            <p class="mt-1 text-xs text-gray-500">{{ __('Users without 2FA enabled will be redirected to their profile to set it up.') }}</p>
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
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-orange-50 shadow-sm ring-1 ring-orange-200">
                        <x-icons.layers class="h-5 w-5 text-orange-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Site Statuses') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Group sites by custom statuses like "Maintenance" or "Miscellaneous".') }}</p>
                    </div>
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

    {{-- Danger Zone --}}
    <div class="mt-8">
        <x-ui.card class="border-red-200 ring-red-100">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-red-50 shadow-sm ring-1 ring-red-200">
                        <x-icons.alert-triangle class="h-5 w-5 text-red-600" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-red-600">{{ __('Danger Zone') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Permanently delete all monitoring check and incident data.') }}</p>
                    </div>
                </div>
                <x-ui.badge variant="red">{{ __('Destructive') }}</x-ui.badge>
            </div>

            <x-ui.button variant="danger" wire:click="purgeMonitoringData" wire:confirm="{{ __('Are you sure? This will delete ALL monitoring data and cannot be undone.') }}" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="purgeMonitoringData">{{ __('Purge Monitoring Data') }}</span>
                <span wire:loading wire:target="purgeMonitoringData">{{ __('Purging...') }}</span>
            </x-ui.button>
        </x-ui.card>
    </div>
</div>
