<div>
    <x-ui.page-header :title="__('Maintenance Plans')" :subtitle="__('Create and apply configuration plans to your sites')">
        @if($view === 'list' && auth()->user()->isAdmin())
            <x-slot:actions>
                @if($this->plans->isNotEmpty())
                    @php $defaultPlan = $this->plans->firstWhere('is_default', true) ?? $this->plans->first(); @endphp
                    <button wire:click="applyPlanToAll({{ $defaultPlan->id }})"
                            wire:confirm="{{ __('Apply the default plan to all connected sites that have no plan assigned?') }}"
                            wire:loading.attr="disabled"
                            wire:target="applyPlanToAll"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition disabled:opacity-60 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                        </svg>
                        <span wire:loading.remove wire:target="applyPlanToAll">{{ __('Apply to Unassigned') }}</span>
                        <span wire:loading wire:target="applyPlanToAll">{{ __('Applying...') }}</span>
                    </button>
                @endif
                <x-ui.button variant="secondary" wire:click="openCreateFromSite">{{ __('Create from Site') }}</x-ui.button>
                <x-ui.button wire:click="openCreate">{{ __('New Plan') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    {{-- List View --}}
    @if($view === 'list')
        @if($this->plans->isEmpty())
            <x-ui.empty-state
                :title="__('No plans yet')"
                :description="__('Create a maintenance plan to quickly configure modules, security, and tweaks for your sites.')"
            />
        @else
            <div class="space-y-3">
                @foreach($this->plans as $plan)
                    <x-ui.card>
                        <div class="flex items-start justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <h4 class="text-sm font-semibold text-gray-900">{{ $plan->name }}</h4>
                                    @if($plan->is_default)
                                        <span class="inline-flex items-center rounded-full bg-accent-100 px-2 py-0.5 text-xs font-medium text-accent-700">{{ __('Default') }}</span>
                                    @endif
                                    <span class="text-xs text-gray-400">{{ $plan->sites_count }} {{ __('site(s)') }}</span>
                                </div>
                                @if($plan->description)
                                    <p class="mt-1 text-sm text-gray-500">{{ $plan->description }}</p>
                                @endif

                                {{-- Section pills --}}
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @if($plan->include_modules)
                                        <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">{{ __('Modules') }}</span>
                                    @endif
                                    @if($plan->include_security && !empty($plan->security_settings))
                                        <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">
                                            {{ __('Security') }} ({{ $this->countEnabledSettings($plan->security_settings) }})
                                        </span>
                                    @endif
                                    @if($plan->include_tweaks && !empty($plan->tweak_settings))
                                        <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20">
                                            {{ __('Tweaks') }} ({{ $this->countEnabledSettings($plan->tweak_settings) }})
                                        </span>
                                    @endif
                                </div>

                                {{-- Enabled modules --}}
                                @if($plan->include_modules && $plan->planModules->where('is_enabled', true)->isNotEmpty())
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach($plan->planModules as $mod)
                                            @if($mod->is_enabled)
                                                <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                                    {{ $this->moduleLabels[$mod->module_key] ?? $mod->module_key }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div class="ml-4 flex items-center gap-2 shrink-0">
                                <button wire:click="startApply({{ $plan->id }})" class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium text-accent-700 hover:bg-accent-50 transition" title="{{ __('Apply to sites') }}">
                                    {{ __('Apply') }}
                                </button>

                                @if(auth()->user()->isAdmin())
                                    <button wire:click="openEdit({{ $plan->id }})" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition">
                                        <x-icons.pencil class="h-4 w-4" />
                                    </button>

                                    @if($confirmDeleteId === $plan->id)
                                        <div class="flex items-center gap-1">
                                            <button wire:click="delete" class="rounded-lg px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition">
                                                {{ __('Confirm') }}
                                            </button>
                                            <button wire:click="cancelDelete" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100 transition">
                                                {{ __('Cancel') }}
                                            </button>
                                        </div>
                                    @else
                                        <button wire:click="confirmDelete({{ $plan->id }})" class="rounded-lg p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600 transition">
                                            <x-icons.trash class="h-4 w-4" />
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Apply View --}}
    @if($view === 'apply')
        @php $plan = $this->plans->firstWhere('id', $applyingPlanId); @endphp
        @if($plan)
            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">{{ __('Apply ":name"', ['name' => $plan->name]) }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __("Select which sites should receive this plan's settings.") }}</p>
                    </div>
                    <button wire:click="backToList" class="text-sm text-gray-500 hover:text-gray-700">{{ __('Cancel') }}</button>
                </div>

                {{-- Section checkboxes --}}
                <div class="mb-4">
                    <p class="text-sm font-medium text-gray-700 mb-2">{{ __('Sections to apply:') }}</p>
                    <div class="flex flex-wrap gap-4">
                        @if($plan->include_modules)
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model="applyModules" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                                {{ __('Modules') }}
                            </label>
                        @endif
                        @if($plan->include_security && !empty($plan->security_settings))
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model="applySecurity" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                                {{ __('Security') }} ({{ $this->countSettings($plan->security_settings) }} {{ __('settings') }})
                            </label>
                        @endif
                        @if($plan->include_tweaks && !empty($plan->tweak_settings))
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model="applyTweaks" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                                {{ __('Tweaks') }} ({{ $this->countSettings($plan->tweak_settings) }} {{ __('settings') }})
                            </label>
                        @endif
                    </div>
                </div>

                {{-- Site selector --}}
                <div class="flex items-center justify-between mb-3">
                    <label class="flex items-center gap-2 text-xs text-gray-500">
                        <input type="checkbox" wire:model.live="selectAll" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                        {{ __('Select All') }}
                    </label>
                </div>

                <div class="mb-3">
                    <input type="text" wire:model.live.debounce.300ms="siteSearch" placeholder="{{ __('Search sites...') }}" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                </div>

                <div class="max-h-80 overflow-y-auto rounded-lg border border-gray-200">
                    @forelse($this->sites as $site)
                        <label class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                            <input
                                type="checkbox"
                                wire:model="selectedSiteIds"
                                value="{{ $site->id }}"
                                class="rounded border-gray-300 text-accent-600 focus:ring-accent-500"
                            >
                            <x-site-favicon :site="$site" class="h-5 w-5" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $site->name }}</p>
                                <p class="text-xs text-gray-400 truncate">{{ $site->domain }}</p>
                            </div>
                            <span class="shrink-0 text-xs {{ $site->is_connected ? 'text-green-600' : 'text-gray-400' }}">
                                {{ $site->is_connected ? __('Connected') : __('Disconnected') }}
                            </span>
                        </label>
                    @empty
                        <p class="px-3 py-4 text-sm text-gray-400 text-center">{{ __('No sites found.') }}</p>
                    @endforelse
                </div>

                @if(count($selectedSiteIds) > 0)
                    <p class="mt-2 text-sm text-gray-500">{{ count($selectedSiteIds) }} {{ __('site(s) selected') }}</p>
                @endif

                <x-ui.alert type="warning" class="mt-4">
                    {{ __('Existing settings will be overwritten on the selected sites. Changes will be pushed to connected sites.') }}
                </x-ui.alert>

                <div class="mt-4 flex justify-end">
                    <x-ui.button wire:click="applyPlan" wire:loading.attr="disabled">
                        <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="applyPlan" />
                        {{ __('Apply to :count Site(s)', ['count' => count($selectedSiteIds)]) }}
                    </x-ui.button>
                </div>
            </x-ui.card>
        @endif
    @endif

    {{-- Create / Edit View --}}
    @if($view === 'create' || $view === 'edit')
        <x-ui.card>
            <form wire:submit="save" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-900">
                        {{ $editingId ? __('Edit Plan') : __('New Plan') }}
                    </h3>
                    <button type="button" wire:click="backToList" class="text-sm text-gray-500 hover:text-gray-700">{{ __('Cancel') }}</button>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Name') }}</label>
                        <x-ui.input wire:model="planName" class="mt-1" placeholder="{{ __('e.g. Full Monitoring') }}" />
                        @error('planName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Sort Order') }}</label>
                        <x-ui.input wire:model="planSortOrder" type="number" min="0" class="mt-1" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Description') }}</label>
                    <textarea
                        wire:model="planDescription"
                        rows="2"
                        placeholder="{{ __('Optional description...') }}"
                        class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition placeholder:text-gray-400 focus:border-accent-500 focus:ring-1 focus:ring-accent-500"
                    ></textarea>
                </div>

                <div>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="planIsDefault" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                        <span class="text-sm font-medium text-gray-700">{{ __('Default plan for new sites') }}</span>
                    </label>
                </div>

                {{-- Section Include Checkboxes --}}
                <div>
                    <p class="text-sm font-medium text-gray-700 mb-2">{{ __('Sections to include:') }}</p>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model.live="includeModules" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            {{ __('Modules') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model.live="includeSecurity" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            {{ __('Security') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model.live="includeTweaks" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            {{ __('Tweaks') }}
                        </label>
                    </div>
                </div>

                {{-- Module Toggles --}}
                @if($includeModules)
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">{{ __('Enabled Modules') }}</label>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach($this->moduleKeys as $key)
                                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition {{ ($planModules[$key]['enabled'] ?? false) ? 'bg-accent-50 border-accent-200' : '' }}">
                                    <input
                                        type="checkbox"
                                        wire:click="toggleModuleInForm('{{ $key }}')"
                                        @checked($planModules[$key]['enabled'] ?? false)
                                        class="rounded border-gray-300 text-accent-600 focus:ring-accent-500"
                                    >
                                    <span class="text-sm text-gray-700">{{ $this->moduleLabels[$key] ?? $key }}</span>
                                </label>
                            @endforeach
                        </div>

                        {{-- Backup Config (when backup module is enabled) --}}
                        @if($planModules['backup']['enabled'] ?? false)
                            <div class="mt-3 rounded-lg border border-blue-100 bg-blue-50/50 p-4 space-y-3">
                                <p class="text-sm font-medium text-gray-700">{{ __('Backup Configuration') }}</p>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">{{ __('Frequency') }}</label>
                                        <select wire:model="backupFrequency" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500">
                                            <option value="daily">{{ __('Daily') }}</option>
                                            <option value="weekly">{{ __('Weekly') }}</option>
                                            <option value="monthly">{{ __('Monthly') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">{{ __('Time') }}</label>
                                        <input type="time" wire:model="backupTime" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">{{ __('Timezone') }}</label>
                                        <select wire:model="backupTimezone" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500">
                                            <option value="UTC">UTC</option>
                                            <option value="America/New_York">America/New_York</option>
                                            <option value="America/Chicago">America/Chicago</option>
                                            <option value="America/Denver">America/Denver</option>
                                            <option value="America/Los_Angeles">America/Los_Angeles</option>
                                            <option value="Europe/London">Europe/London</option>
                                            <option value="Europe/Paris">Europe/Paris</option>
                                            <option value="Europe/Berlin">Europe/Berlin</option>
                                            <option value="Europe/Bucharest">Europe/Bucharest</option>
                                            <option value="Asia/Tokyo">Asia/Tokyo</option>
                                            <option value="Australia/Sydney">Australia/Sydney</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">{{ __('Backup Type') }}</label>
                                        <select wire:model="backupType" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500">
                                            <option value="full">{{ __('Full (Files + Database)') }}</option>
                                            <option value="database">{{ __('Database Only') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">{{ __('Retention Type') }}</label>
                                        <select wire:model="backupRetentionType" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500">
                                            <option value="count">{{ __('Keep N backups') }}</option>
                                            <option value="days">{{ __('Keep for N days') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600">{{ __('Retention Value') }}</label>
                                        <input type="number" wire:model="backupRetentionValue" min="1" max="365" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" />
                                    </div>
                                </div>
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model="backupBeforeUpdates" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                                    {{ __('Backup before updates') }}
                                </label>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Security Toggles --}}
                @if($includeSecurity)
                    <div class="space-y-4">
                        <p class="text-sm font-medium text-gray-700">{{ __('Security Settings') }}</p>
                        @foreach($this->securitySettingLabels as $category => $group)
                            <div>
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">{{ $group['title'] }}</p>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach($group['settings'] as $key => $label)
                                        <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition {{ ($securityToggles[$key] ?? false) ? 'bg-amber-50 border-amber-200' : '' }}">
                                            <input
                                                type="checkbox"
                                                wire:click="toggleSecuritySetting('{{ $key }}')"
                                                @checked($securityToggles[$key] ?? false)
                                                class="rounded border-gray-300 text-amber-600 focus:ring-amber-500"
                                            >
                                            <span class="text-sm text-gray-700">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>

                                {{-- Sub-config: Brute Force Protection --}}
                                @if($category === 'login' && ($securityToggles['brute_force_protection'] ?? false))
                                    <div class="mt-2 ml-2 bg-gray-50 rounded-lg p-3">
                                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('Max Attempts') }}</label>
                                                <input type="number" wire:model="bruteForceMaxAttempts" min="1" max="20" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500" />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('Window (minutes)') }}</label>
                                                <input type="number" wire:model="bruteForceWindow" min="1" max="60" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500" />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('Block Duration (minutes)') }}</label>
                                                <input type="number" wire:model="bruteForceBlockDuration" min="1" max="1440" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500" />
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Tweak Toggles --}}
                @if($includeTweaks)
                    <div class="space-y-4">
                        <p class="text-sm font-medium text-gray-700">{{ __('Tweak Settings') }}</p>
                        @foreach($this->tweakSettingLabels as $category => $group)
                            <div>
                                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">{{ $group['title'] }}</p>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach($group['settings'] as $key => $label)
                                        <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition {{ ($tweakToggles[$key] ?? false) ? 'bg-indigo-50 border-indigo-200' : '' }}">
                                            <input
                                                type="checkbox"
                                                wire:click="toggleTweakSetting('{{ $key }}')"
                                                @checked($tweakToggles[$key] ?? false)
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            >
                                            <span class="text-sm text-gray-700">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>

                                {{-- Sub-config: Heartbeat --}}
                                @if($category === 'performance' && ($tweakToggles['heartbeat_control'] ?? false))
                                    <div class="mt-2 ml-2 bg-gray-50 rounded-lg p-3 space-y-2">
                                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('Frontend') }}</label>
                                                <select wire:model="heartbeatFrontend" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    <option value="default">{{ __('Default') }}</option>
                                                    <option value="throttle">{{ __('Throttle') }}</option>
                                                    <option value="disable">{{ __('Disable') }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('Dashboard') }}</label>
                                                <select wire:model="heartbeatDashboard" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    <option value="default">{{ __('Default') }}</option>
                                                    <option value="throttle">{{ __('Throttle') }}</option>
                                                    <option value="disable">{{ __('Disable') }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('Editor') }}</label>
                                                <select wire:model="heartbeatEditor" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    <option value="default">{{ __('Default') }}</option>
                                                    <option value="throttle">{{ __('Throttle') }}</option>
                                                    <option value="disable">{{ __('Disable') }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('Interval (sec)') }}</label>
                                                <input type="number" wire:model="heartbeatInterval" min="15" max="300" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Sub-config: Revisions --}}
                                @if($category === 'performance' && ($tweakToggles['revisions_control'] ?? false))
                                    <div class="mt-2 ml-2 bg-gray-50 rounded-lg p-3">
                                        <div class="max-w-xs">
                                            <label class="block text-xs font-medium text-gray-600">{{ __('Max Revisions') }}</label>
                                            <input type="number" wire:model="revisionsLimit" min="0" max="100" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                        </div>
                                    </div>
                                @endif

                                {{-- Sub-config: Image Upload --}}
                                @if($category === 'performance' && ($tweakToggles['image_upload_control'] ?? false))
                                    <div class="mt-2 ml-2 bg-gray-50 rounded-lg p-3">
                                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('Max Width (px)') }}</label>
                                                <input type="number" wire:model="imageMaxWidth" min="100" max="10000" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('Max Height (px)') }}</label>
                                                <input type="number" wire:model="imageMaxHeight" min="100" max="10000" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-600">{{ __('JPEG Quality') }}</label>
                                                <input type="number" wire:model="jpegQuality" min="10" max="100" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex items-center justify-end gap-3 pt-2">
                    <x-ui.button type="submit">
                        {{ $editingId ? __('Update Plan') : __('Create Plan') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    {{-- Create from Site View --}}
    @if($view === 'create_from_site')
        <x-ui.card>
            <form wire:submit="createFromSite" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Create Plan from Site') }}</h3>
                    <button type="button" wire:click="backToList" class="text-sm text-gray-500 hover:text-gray-700">{{ __('Cancel') }}</button>
                </div>

                <p class="text-sm text-gray-500">{{ __("Snapshot a site's current configuration into a reusable maintenance plan.") }}</p>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Source Site') }}</label>
                    <x-ui.select wire:model="sourceSiteId">
                        <option value="">{{ __('-- Select a site --') }}</option>
                        @foreach($this->sourceSites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }} ({{ $site->domain }})</option>
                        @endforeach
                    </x-ui.select>
                    @error('sourceSiteId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Plan Name') }}</label>
                        <x-ui.input wire:model="snapshotName" class="mt-1" placeholder="{{ __('e.g. Production Config') }}" />
                        @error('snapshotName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Description') }}</label>
                        <x-ui.input wire:model="snapshotDescription" class="mt-1" placeholder="{{ __('Optional description...') }}" />
                        @error('snapshotDescription') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <p class="text-sm font-medium text-gray-700 mb-2">{{ __('What to include:') }}</p>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="snapshotModules" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            {{ __('Module Configuration (uptime, backup, performance, security monitors)') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="snapshotSecurity" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            {{ __('Security Settings (hardening, .htaccess, login, captcha, IP management, activity log)') }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="snapshotTweaks" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            {{ __('Tweak Settings (performance, site control, admin UX, content/media, email)') }}
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled">
                        <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="createFromSite" />
                        {{ __('Create Plan') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
