<div>
    @include('livewire.settings.partials.settings-tabs')

    <div class="mb-6 flex justify-end">
        <button wire:click="openCreateForm"
                class="inline-flex items-center gap-2 rounded-lg bg-accent-600 px-4 py-2 text-sm font-medium text-white hover:bg-accent-700 transition">
            <x-icons.plus class="h-4 w-4" />
            {{ __('New Template') }}
        </button>
    </div>

    @if(session()->has('template-success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-700">
            {{ session('template-success') }}
        </div>
    @endif
    @if(session()->has('template-error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-700">
            {{ session('template-error') }}
        </div>
    @endif

    {{-- Templates List --}}
    <div class="space-y-4">
        @forelse($templates as $template)
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="font-medium text-gray-900">{{ $template->name }}</h3>
                            @if($template->is_default)
                                <x-ui.badge variant="purple">{{ __('Default') }}</x-ui.badge>
                            @endif
                        </div>
                        @if($template->description)
                            <p class="mt-1 text-sm text-gray-500">{{ $template->description }}</p>
                        @endif

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @php
                                $sectionLabels = [
                                    'overview'         => __('Overview'),
                                    'updates'          => __('Updates'),
                                    'uptime'           => __('Uptime'),
                                    'backups'          => __('Backups'),
                                    'analytics'        => __('Analytics'),
                                    'search_console'   => __('Search Console'),
                                    'performance'      => __('Performance'),
                                    'infrastructure'   => __('Infrastructure'),
                                    'plugin_inventory' => __('Plugins'),
                                    'database_health'  => __('DB Health'),
                                    'cloudflare'       => 'Cloudflare',
                                    'wp_users'         => __('WP Users'),
                                    'security_checks'  => __('Security'),
                                    'recommendations'  => __('Recommendations'),
                                ];
                            @endphp
                            @foreach($template->sections ?? [] as $section)
                                <x-ui.badge variant="gray">{{ $sectionLabels[$section] ?? ucfirst($section) }}</x-ui.badge>
                            @endforeach
                        </div>

                        <div class="mt-2 flex items-center gap-4 text-xs text-gray-500">
                            @if($template->company_name)
                                <span>{{ __('Brand:') }} {{ $template->company_name }}</span>
                            @endif
                            <span>
                                <span class="inline-block h-3 w-3 rounded-full" style="background-color: {{ $template->primary_color }}; vertical-align: middle;"></span>
                                {{ $template->primary_color }}
                            </span>
                            <span>{{ $template->schedules_count }} {{ Str::plural('schedule', $template->schedules_count) }}</span>
                            <span>{{ $template->sites_count }} {{ Str::plural('site', $template->sites_count) }} {{ __('assigned') }}</span>
                            <span>{{ strtoupper($template->language) }}</span>
                            @if(($nextRunDates[$template->id] ?? null))
                                <span class="inline-flex items-center gap-1 text-accent-600 font-medium">
                                    <svg aria-hidden="true" class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    {{ __('Next report:') }} {{ $nextRunDates[$template->id]->timezone('Europe/Bucharest')->format('M d, Y H:i') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-2 ml-4">
                        <button wire:click="openBulkScheduleModal({{ $template->id }})"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-accent-300 px-3 py-1.5 text-xs font-medium text-accent-700 hover:bg-accent-50 transition disabled:opacity-50"
                                title="{{ __('Create monthly schedules for all assigned sites') }}">
                            {{ __('Schedule All') }}
                        </button>
                        <button wire:click="openAssignSites({{ $template->id }})"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition disabled:opacity-50"
                                title="{{ __('Assign sites') }}">
                            {{ __('Assign Sites') }}
                        </button>
                        @if(!$template->is_default)
                            <button wire:click="setDefault({{ $template->id }})"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 transition disabled:opacity-50"
                                    title="{{ __('Set as default') }}">
                                {{ __('Set Default') }}
                            </button>
                        @endif
                        <button wire:click="editTemplate({{ $template->id }})"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition disabled:opacity-50">
                            {{ __('Edit') }}
                        </button>
                        <button wire:click="duplicateTemplate({{ $template->id }})"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition disabled:opacity-50">
                            {{ __('Duplicate') }}
                        </button>
                        @if($template->schedules_count === 0 && $template->sites_count === 0)
                            <button wire:click="deleteTemplate({{ $template->id }})"
                                    wire:confirm="{{ __('Are you sure you want to delete this template?') }}"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition disabled:opacity-50">
                                {{ __('Delete') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <x-ui.card>
                <x-ui.empty-state
                    title="{{ __('No templates yet') }}"
                    description="{{ __('Create a template to start generating PDF reports.') }}"
                    icon="file-text"
                >
                    <x-ui.button wire:click="openCreateForm" size="sm">
                        {{ __('Create your first template') }}
                    </x-ui.button>
                </x-ui.empty-state>
            </x-ui.card>
        @endforelse

        @if($templates->hasPages())
            <div class="mt-4">
                {{ $templates->links() }}
            </div>
        @endif
    </div>

    {{-- Create/Edit Form Modal --}}
    <x-ui.modal name="template-form" maxWidth="3xl">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">
                {{ $editingTemplateId ? __('Edit Template') : __('New Template') }}
            </h3>
            <button @click="$dispatch('close-modal-template-form')" class="text-gray-400 hover:text-gray-600">
                <x-icons.x class="h-5 w-5" />
            </button>
        </div>

        <div class="space-y-5">
            {{-- Name --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Template Name') }}</label>
                <x-ui.input type="text" wire:model="name" placeholder="{{ __('e.g. Monthly Maintenance Report') }}" />
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Description --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Description') }}</label>
                <textarea wire:model="description" rows="2"
                          placeholder="{{ __('Optional description') }}"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
            </div>

            {{-- Sections with expandable customization --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Report Sections') }}</label>
                @error('sections') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror

                @php
                    $availableSections = [
                        'overview'         => ['label' => __('Executive Overview'),       'optionsKey' => 'executive_snapshot'],
                        'updates'          => ['label' => __('WordPress Updates'),        'optionsKey' => 'updates'],
                        'uptime'           => ['label' => __('Uptime & Stability'),       'optionsKey' => 'technical_stability'],
                        'infrastructure'   => ['label' => __('Infrastructure'),            'optionsKey' => 'infrastructure'],
                        'backups'          => ['label' => __('Backup Status'),             'optionsKey' => 'backups'],
                        'analytics'        => ['label' => __('Google Analytics'),          'optionsKey' => 'analytics'],
                        'search_console'   => ['label' => __('Search Console'),            'optionsKey' => 'search_console'],
                        'performance'      => ['label' => __('Performance (PageSpeed)'),   'optionsKey' => 'performance'],
                        'plugin_inventory' => ['label' => __('Plugin Inventory'),           'optionsKey' => 'plugin_inventory'],
                        'database_health'  => ['label' => __('Database Health'),            'optionsKey' => 'database_health'],
                        'cloudflare'       => ['label' => 'Cloudflare',                    'optionsKey' => 'cloudflare'],
                        'wp_users'         => ['label' => __('WordPress Users'),            'optionsKey' => 'wp_users'],
                        'security_checks'  => ['label' => __('Security Checks'),            'optionsKey' => 'security_checks'],
                        'recommendations'  => ['label' => __('Recommendations'),             'optionsKey' => 'recommendations'],
                    ];
                @endphp

                <div class="space-y-2">
                    @foreach($availableSections as $sectionKey => $sectionConfig)
                        @php
                            $isEnabled = in_array($sectionKey, $sections);
                            $optionsKey = $sectionConfig['optionsKey'];
                            $hasSubOptions = $optionsKey && !empty($sectionSubOptions[$optionsKey] ?? []);
                            $isExpanded = in_array($sectionKey, $expandedSections);
                        @endphp
                        <div class="rounded-lg border {{ $isEnabled ? 'border-accent-300 bg-accent-50/50' : 'border-gray-200 bg-white' }} transition-colors">
                            {{-- Section header row --}}
                            <div class="flex items-center gap-3 p-3 {{ $isEnabled && $optionsKey ? 'cursor-pointer' : '' }}"
                                 @if($isEnabled && $optionsKey) wire:click="toggleSectionExpand('{{ $sectionKey }}')" @endif>
                                <input type="checkbox" wire:model.live="sections" value="{{ $sectionKey }}"
                                       class="rounded border-gray-300 text-accent-600 focus:ring-accent-500"
                                       onclick="event.stopPropagation()">
                                <span class="flex-1 text-sm font-medium {{ $isEnabled ? 'text-gray-900' : 'text-gray-500' }}">{{ $sectionConfig['label'] }}</span>
                                @if($isEnabled && ($hasSubOptions || $optionsKey))
                                    <svg aria-hidden="true" class="h-4 w-4 text-gray-400 transition-transform {{ $isExpanded ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                    </svg>
                                @endif
                            </div>

                            {{-- Expanded customization panel --}}
                            @if($isEnabled && $isExpanded && $optionsKey)
                                <div class="border-t border-accent-200/60 px-3 pb-3 pt-3 space-y-3">
                                    {{-- Title override --}}
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Custom Title') }}</label>
                                        <input type="text"
                                               wire:model="section_overrides.{{ $optionsKey }}.title"
                                               placeholder="{{ __('Default from translations') }}"
                                               class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-accent-500 focus:ring-accent-500">
                                    </div>

                                    {{-- Description override --}}
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('Custom Description') }}</label>
                                        <textarea wire:model="section_overrides.{{ $optionsKey }}.description"
                                                  rows="2"
                                                  placeholder="{{ __('Default from translations') }}"
                                                  class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                                    </div>

                                    {{-- Sub-section toggles --}}
                                    @if($hasSubOptions)
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1.5">{{ __('Show / Hide Sub-sections') }}</label>
                                            <div class="grid grid-cols-2 gap-1.5">
                                                @foreach($sectionSubOptions[$optionsKey] as $optKey => $optLabel)
                                                    <label class="flex items-center gap-2 rounded border border-gray-200 bg-white px-2.5 py-1.5 cursor-pointer hover:bg-gray-50 text-xs">
                                                        <input type="checkbox"
                                                               wire:model="section_options.{{ $optionsKey }}.{{ $optKey }}"
                                                               class="rounded border-gray-300 text-accent-600 focus:ring-accent-500 h-3.5 w-3.5">
                                                        <span class="text-gray-700">{{ $optLabel }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach

                </div>
            </div>

            {{-- Language --}}
            <div class="border-t border-gray-200 pt-5">
                <h4 class="text-sm font-medium text-gray-900 mb-3">{{ __('Language') }}</h4>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model="language" value="ro" class="text-accent-600 focus:ring-accent-500">
                        <span class="text-sm">{{ __('Romanian (RO)') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="radio" wire:model="language" value="en" class="text-accent-600 focus:ring-accent-500">
                        <span class="text-sm">{{ __('English (EN)') }}</span>
                    </label>
                </div>
            </div>

            {{-- Branding --}}
            <div class="border-t border-gray-200 pt-5">
                <h4 class="text-sm font-medium text-gray-900 mb-3">{{ __('Branding') }}</h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">{{ __('Company Name') }}</label>
                        <x-ui.input type="text" wire:model="company_name" placeholder="{{ __('Your Company') }}" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">{{ __('Website') }}</label>
                        <x-ui.input type="text" wire:model="company_website" placeholder="https://example.com" />
                    </div>
                </div>
                <div class="mt-3">
                    <label class="block text-xs font-medium text-gray-700 mb-1">{{ __('Primary Color') }}</label>
                    <div class="flex items-center gap-3">
                        <input type="color" wire:model="primary_color" class="h-9 w-14 cursor-pointer rounded border border-gray-300">
                        <x-ui.input type="text" wire:model="primary_color" class="w-24 font-mono" />
                    </div>
                </div>
            </div>

            {{-- Content --}}
            <div class="border-t border-gray-200 pt-5">
                <h4 class="text-sm font-medium text-gray-900 mb-3">{{ __('Report Content') }}</h4>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">{{ __('Introduction Text') }}</label>
                    <textarea wire:model="intro_text" rows="3"
                              placeholder="{{ __('Text shown at the beginning of the report...') }}"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                </div>
                <div class="mt-3">
                    <label class="block text-xs font-medium text-gray-700 mb-1">{{ __('Closing Text') }}</label>
                    <textarea wire:model="closing_text" rows="3"
                              placeholder="{{ __('Text shown at the end of the report...') }}"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <x-ui.button variant="secondary" wire:click="cancelForm">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button wire:click="saveTemplate" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="saveTemplate">{{ $editingTemplateId ? __('Update Template') : __('Create Template') }}</span>
                <span wire:loading wire:target="saveTemplate">{{ __('Saving...') }}</span>
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Bulk Schedule Modal --}}
    @if($showBulkScheduleModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" wire:click="$set('showBulkScheduleModal', false)"></div>
            <div class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ __('Schedule All Sites') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Create monthly schedules (day 1) for all assigned sites without a schedule') }}</p>
                    </div>
                    <button wire:click="$set('showBulkScheduleModal', false)" class="text-gray-400 hover:text-gray-600">
                        <x-icons.x class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Send Time') }}</label>
                        <x-ui.input type="time" wire:model="bulkScheduleTime" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('Europe/Bucharest timezone') }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Report Period') }}</label>
                        <x-ui.select wire:model="bulkSchedulePeriod">
                            <option value="last_month">{{ __('Last calendar month') }}</option>
                            <option value="last_30_days">{{ __('Last 30 days') }}</option>
                            <option value="last_7_days">{{ __('Last 7 days') }}</option>
                        </x-ui.select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Recipient Emails') }}</label>
                        <x-ui.input type="text" wire:model="bulkScheduleRecipientEmails"
                               placeholder="{{ __('client@example.com, team@example.com') }}" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('Comma-separated. Leave empty if admin copy is sufficient.') }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" wire:model="bulkScheduleSendCopyToAdmin" id="bulkAdminCopy"
                               class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                        <label for="bulkAdminCopy" class="text-sm text-gray-700">{{ __('Send copy to admin email') }}</label>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button variant="secondary" wire:click="$set('showBulkScheduleModal', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button wire:click="saveBulkSchedule" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveBulkSchedule">{{ __('Create Schedules') }}</span>
                        <span wire:loading wire:target="saveBulkSchedule">{{ __('Creating...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Assign Sites Modal --}}
    @if($showAssignSitesModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" wire:click="$set('showAssignSitesModal', false)"></div>
            <div class="relative w-full max-w-lg max-h-[80vh] overflow-hidden rounded-xl bg-white shadow-xl flex flex-col">
                <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-semibold text-gray-900">{{ __('Assign Sites to Template') }}</h3>
                    <button wire:click="$set('showAssignSitesModal', false)" class="text-gray-400 hover:text-gray-600">
                        <x-icons.x class="h-5 w-5" />
                    </button>
                </div>

                <div class="px-6 pt-4 pb-2">
                    <x-ui.input type="text" wire:model.live.debounce.300ms="siteSearch" placeholder="{{ __('Search sites...') }}" />
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-2">
                    @forelse($assignSites as $site)
                        @php
                            $isAssigned = in_array($site->id, $assignedSiteIds);
                            $otherTemplate = $site->report_template_id && $site->report_template_id !== $assignTemplateId;
                        @endphp
                        <label class="flex items-center gap-3 rounded-lg px-2 py-2 cursor-pointer hover:bg-gray-50 transition">
                            <input type="checkbox"
                                   wire:click="toggleSiteAssignment({{ $site->id }})"
                                   {{ $isAssigned ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-accent-600 focus:ring-accent-500">
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-900 truncate">{{ $site->name }}</div>
                                <div class="text-xs text-gray-500 truncate">{{ $site->url }}</div>
                            </div>
                            @if($otherTemplate)
                                <span class="text-amber-500 flex-shrink-0" title="{{ __('Already assigned to another template') }}">
                                    <svg aria-hidden="true" class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                                </span>
                            @endif
                        </label>
                    @empty
                        <p class="text-sm text-gray-400 text-center py-4">{{ __('No sites found.') }}</p>
                    @endforelse
                </div>

                <div class="flex items-center justify-between border-t border-gray-200 px-6 py-4">
                    <span class="text-sm text-gray-500">{{ count($assignedSiteIds) }} {{ __('site(s) selected') }}</span>
                    <div class="flex gap-3">
                        <x-ui.button variant="secondary" wire:click="$set('showAssignSitesModal', false)">{{ __('Cancel') }}</x-ui.button>
                        <x-ui.button wire:click="saveAssignedSites">{{ __('Save') }}</x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
