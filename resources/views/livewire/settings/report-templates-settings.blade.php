<div class="space-y-6">
    @php
        // Short badge labels for the section keys stored on a template.
        // Keep in sync with WithTemplateForm::resetForm() / $sectionKeyMap.
        $sectionLabels = [
            'overview' => __('Overview'),
            'updates' => __('Updates'),
            'uptime' => __('Uptime'),
            'infrastructure' => __('Infrastructure'),
            'backups' => __('Backups'),
            'tasks' => __('Work carried out'),
            'analytics' => __('Analytics'),
            'search_console' => __('Search Console'),
            'performance' => __('Performance'),
            'broken_links' => __('Broken links'),
            'plugin_inventory' => __('Plugins'),
            'database_health' => __('DB Health'),
            'cloudflare' => 'Cloudflare',
            'wp_users' => __('WP Users'),
            'security_checks' => __('Security'),
            'recommendations' => __('Recommendations'),
            'seo' => __('SEO'),
        ];
    @endphp

    <x-settings.section :title="__('Report templates')"
                        :subtitle="__('Sections, branding and content of the generated PDF reports.')">
        <x-slot:actions>
            <x-ui.button size="sm" wire:click="openCreateForm">
                <x-icons.plus class="h-4 w-4" aria-hidden="true" />
                {{ __('New Template') }}
            </x-ui.button>
        </x-slot:actions>

        <div class="space-y-3">
            @forelse($templates as $template)
                <x-ui.card :padding="false" class="p-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h3 class="font-medium text-gray-900">{{ $template->name }}</h3>
                                @if($template->is_default)
                                    <x-ui.badge variant="blue">{{ __('Default') }}</x-ui.badge>
                                @endif
                            </div>
                            @if($template->description)
                                <p class="mt-1 text-sm text-gray-500">{{ $template->description }}</p>
                            @endif

                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach($template->sections ?? [] as $section)
                                    <x-ui.badge variant="gray">{{ $sectionLabels[$section] ?? ucfirst($section) }}</x-ui.badge>
                                @endforeach
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                                @if($template->company_name)
                                    <span>{{ __('Brand:') }} {{ $template->company_name }}</span>
                                @endif
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="inline-block h-3 w-3 rounded-full ring-1 ring-inset ring-black/10"
                                          style="background-color: {{ $template->primary_color }};"
                                          aria-hidden="true"></span>
                                    <span class="font-mono">{{ $template->primary_color }}</span>
                                </span>
                                <span>{{ __(':count schedule(s)', ['count' => $template->schedules_count]) }}</span>
                                <span>{{ __(':count site(s) assigned', ['count' => $template->sites_count]) }}</span>
                                <span>{{ strtoupper($template->language) }}</span>
                                @if(($nextRunDates[$template->id] ?? null))
                                    <span class="inline-flex items-center gap-1 font-medium text-accent-600">
                                        <x-icons.calendar class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Next report:') }}
                                        {{ $nextRunDates[$template->id]->timezone('Europe/Bucharest')->translatedFormat('d M Y H:i') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <x-ui.button size="xs" variant="secondary"
                                         wire:click="openBulkScheduleModal({{ $template->id }})"
                                         wire:loading.attr="disabled"
                                         title="{{ __('Create monthly schedules for all assigned sites') }}">
                                {{ __('Schedule All') }}
                            </x-ui.button>
                            <x-ui.button size="xs" variant="secondary"
                                         wire:click="openAssignSites({{ $template->id }})"
                                         wire:loading.attr="disabled"
                                         title="{{ __('Assign sites') }}">
                                {{ __('Assign Sites') }}
                            </x-ui.button>
                            @if(! $template->is_default)
                                <x-ui.button size="xs" variant="ghost"
                                             wire:click="setDefault({{ $template->id }})"
                                             wire:loading.attr="disabled"
                                             title="{{ __('Set as default') }}">
                                    {{ __('Set Default') }}
                                </x-ui.button>
                            @endif
                            <x-ui.button size="xs" variant="secondary"
                                         wire:click="editTemplate({{ $template->id }})"
                                         wire:loading.attr="disabled">
                                <x-icons.pencil class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Edit') }}
                            </x-ui.button>
                            <x-ui.button size="xs" variant="secondary"
                                         wire:click="duplicateTemplate({{ $template->id }})"
                                         wire:loading.attr="disabled">
                                <x-icons.copy class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Duplicate') }}
                            </x-ui.button>
                            @if($template->schedules_count === 0 && $template->sites_count === 0)
                                <x-ui.button size="xs" variant="danger"
                                             wire:click="deleteTemplate({{ $template->id }})"
                                             wire:confirm="{{ __('Are you sure you want to delete this template?') }}"
                                             wire:loading.attr="disabled">
                                    <x-icons.trash class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Delete') }}
                                </x-ui.button>
                            @endif
                        </div>
                    </div>
                </x-ui.card>
            @empty
                {{-- The button used to sit in the default slot, which x-ui.empty-state
                     never renders — it only prints the named `action` slot. --}}
                <x-ui.empty-state
                    :title="__('No templates yet')"
                    :description="__('Create a template to start generating PDF reports.')"
                    icon="file-text"
                >
                    <x-slot:action>
                        <x-ui.button size="sm" wire:click="openCreateForm">
                            {{ __('Create your first template') }}
                        </x-ui.button>
                    </x-slot:action>
                </x-ui.empty-state>
            @endforelse
        </div>

        @if($templates->hasPages())
            <div class="mt-4">
                {{ $templates->links() }}
            </div>
        @endif
    </x-settings.section>

    {{-- Create/Edit Form Modal --}}
    <x-ui.modal name="template-form" maxWidth="3xl">
        <div class="mb-5 flex items-center justify-between">
            <h3 id="modal-template-form-title" class="text-lg font-semibold text-gray-900">
                {{ $editingTemplateId ? __('Edit Template') : __('New Template') }}
            </h3>
            <button type="button"
                    x-on:click="$dispatch('close-modal-template-form')"
                    class="text-gray-400 hover:text-gray-600"
                    aria-label="{{ __('Close') }}">
                <x-icons.x class="h-5 w-5" aria-hidden="true" />
            </button>
        </div>

        <div class="space-y-5">
            <x-ui.form-group :label="__('Template Name')" for="tpl-name" error="name" required>
                <x-ui.input id="tpl-name" type="text" wire:model="name"
                            :error="$errors->has('name')"
                            placeholder="{{ __('e.g. Monthly Maintenance Report') }}" />
            </x-ui.form-group>

            <x-ui.form-group :label="__('Description')" for="tpl-description" error="description">
                <textarea id="tpl-description" wire:model="description" rows="2"
                          placeholder="{{ __('Optional description') }}"
                          class="block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-[13px] placeholder:text-gray-400 focus:border-accent-500 focus:outline-none focus:ring-0 dark:border-gray-600 dark:bg-gray-800"></textarea>
            </x-ui.form-group>

            {{-- Sections with expandable customization --}}
            <div>
                <span class="mb-1 block text-sm font-medium text-gray-700">{{ __('Report Sections') }}</span>
                @error('sections') <p class="mb-2 text-xs text-red-600" role="alert">{{ $message }}</p> @enderror

                @php
                    $availableSections = [
                        'overview'         => ['label' => __('Executive Overview'),       'optionsKey' => 'executive_snapshot'],
                        'updates'          => ['label' => __('WordPress Updates'),        'optionsKey' => 'updates'],
                        'uptime'           => ['label' => __('Uptime & Stability'),       'optionsKey' => 'technical_stability'],
                        'infrastructure'   => ['label' => __('Infrastructure'),           'optionsKey' => 'infrastructure'],
                        'backups'          => ['label' => __('Backup Status'),            'optionsKey' => 'backups'],
                        'tasks'            => ['label' => __('Work carried out (app.simplead.ro)'), 'optionsKey' => 'tasks'],
                        'analytics'        => ['label' => __('Google Analytics'),         'optionsKey' => 'analytics'],
                        'search_console'   => ['label' => __('Search Console'),           'optionsKey' => 'search_console'],
                        'performance'      => ['label' => __('Performance (PageSpeed)'),  'optionsKey' => 'performance'],
                        'broken_links'     => ['label' => __('Broken links'),             'optionsKey' => 'broken_links'],
                        'plugin_inventory' => ['label' => __('Plugin Inventory'),         'optionsKey' => 'plugin_inventory'],
                        'database_health'  => ['label' => __('Database Health'),          'optionsKey' => 'database_health'],
                        'cloudflare'       => ['label' => 'Cloudflare',                   'optionsKey' => 'cloudflare'],
                        'wp_users'         => ['label' => __('WordPress Users'),          'optionsKey' => 'wp_users'],
                        'security_checks'  => ['label' => __('Security Checks'),          'optionsKey' => 'security_checks'],
                        'recommendations'  => ['label' => __('Recommendations'),          'optionsKey' => 'recommendations'],
                    ];
                @endphp

                <div class="space-y-2">
                    @foreach($availableSections as $sectionKey => $sectionConfig)
                        @php
                            $isEnabled = in_array($sectionKey, $sections);
                            $optionsKey = $sectionConfig['optionsKey'];
                            $hasSubOptions = $optionsKey && ! empty($sectionSubOptions[$optionsKey] ?? []);
                            $isExpanded = in_array($sectionKey, $expandedSections);
                            $overrideTitleError = $errors->has("section_overrides.{$optionsKey}.title");
                            $overrideDescError = $errors->has("section_overrides.{$optionsKey}.description");
                            $overrideHasError = $overrideTitleError || $overrideDescError;
                        @endphp
                        <div class="rounded-lg border transition-colors {{ $isEnabled ? 'border-accent-300 bg-accent-50/50' : 'border-gray-200 bg-white' }}">
                            {{-- Section header row --}}
                            <div class="flex items-center gap-3 p-3 {{ $isEnabled && $optionsKey ? 'cursor-pointer' : '' }}"
                                 @if($isEnabled && $optionsKey) wire:click="toggleSectionExpand('{{ $sectionKey }}')" @endif>
                                {{-- The row itself toggles the expand panel; the checkbox must not. --}}
                                <span onclick="event.stopPropagation()">
                                    <x-ui.checkbox wire:model.live="sections" value="{{ $sectionKey }}" />
                                </span>
                                <span class="flex-1 text-sm font-medium {{ $isEnabled ? 'text-gray-900' : 'text-gray-500' }}">{{ $sectionConfig['label'] }}</span>
                                @if($overrideHasError)
                                    <x-ui.badge variant="red">{{ __('Error') }}</x-ui.badge>
                                @endif
                                @if($isEnabled && ($hasSubOptions || $optionsKey))
                                    <svg aria-hidden="true" class="h-4 w-4 text-gray-400 transition-transform {{ $isExpanded ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                    </svg>
                                @endif
                            </div>

                            {{-- Expanded customization panel --}}
                            @if($isEnabled && $isExpanded && $optionsKey)
                                <div class="space-y-3 border-t border-accent-200/60 px-3 pb-3 pt-3">
                                    <x-ui.form-group :label="__('Custom Title')"
                                                     for="override-{{ $optionsKey }}-title"
                                                     error="section_overrides.{{ $optionsKey }}.title">
                                        <x-ui.input id="override-{{ $optionsKey }}-title" type="text"
                                                    wire:model="section_overrides.{{ $optionsKey }}.title"
                                                    :error="$overrideTitleError"
                                                    placeholder="{{ __('Default from translations') }}" />
                                    </x-ui.form-group>

                                    <x-ui.form-group :label="__('Custom Description')"
                                                     for="override-{{ $optionsKey }}-description"
                                                     error="section_overrides.{{ $optionsKey }}.description">
                                        <textarea id="override-{{ $optionsKey }}-description"
                                                  wire:model="section_overrides.{{ $optionsKey }}.description"
                                                  rows="2"
                                                  placeholder="{{ __('Default from translations') }}"
                                                  class="block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-[13px] placeholder:text-gray-400 focus:border-accent-500 focus:outline-none focus:ring-0 dark:border-gray-600 dark:bg-gray-800"></textarea>
                                    </x-ui.form-group>

                                    {{-- Sub-section toggles --}}
                                    @if($hasSubOptions)
                                        <div>
                                            <span class="mb-1.5 block text-xs font-medium text-gray-600">{{ __('Show / Hide Sub-sections') }}</span>
                                            <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                                                @foreach($sectionSubOptions[$optionsKey] as $optKey => $optLabel)
                                                    <div class="rounded border border-gray-200 bg-white px-2.5 py-1.5 hover:bg-gray-50">
                                                        <x-ui.checkbox wire:model="section_options.{{ $optionsKey }}.{{ $optKey }}"
                                                                       :label="$optLabel"
                                                                       class="h-3.5 w-3.5" />
                                                    </div>
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
                <h4 class="mb-3 text-sm font-medium text-gray-900">{{ __('Language') }}</h4>
                <div class="flex gap-4">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="radio" wire:model="language" value="ro" class="text-accent-600 focus:ring-accent-500">
                        <span class="text-sm">{{ __('Romanian (RO)') }}</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="radio" wire:model="language" value="en" class="text-accent-600 focus:ring-accent-500">
                        <span class="text-sm">{{ __('English (EN)') }}</span>
                    </label>
                </div>
                @error('language') <p class="mt-1 text-xs text-red-600" role="alert">{{ $message }}</p> @enderror
            </div>

            {{-- Branding --}}
            <div class="border-t border-gray-200 pt-5">
                <h4 class="mb-3 text-sm font-medium text-gray-900">{{ __('Branding') }}</h4>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.form-group :label="__('Company Name')" for="tpl-company-name" error="company_name">
                        <x-ui.input id="tpl-company-name" type="text" wire:model="company_name"
                                    :error="$errors->has('company_name')"
                                    placeholder="{{ __('Your Company') }}" />
                    </x-ui.form-group>
                    <x-ui.form-group :label="__('Website')" for="tpl-company-website" error="company_website">
                        <x-ui.input id="tpl-company-website" type="text" wire:model="company_website"
                                    :error="$errors->has('company_website')"
                                    placeholder="{{ __('https://example.com') }}" />
                    </x-ui.form-group>
                </div>
                <x-ui.form-group class="mt-3" :label="__('Primary Color')" for="tpl-primary-color" error="primary_color" required>
                    <div class="flex items-center gap-3">
                        <input type="color" wire:model="primary_color"
                               class="h-9 w-14 cursor-pointer rounded border border-gray-300 dark:border-gray-600"
                               aria-label="{{ __('Primary Color') }}">
                        <x-ui.input id="tpl-primary-color" type="text" wire:model="primary_color"
                                    :error="$errors->has('primary_color')"
                                    class="w-28 font-mono" />
                    </div>
                </x-ui.form-group>
            </div>

            {{-- Content --}}
            <div class="border-t border-gray-200 pt-5">
                <h4 class="mb-3 text-sm font-medium text-gray-900">{{ __('Report Content') }}</h4>
                <x-ui.form-group :label="__('Introduction Text')" for="tpl-intro-text" error="intro_text">
                    <textarea id="tpl-intro-text" wire:model="intro_text" rows="3"
                              placeholder="{{ __('Text shown at the beginning of the report...') }}"
                              class="block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-[13px] placeholder:text-gray-400 focus:border-accent-500 focus:outline-none focus:ring-0 dark:border-gray-600 dark:bg-gray-800"></textarea>
                </x-ui.form-group>
                <x-ui.form-group class="mt-3" :label="__('Closing Text')" for="tpl-closing-text" error="closing_text">
                    <textarea id="tpl-closing-text" wire:model="closing_text" rows="3"
                              placeholder="{{ __('Text shown at the end of the report...') }}"
                              class="block w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-[13px] placeholder:text-gray-400 focus:border-accent-500 focus:outline-none focus:ring-0 dark:border-gray-600 dark:bg-gray-800"></textarea>
                </x-ui.form-group>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <x-ui.button variant="secondary" wire:click="cancelForm">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button wire:click="saveTemplate" wire:loading.attr="disabled" wire:target="saveTemplate">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="saveTemplate" />
                <span wire:loading.remove wire:target="saveTemplate">{{ $editingTemplateId ? __('Update Template') : __('Create Template') }}</span>
                <span wire:loading wire:target="saveTemplate">{{ __('Saving...') }}</span>
            </x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Bulk Schedule Modal.
         The wrapper syncs the Livewire flag whenever the modal closes by any
         route (backdrop, escape, cancel) — the flag still gates server work. --}}
    <div x-data x-on:close-modal-bulk-schedule.window="$wire.get('showBulkScheduleModal') && $wire.set('showBulkScheduleModal', false)">
        <x-ui.modal name="bulk-schedule" maxWidth="md">
            <div class="mb-5 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 id="modal-bulk-schedule-title" class="text-lg font-semibold text-gray-900">{{ __('Schedule All Sites') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Create monthly schedules (day 1) for all assigned sites without a schedule') }}</p>
                </div>
                <button type="button"
                        x-on:click="$dispatch('close-modal-bulk-schedule')"
                        class="shrink-0 text-gray-400 hover:text-gray-600"
                        aria-label="{{ __('Close') }}">
                    <x-icons.x class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            @error('bulkScheduleTemplateId')
                <div class="mb-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                    <x-icons.alert-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{{ $message }}</span>
                </div>
            @enderror

            <div class="space-y-4">
                <x-ui.form-group :label="__('Send Time')" for="bulk-schedule-time" error="bulkScheduleTime"
                                 :hint="__('Europe/Bucharest timezone')" required>
                    <x-ui.input id="bulk-schedule-time" type="time" wire:model="bulkScheduleTime"
                                :error="$errors->has('bulkScheduleTime')" />
                </x-ui.form-group>

                <x-ui.form-group :label="__('Report Period')" for="bulk-schedule-period" error="bulkSchedulePeriod" required>
                    <x-ui.select id="bulk-schedule-period" wire:model="bulkSchedulePeriod">
                        <option value="last_month">{{ __('Last calendar month') }}</option>
                        <option value="last_30_days">{{ __('Last 30 days') }}</option>
                        <option value="last_7_days">{{ __('Last 7 days') }}</option>
                    </x-ui.select>
                </x-ui.form-group>

                <x-ui.form-group :label="__('Recipient Emails')" for="bulk-schedule-emails"
                                 error="bulkScheduleRecipientEmails"
                                 :hint="__('Comma-separated. Leave empty if admin copy is sufficient.')">
                    <x-ui.input id="bulk-schedule-emails" type="text" wire:model="bulkScheduleRecipientEmails"
                                placeholder="{{ __('client@example.com, team@example.com') }}" />
                </x-ui.form-group>

                <x-ui.checkbox id="bulkAdminCopy"
                               wire:model="bulkScheduleSendCopyToAdmin"
                               :label="__('Send copy to admin email')" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal-bulk-schedule')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button wire:click="saveBulkSchedule" wire:loading.attr="disabled" wire:target="saveBulkSchedule">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="saveBulkSchedule" />
                    <span wire:loading.remove wire:target="saveBulkSchedule">{{ __('Create Schedules') }}</span>
                    <span wire:loading wire:target="saveBulkSchedule">{{ __('Creating...') }}</span>
                </x-ui.button>
            </div>
        </x-ui.modal>
    </div>

    {{-- Assign Sites Modal --}}
    <div x-data x-on:close-modal-assign-sites.window="$wire.get('showAssignSitesModal') && $wire.set('showAssignSitesModal', false)">
        <x-ui.modal name="assign-sites" maxWidth="lg">
            <div class="mb-4 flex items-center justify-between gap-4">
                <h3 id="modal-assign-sites-title" class="text-lg font-semibold text-gray-900">{{ __('Assign Sites to Template') }}</h3>
                <button type="button"
                        x-on:click="$dispatch('close-modal-assign-sites')"
                        class="shrink-0 text-gray-400 hover:text-gray-600"
                        aria-label="{{ __('Close') }}">
                    <x-icons.x class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            @php
                $assignErrors = collect($errors->get('assignTemplateId'))
                    ->merge(collect($errors->get('assignedSiteIds'))->flatten())
                    ->merge(collect($errors->get('assignedSiteIds.*'))->flatten())
                    ->unique()
                    ->values();
            @endphp
            @if($assignErrors->isNotEmpty())
                <div class="mb-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                    <x-icons.alert-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <ul class="space-y-0.5">
                        @foreach($assignErrors as $assignError)
                            <li>{{ $assignError }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <x-ui.input type="search" wire:model.live.debounce.300ms="siteSearch"
                        aria-label="{{ __('Search sites...') }}"
                        placeholder="{{ __('Search sites...') }}" />

            <div class="-mx-1 mt-3 max-h-[45vh] overflow-y-auto px-1">
                @forelse($assignSites as $site)
                    @php
                        $isAssigned = in_array($site->id, $assignedSiteIds);
                        $otherTemplate = $site->report_template_id && $site->report_template_id !== $assignTemplateId;
                    @endphp
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-gray-50">
                        <input type="checkbox"
                               wire:click="toggleSiteAssignment({{ $site->id }})"
                               {{ $isAssigned ? 'checked' : '' }}
                               class="rounded border-gray-300 text-accent-600 focus:ring-accent-500 dark:border-gray-600 dark:bg-gray-700">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-gray-900">{{ $site->name }}</div>
                            <div class="truncate text-xs text-gray-500">{{ $site->url }}</div>
                        </div>
                        @if($otherTemplate)
                            <span class="shrink-0 text-yellow-500" title="{{ __('Already assigned to another template') }}">
                                <x-icons.alert-triangle class="h-4 w-4" aria-hidden="true" />
                                <span class="sr-only">{{ __('Already assigned to another template') }}</span>
                            </span>
                        @endif
                    </label>
                @empty
                    <p class="py-6 text-center text-sm text-gray-400">{{ __('No sites found.') }}</p>
                @endforelse
            </div>

            <div class="mt-4 flex items-center justify-between gap-4 border-t border-gray-200 pt-4">
                <span class="text-sm text-gray-500">{{ __(':count site(s) selected', ['count' => count($assignedSiteIds)]) }}</span>
                <div class="flex gap-3">
                    <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal-assign-sites')">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button wire:click="saveAssignedSites" wire:loading.attr="disabled" wire:target="saveAssignedSites">
                        <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="saveAssignedSites" />
                        <span wire:loading.remove wire:target="saveAssignedSites">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveAssignedSites">{{ __('Saving...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    </div>
</div>
