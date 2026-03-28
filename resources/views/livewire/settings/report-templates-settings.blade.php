<div>
    @include('livewire.settings.partials.settings-tabs')

    <div class="mb-6 flex justify-end">
        <button wire:click="openCreateForm"
                class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition">
            <x-icons.plus class="h-4 w-4" />
            New Template
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
                                <x-ui.badge variant="purple">Default</x-ui.badge>
                            @endif
                        </div>
                        @if($template->description)
                            <p class="mt-1 text-sm text-gray-500">{{ $template->description }}</p>
                        @endif

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @php
                                $sectionLabels = [
                                    'overview'         => 'Overview',
                                    'updates'          => 'Updates',
                                    'uptime'           => 'Uptime',
                                    'backups'          => 'Backups',
                                    'analytics'        => 'Analytics',
                                    'search_console'   => 'Search Console',
                                    'performance'      => 'Performance',
                                    'infrastructure'   => 'Infrastructure',
                                    'plugin_inventory' => 'Plugins',
                                    'database_health'  => 'DB Health',
                                    'cloudflare'       => 'Cloudflare',
                                    'wp_users'         => 'WP Users',
                                    'security_checks'  => 'Security',
                                    'recommendations'  => 'Recommendations',
                                ];
                            @endphp
                            @foreach($template->sections ?? [] as $section)
                                <x-ui.badge variant="gray">{{ $sectionLabels[$section] ?? ucfirst($section) }}</x-ui.badge>
                            @endforeach
                        </div>

                        <div class="mt-2 flex items-center gap-4 text-xs text-gray-500">
                            @if($template->company_name)
                                <span>Brand: {{ $template->company_name }}</span>
                            @endif
                            <span>
                                <span class="inline-block h-3 w-3 rounded-full" style="background-color: {{ $template->primary_color }}; vertical-align: middle;"></span>
                                {{ $template->primary_color }}
                            </span>
                            <span>Used by {{ $template->schedules_count }} {{ Str::plural('schedule', $template->schedules_count) }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 ml-4">
                        @if(!$template->is_default)
                            <button wire:click="setDefault({{ $template->id }})"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 transition disabled:opacity-50"
                                    title="Set as default">
                                Set Default
                            </button>
                        @endif
                        <button wire:click="editTemplate({{ $template->id }})"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition disabled:opacity-50">
                            Edit
                        </button>
                        <button wire:click="duplicateTemplate({{ $template->id }})"
                                wire:loading.attr="disabled"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition disabled:opacity-50">
                            Duplicate
                        </button>
                        @if($template->schedules_count === 0)
                            <button wire:click="deleteTemplate({{ $template->id }})"
                                    wire:confirm="Are you sure you want to delete this template?"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition disabled:opacity-50">
                                Delete
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <x-ui.card>
                <x-ui.empty-state
                    title="No templates yet"
                    description="Create a template to start generating PDF reports."
                    icon="file-text"
                >
                    <x-ui.button wire:click="openCreateForm" size="sm">
                        Create your first template
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
                {{ $editingTemplateId ? 'Edit Template' : 'New Template' }}
            </h3>
            <button @click="$dispatch('close-modal-template-form')" class="text-gray-400 hover:text-gray-600">
                <x-icons.x class="h-5 w-5" />
            </button>
        </div>

        <div class="space-y-5">
            {{-- Name --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Template Name</label>
                <x-ui.input type="text" wire:model="name" placeholder="e.g. Monthly Maintenance Report" />
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- Description --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea wire:model="description" rows="2"
                          placeholder="Optional description"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
            </div>

            {{-- Sections with expandable customization --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Report Sections</label>
                @error('sections') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror

                @php
                    $availableSections = [
                        'overview'         => ['label' => 'Executive Overview',       'optionsKey' => 'executive_snapshot'],
                        'updates'          => ['label' => 'WordPress Updates',        'optionsKey' => 'updates'],
                        'uptime'           => ['label' => 'Uptime & Stability',       'optionsKey' => 'technical_stability'],
                        'infrastructure'   => ['label' => 'Infrastructure',            'optionsKey' => 'infrastructure'],
                        'backups'          => ['label' => 'Backup Status',             'optionsKey' => 'backups'],
                        'analytics'        => ['label' => 'Google Analytics',          'optionsKey' => 'analytics'],
                        'search_console'   => ['label' => 'Search Console',            'optionsKey' => 'search_console'],
                        'performance'      => ['label' => 'Performance (PageSpeed)',    'optionsKey' => 'performance'],
                        'plugin_inventory' => ['label' => 'Plugin Inventory',           'optionsKey' => 'plugin_inventory'],
                        'database_health'  => ['label' => 'Database Health',            'optionsKey' => 'database_health'],
                        'cloudflare'       => ['label' => 'Cloudflare',                 'optionsKey' => 'cloudflare'],
                        'wp_users'         => ['label' => 'WordPress Users',            'optionsKey' => 'wp_users'],
                        'security_checks'  => ['label' => 'Security Checks',            'optionsKey' => 'security_checks'],
                        'recommendations'  => ['label' => 'Recommendations',             'optionsKey' => 'recommendations'],
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
                        <div class="rounded-lg border {{ $isEnabled ? 'border-purple-300 bg-purple-50/50' : 'border-gray-200 bg-white' }} transition-colors">
                            {{-- Section header row --}}
                            <div class="flex items-center gap-3 p-3 {{ $isEnabled && $optionsKey ? 'cursor-pointer' : '' }}"
                                 @if($isEnabled && $optionsKey) wire:click="toggleSectionExpand('{{ $sectionKey }}')" @endif>
                                <input type="checkbox" wire:model.live="sections" value="{{ $sectionKey }}"
                                       class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                       onclick="event.stopPropagation()">
                                <span class="flex-1 text-sm font-medium {{ $isEnabled ? 'text-gray-900' : 'text-gray-500' }}">{{ $sectionConfig['label'] }}</span>
                                @if($isEnabled && ($hasSubOptions || $optionsKey))
                                    <svg class="h-4 w-4 text-gray-400 transition-transform {{ $isExpanded ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                    </svg>
                                @endif
                            </div>

                            {{-- Expanded customization panel --}}
                            @if($isEnabled && $isExpanded && $optionsKey)
                                <div class="border-t border-purple-200/60 px-3 pb-3 pt-3 space-y-3">
                                    {{-- Title override --}}
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Custom Title</label>
                                        <input type="text"
                                               wire:model="section_overrides.{{ $optionsKey }}.title"
                                               placeholder="Default from translations"
                                               class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-purple-500 focus:ring-purple-500">
                                    </div>

                                    {{-- Description override --}}
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Custom Description</label>
                                        <textarea wire:model="section_overrides.{{ $optionsKey }}.description"
                                                  rows="2"
                                                  placeholder="Default from translations"
                                                  class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
                                    </div>

                                    {{-- Sub-section toggles --}}
                                    @if($hasSubOptions)
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Show / Hide Sub-sections</label>
                                            <div class="grid grid-cols-2 gap-1.5">
                                                @foreach($sectionSubOptions[$optionsKey] as $optKey => $optLabel)
                                                    <label class="flex items-center gap-2 rounded border border-gray-200 bg-white px-2.5 py-1.5 cursor-pointer hover:bg-gray-50 text-xs">
                                                        <input type="checkbox"
                                                               wire:model="section_options.{{ $optionsKey }}.{{ $optKey }}"
                                                               class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 h-3.5 w-3.5">
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

            {{-- Branding --}}
            <div class="border-t border-gray-200 pt-5">
                <h4 class="text-sm font-medium text-gray-900 mb-3">Branding</h4>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Company Name</label>
                        <x-ui.input type="text" wire:model="company_name" placeholder="Your Company" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Website</label>
                        <x-ui.input type="text" wire:model="company_website" placeholder="https://example.com" />
                    </div>
                </div>
                <div class="mt-3">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Primary Color</label>
                    <div class="flex items-center gap-3">
                        <input type="color" wire:model="primary_color" class="h-9 w-14 cursor-pointer rounded border border-gray-300">
                        <x-ui.input type="text" wire:model="primary_color" class="w-24 font-mono" />
                    </div>
                </div>
            </div>

            {{-- Content --}}
            <div class="border-t border-gray-200 pt-5">
                <h4 class="text-sm font-medium text-gray-900 mb-3">Report Content</h4>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Introduction Text</label>
                    <textarea wire:model="intro_text" rows="3"
                              placeholder="Text shown at the beginning of the report..."
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
                </div>
                <div class="mt-3">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Closing Text</label>
                    <textarea wire:model="closing_text" rows="3"
                              placeholder="Text shown at the end of the report..."
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <x-ui.button variant="secondary" wire:click="cancelForm">Cancel</x-ui.button>
            <x-ui.button wire:click="saveTemplate" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="saveTemplate">{{ $editingTemplateId ? 'Update Template' : 'Create Template' }}</span>
                <span wire:loading wire:target="saveTemplate">Saving...</span>
            </x-ui.button>
        </div>
    </x-ui.modal>
</div>
