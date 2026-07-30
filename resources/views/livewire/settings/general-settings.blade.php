@php
    // Built here rather than inline: the copy contains double quotes, which
    // would terminate a `:subtitle="__('…')"` component attribute early.
    $statusesSubtitle = __('Group sites by custom statuses like "Maintenance" or "Miscellaneous".');
@endphp

<div class="space-y-6">
    <form wire:submit="save" class="space-y-6">
        {{-- Branding --}}
        <x-settings.section id="branding"
                            :title="__('Branding')"
                            :subtitle="__('Application name, URL, and branding assets')">
            <x-slot:actions>
                <x-ui.badge variant="blue">{{ $form->appName ?: __('SimpleAd Manager') }}</x-ui.badge>
            </x-slot:actions>

            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form-group :label="__('Application Name')" for="appName" error="form.appName"
                                     :hint="__('Shown in the browser title and in outgoing reports.')">
                        <x-ui.input wire:model.live.debounce.500ms="form.appName" id="appName" :placeholder="__('SimpleAd Manager')" />
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Application URL')" for="appUrl" error="form.appUrl"
                                     :hint="__('Base address used when building links in emails.')">
                        <x-ui.input wire:model="form.appUrl" id="appUrl" type="url" :placeholder="__('https://your-app.com')" />
                    </x-ui.form-group>
                </div>

                {{-- Favicon & logo --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {{-- Favicon --}}
                    <div class="rounded-lg border border-gray-100 p-3">
                        <p class="mb-3 text-sm font-medium text-gray-900">{{ __('Favicon') }}</p>

                        <div class="flex items-start gap-4">
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg text-sm font-bold text-white {{ ($form->favicon || $faviconPath) ? 'border border-gray-200 bg-white' : 'bg-accent-500' }}">
                                @if($form->favicon)
                                    <img src="{{ $form->favicon->temporaryUrl() }}" alt="{{ __('Favicon preview') }}" class="h-full w-full object-contain">
                                @elseif($faviconPath)
                                    <img src="{{ Storage::url($faviconPath) }}" alt="{{ __('Favicon') }}" class="h-full w-full object-contain">
                                @else
                                    SA
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <input type="file"
                                       wire:model="form.favicon"
                                       id="favicon"
                                       accept="image/*,.svg"
                                       aria-label="{{ __('Upload a favicon') }}"
                                       class="w-full text-[13px] text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-accent-50 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-accent-700 hover:file:bg-accent-100">

                                <div wire:loading wire:target="form.favicon" class="mt-1 flex items-center gap-1.5 text-xs text-gray-500">
                                    <x-ui.spinner size="xs" />
                                    {{ __('Uploading…') }}
                                </div>

                                @error('form.favicon')
                                    <p class="mt-1 text-xs text-red-600" role="alert">{{ $message }}</p>
                                @enderror

                                @unless($errors->has('form.favicon'))
                                    <p class="mt-1 text-xs text-gray-500">{{ __('Square image or SVG, up to 1 MB.') }}</p>
                                @endunless

                                @if($faviconPath)
                                    <x-ui.button type="button"
                                                 variant="danger"
                                                 size="xs"
                                                 class="mt-2"
                                                 wire:click="removeFavicon"
                                                 wire:confirm="{{ __('Remove the current favicon?') }}"
                                                 wire:loading.attr="disabled"
                                                 wire:target="removeFavicon">
                                        <x-icons.trash class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Remove') }}
                                    </x-ui.button>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Logo --}}
                    <div class="rounded-lg border border-gray-100 p-3">
                        <p class="mb-3 text-sm font-medium text-gray-900">{{ __('Logo') }}</p>

                        <div class="flex items-start gap-4">
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200">
                                @if($form->logo)
                                    <img src="{{ $form->logo->temporaryUrl() }}" alt="{{ __('Logo preview') }}" class="h-full w-full object-contain">
                                @elseif($logoPath)
                                    <img src="{{ Storage::url($logoPath) }}" alt="{{ __('Logo') }}" class="h-full w-full object-contain">
                                @else
                                    <x-icons.image class="h-4 w-4 text-gray-400" aria-hidden="true" />
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <input type="file"
                                       wire:model="form.logo"
                                       id="logo"
                                       accept="image/*,.svg"
                                       aria-label="{{ __('Upload a logo') }}"
                                       class="w-full text-[13px] text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-accent-50 file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-accent-700 hover:file:bg-accent-100">

                                <div wire:loading wire:target="form.logo" class="mt-1 flex items-center gap-1.5 text-xs text-gray-500">
                                    <x-ui.spinner size="xs" />
                                    {{ __('Uploading…') }}
                                </div>

                                @error('form.logo')
                                    <p class="mt-1 text-xs text-red-600" role="alert">{{ $message }}</p>
                                @enderror

                                @unless($errors->has('form.logo'))
                                    <p class="mt-1 text-xs text-gray-500">{{ __('Replaces the app name in the sidebar, up to 2 MB.') }}</p>
                                @endunless

                                @if($logoPath)
                                    <x-ui.button type="button"
                                                 variant="danger"
                                                 size="xs"
                                                 class="mt-2"
                                                 wire:click="removeLogo"
                                                 wire:confirm="{{ __('Remove the current logo?') }}"
                                                 wire:loading.attr="disabled"
                                                 wire:target="removeLogo">
                                        <x-icons.trash class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Remove') }}
                                    </x-ui.button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Accent colour --}}
                <x-ui.form-group :label="__('Accent Color')"
                                 for="accentColor"
                                 error="form.accentColor"
                                 :hint="__('Primary color used across the interface. Leave it empty for the default blue.')">
                    <div class="flex items-center gap-3">
                        <input type="color"
                               wire:model.live="form.accentColor"
                               id="accentColor"
                               aria-label="{{ __('Accent color picker') }}"
                               class="h-8 w-12 shrink-0 cursor-pointer rounded-lg border border-gray-300 p-1">

                        <div class="w-28 shrink-0">
                            <x-ui.input wire:model.live="form.accentColor"
                                        class="font-mono"
                                        maxlength="7"
                                        aria-label="{{ __('Accent color hex value') }}"
                                        placeholder="#2271b1" />
                        </div>

                        <span class="inline-block h-8 w-8 shrink-0 rounded-lg border border-gray-200"
                              style="background-color: {{ $form->accentColor ?: '#2271b1' }}"
                              aria-hidden="true"></span>

                        @if($form->accentColor)
                            <x-ui.button type="button"
                                         variant="ghost"
                                         size="xs"
                                         wire:click="$set('form.accentColor', null)">
                                {{ __('Reset') }}
                            </x-ui.button>
                        @endif
                    </div>
                </x-ui.form-group>
            </div>
        </x-settings.section>

        {{-- Regional --}}
        <x-settings.section id="regional"
                            :title="__('Regional')"
                            :subtitle="__('Timezone and date format preferences')">
            <x-slot:actions>
                {{-- rescue(): a stored timezone that is blank or no longer a
                     valid identifier would otherwise throw straight out of the
                     header and 500 the whole settings page. --}}
                @php
                    $regionalPreview = rescue(
                        fn () => now()->setTimezone($form->defaultTimezone)->format($form->dateFormat),
                        null,
                        false,
                    );
                @endphp
                @if($regionalPreview)
                    <x-ui.badge variant="gray">{{ $regionalPreview }}</x-ui.badge>
                @endif
            </x-slot:actions>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.form-group :label="__('Default Timezone')" for="defaultTimezone" error="form.defaultTimezone"
                                 :hint="__('Timestamps across the app are rendered in this zone.')">
                    <x-ui.select wire:model.live="form.defaultTimezone" id="defaultTimezone">
                        @foreach(timezone_identifiers_list() as $tz)
                            <option value="{{ $tz }}">{{ $tz }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Date Format')" for="dateFormat" error="form.dateFormat"
                                 :hint="__('Used wherever a date is shown without a time.')">
                    <x-ui.select wire:model.live="form.dateFormat" id="dateFormat">
                        <option value="M d, Y">{{ now()->format('M d, Y') }} (M d, Y)</option>
                        <option value="d/m/Y">{{ now()->format('d/m/Y') }} (d/m/Y)</option>
                        <option value="d.m.Y">{{ now()->format('d.m.Y') }} (d.m.Y)</option>
                        <option value="m/d/Y">{{ now()->format('m/d/Y') }} (m/d/Y)</option>
                        <option value="Y-m-d">{{ now()->format('Y-m-d') }} (Y-m-d)</option>
                    </x-ui.select>
                </x-ui.form-group>
            </div>
        </x-settings.section>

        {{-- Monitoring defaults --}}
        @php
            $intervalLabel = match((int) $form->defaultInterval) {
                60 => __('1 minute'),
                120 => __('2 minutes'),
                300 => __('5 minutes'),
                600 => __('10 minutes'),
                900 => __('15 minutes'),
                1800 => __('30 minutes'),
                3600 => __('1 hour'),
                default => __(':seconds seconds', ['seconds' => $form->defaultInterval]),
            };
        @endphp

        <x-settings.section id="monitoring"
                            :title="__('Monitoring Defaults')"
                            :subtitle="__('Default check intervals and alert thresholds for new sites')">
            <x-slot:actions>
                <x-ui.badge variant="green">{{ $intervalLabel }}</x-ui.badge>
            </x-slot:actions>

            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form-group :label="__('Default Check Interval')" for="defaultInterval" error="form.defaultInterval"
                                     :hint="__('How often a new monitor probes its site.')">
                        <x-ui.select wire:model.live="form.defaultInterval" id="defaultInterval">
                            <option value="60">{{ __('1 minute') }}</option>
                            <option value="120">{{ __('2 minutes') }}</option>
                            <option value="300">{{ __('5 minutes') }}</option>
                            <option value="600">{{ __('10 minutes') }}</option>
                            <option value="900">{{ __('15 minutes') }}</option>
                            <option value="1800">{{ __('30 minutes') }}</option>
                            <option value="3600">{{ __('1 hour') }}</option>
                        </x-ui.select>
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Default Timeout (seconds)')" for="defaultTimeout" error="form.defaultTimeout"
                                     :hint="__('Between 5 and 120 seconds.')">
                        <x-ui.input wire:model="form.defaultTimeout" id="defaultTimeout" type="number" min="5" max="120" />
                    </x-ui.form-group>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form-group :label="__('Alert After Failures')" for="alertAfterFailures" error="form.alertAfterFailures"
                                     :hint="__('Consecutive failures before alerting (1-10).')">
                        <x-ui.input wire:model="form.alertAfterFailures" id="alertAfterFailures" type="number" min="1" max="10" />
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Dashboard Sites Per Page')" for="dashboardPerPage" error="form.dashboardPerPage"
                                     :hint="__('Number of sites shown on the dashboard (10-200).')">
                        <x-ui.input wire:model="form.dashboardPerPage" id="dashboardPerPage" type="number" min="10" max="200" />
                    </x-ui.form-group>

                    <x-ui.form-group :label="__('Sites List Per Page')" for="sitesPerPage" error="form.sitesPerPage"
                                     :hint="__('Number of sites shown on the Sites page (10-200).')">
                        <x-ui.input wire:model="form.sitesPerPage" id="sitesPerPage" type="number" min="10" max="200" />
                    </x-ui.form-group>
                </div>
            </div>
        </x-settings.section>

        <div class="flex justify-end">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                {{ __('Save Settings') }}
            </x-ui.button>
        </div>
    </form>

    {{-- Site statuses --}}
    <x-settings.section id="statuses" :title="__('Site Statuses')" :subtitle="$statusesSubtitle">
        <x-slot:actions>
            <x-ui.button type="button" size="sm" variant="secondary" wire:click="openStatusForm">
                <x-icons.plus class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Add Status') }}
            </x-ui.button>
        </x-slot:actions>

        @if($this->siteStatuses->isEmpty())
            <x-ui.empty-state icon="layers"
                              :title="__('No site statuses yet')"
                              :description="__('Statuses group sites outside their uptime state — for example while a site is under maintenance.')">
                <x-slot:action>
                    <x-ui.button type="button" size="sm" wire:click="openStatusForm">
                        <x-icons.plus class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Add Status') }}
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="space-y-2">
                @foreach($this->siteStatuses as $status)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 p-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="h-3 w-3 shrink-0 rounded-full"
                                  style="background-color: {{ $status->color }}"
                                  aria-hidden="true"></span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900">{{ $status->name }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ trans_choice('{0}No sites|{1}:count site|[2,*]:count sites', $status->sites_count, ['count' => $status->sites_count]) }}
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button type="button" variant="ghost" size="xs"
                                         wire:click="openStatusForm({{ $status->id }})">
                                <x-icons.pencil class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Edit') }}
                            </x-ui.button>

                            <x-ui.button type="button" variant="danger" size="xs"
                                         wire:click="deleteStatus({{ $status->id }})"
                                         wire:confirm="{{ __('Delete this status?') }}"
                                         wire:loading.attr="disabled"
                                         wire:target="deleteStatus({{ $status->id }})">
                                <x-icons.trash class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Delete') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-settings.section>

    {{-- Status form modal --}}
    <x-ui.modal name="status-form" maxWidth="sm">
        <form wire:submit="saveStatus">
            <h2 class="text-base font-semibold text-gray-900">{{ $editingStatusId ? __('Edit Status') : __('Add Status') }}</h2>
            <p class="mt-0.5 text-sm text-gray-500">{{ __('A name, a colour, and a position in the list.') }}</p>

            <div class="mt-4 space-y-4">
                <x-ui.form-group :label="__('Name')" for="statusName" error="statusForm.statusName">
                    <x-ui.input wire:model="statusForm.statusName" id="statusName" :placeholder="__('e.g. Maintenance')" />
                </x-ui.form-group>

                <x-ui.form-group :label="__('Color')" for="statusColor" error="statusForm.statusColor">
                    <div class="flex items-center gap-3">
                        <input type="color"
                               wire:model.live="statusForm.statusColor"
                               id="statusColor"
                               aria-label="{{ __('Status color picker') }}"
                               class="h-8 w-12 shrink-0 cursor-pointer rounded-lg border border-gray-300 p-1">
                        <div class="min-w-0 flex-1">
                            <x-ui.input wire:model.live="statusForm.statusColor"
                                        class="font-mono"
                                        maxlength="7"
                                        aria-label="{{ __('Status color hex value') }}"
                                        placeholder="#6b7280" />
                        </div>
                    </div>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Sort Order')" for="statusSortOrder" error="statusForm.statusSortOrder"
                                 :hint="__('Lower numbers appear first.')">
                    <x-ui.input wire:model="statusForm.statusSortOrder" id="statusSortOrder" type="number" min="0" />
                </x-ui.form-group>
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal-status-form')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveStatus">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="saveStatus" />
                    {{ $editingStatusId ? __('Save Changes') : __('Add Status') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Danger zone --}}
    <x-settings.section id="danger"
                        :title="__('Danger Zone')"
                        :subtitle="__('Irreversible maintenance actions on stored monitoring history.')">
        <x-slot:actions>
            <x-ui.badge variant="red">{{ __('Destructive') }}</x-ui.badge>
        </x-slot:actions>

        <div class="flex flex-col gap-3 rounded-lg border border-red-200 bg-red-50 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <x-icons.alert-triangle class="mt-0.5 h-4 w-4 shrink-0 text-red-600" aria-hidden="true" />
                <div class="min-w-0">
                    <p class="text-sm font-medium text-red-800">{{ __('Purge monitoring data') }}</p>
                    <p class="mt-0.5 text-xs text-red-700">{{ __('Permanently deletes every uptime check and incident record. Uptime history and SLA figures cannot be recovered afterwards.') }}</p>
                </div>
            </div>

            <x-ui.button type="button"
                         variant="danger"
                         class="shrink-0"
                         wire:click="purgeMonitoringData"
                         wire:confirm="{{ __('Are you sure? This will delete ALL monitoring data and cannot be undone.') }}"
                         wire:loading.attr="disabled"
                         wire:target="purgeMonitoringData">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="purgeMonitoringData" />
                {{ __('Purge Monitoring Data') }}
            </x-ui.button>
        </div>
    </x-settings.section>
</div>
