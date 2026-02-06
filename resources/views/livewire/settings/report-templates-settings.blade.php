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
                                    'overview' => 'Overview',
                                    'updates' => 'Updates',
                                    'uptime' => 'Uptime',
                                    'backups' => 'Backups',
                                    'analytics' => 'Analytics',
                                    'search_console' => 'Search Console',
                                    'performance' => 'Performance',
                                    'links' => 'Links',
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
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 transition"
                                    title="Set as default">
                                Set Default
                            </button>
                        @endif
                        <button wire:click="editTemplate({{ $template->id }})"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition">
                            Edit
                        </button>
                        <button wire:click="duplicateTemplate({{ $template->id }})"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 transition">
                            Duplicate
                        </button>
                        @if($template->schedules_count === 0)
                            <button wire:click="deleteTemplate({{ $template->id }})"
                                    wire:confirm="Are you sure you want to delete this template?"
                                    class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 transition">
                                Delete
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-8 text-center">
                <x-icons.file-text class="mx-auto h-8 w-8 text-gray-400" />
                <p class="mt-2 text-sm font-medium text-gray-900">No templates yet</p>
                <p class="mt-1 text-sm text-gray-500">Create a template to start generating PDF reports</p>
                <button wire:click="openCreateForm" class="mt-3 text-sm font-medium text-purple-600 hover:text-purple-700">
                    Create your first template
                </button>
            </div>
        @endforelse
    </div>

    {{-- Create/Edit Form Modal --}}
    @if($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" wire:click="cancelForm"></div>
            <div class="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-5 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">
                        {{ $editingTemplateId ? 'Edit Template' : 'New Template' }}
                    </h3>
                    <button wire:click="cancelForm" class="text-gray-400 hover:text-gray-600">
                        <x-icons.x class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-5">
                    {{-- Name --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Template Name</label>
                        <input type="text" wire:model="name"
                               placeholder="e.g. Monthly Maintenance Report"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500">
                        @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Description --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea wire:model="description" rows="2"
                                  placeholder="Optional description"
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500"></textarea>
                    </div>

                    {{-- Sections --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Report Sections</label>
                        @error('sections') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        <div class="grid grid-cols-2 gap-2">
                            @php
                                $availableSections = [
                                    'overview' => 'Executive Overview',
                                    'updates' => 'WordPress Updates',
                                    'uptime' => 'Uptime Monitoring',
                                    'backups' => 'Backup Status',
                                    'analytics' => 'Google Analytics',
                                    'search_console' => 'Search Console',
                                    'performance' => 'Performance (PageSpeed)',
                                    'links' => 'Broken Links',
                                ];
                            @endphp
                            @foreach($availableSections as $key => $label)
                                <label class="flex items-center gap-2 rounded-lg border border-gray-200 p-2.5 cursor-pointer hover:bg-gray-50">
                                    <input type="checkbox" wire:model="sections" value="{{ $key }}"
                                           class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                    <span class="text-sm text-gray-700">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Branding --}}
                    <div class="border-t border-gray-200 pt-5">
                        <h4 class="text-sm font-medium text-gray-900 mb-3">Branding</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Company Name</label>
                                <input type="text" wire:model="company_name"
                                       placeholder="Your Company"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Website</label>
                                <input type="text" wire:model="company_website"
                                       placeholder="https://example.com"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:ring-purple-500">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Primary Color</label>
                            <div class="flex items-center gap-3">
                                <input type="color" wire:model="primary_color"
                                       class="h-9 w-14 cursor-pointer rounded border border-gray-300">
                                <input type="text" wire:model="primary_color"
                                       class="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:border-purple-500 focus:ring-purple-500">
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
                    <button wire:click="cancelForm" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="saveTemplate" class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700">
                        {{ $editingTemplateId ? 'Update Template' : 'Create Template' }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
