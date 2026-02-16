<div>
    @include('livewire.settings.partials.settings-tabs')

    <div class="space-y-6">
        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-base font-semibold text-gray-900">Site Presets</h3>
                <p class="mt-1 text-sm text-gray-500">Configure module presets that can be applied to sites during creation or later.</p>
            </div>
            @unless($showForm)
                <x-ui.button wire:click="openCreate">New Preset</x-ui.button>
            @endunless
        </div>

        {{-- Create / Edit Form --}}
        @if($showForm)
            <x-ui.card>
                <form wire:submit="save" class="space-y-4">
                    <h3 class="text-base font-semibold text-gray-900">
                        {{ $editingId ? 'Edit Preset' : 'New Preset' }}
                    </h3>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Name</label>
                            <x-ui.input wire:model="presetName" class="mt-1" placeholder="e.g. Full Monitoring" />
                            @error('presetName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Sort Order</label>
                            <x-ui.input wire:model="presetSortOrder" type="number" min="0" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea
                            wire:model="presetDescription"
                            rows="2"
                            placeholder="Optional description..."
                            class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm transition placeholder:text-gray-400 focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                        ></textarea>
                    </div>

                    <div>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="presetIsDefault" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                            <span class="text-sm font-medium text-gray-700">Default preset for new sites</span>
                        </label>
                    </div>

                    {{-- Module Toggles --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">Enabled Modules</label>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach($this->moduleKeys as $key)
                                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 cursor-pointer hover:bg-gray-50 transition {{ ($presetModules[$key]['enabled'] ?? false) ? 'bg-purple-50 border-purple-200' : '' }}">
                                    <input
                                        type="checkbox"
                                        wire:click="toggleModuleInForm('{{ $key }}')"
                                        @checked($presetModules[$key]['enabled'] ?? false)
                                        class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                    >
                                    <span class="text-sm text-gray-700">{{ $this->moduleLabels[$key] ?? $key }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" wire:click="cancel" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100">
                            Cancel
                        </button>
                        <x-ui.button type="submit">
                            {{ $editingId ? 'Update Preset' : 'Create Preset' }}
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        {{-- Presets List --}}
        @if($this->presets->isEmpty() && !$showForm)
            <x-ui.empty-state
                title="No presets yet"
                description="Create a preset to quickly configure monitoring modules for new sites."
            />
        @else
            <div class="space-y-3">
                @foreach($this->presets as $preset)
                    <x-ui.card>
                        <div class="flex items-start justify-between">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <h4 class="text-sm font-semibold text-gray-900">{{ $preset->name }}</h4>
                                    @if($preset->is_default)
                                        <span class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700">Default</span>
                                    @endif
                                    <span class="text-xs text-gray-400">{{ $preset->sites_count }} site(s)</span>
                                </div>
                                @if($preset->description)
                                    <p class="mt-1 text-sm text-gray-500">{{ $preset->description }}</p>
                                @endif

                                {{-- Active modules --}}
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach($preset->presetModules as $mod)
                                        @if($mod->is_enabled)
                                            <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                                {{ $this->moduleLabels[$mod->module_key] ?? $mod->module_key }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            </div>

                            <div class="ml-4 flex items-center gap-2 shrink-0">
                                <button wire:click="openEdit({{ $preset->id }})" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition">
                                    <x-icons.pencil class="h-4 w-4" />
                                </button>

                                @if($confirmDeleteId === $preset->id)
                                    <div class="flex items-center gap-1">
                                        <button wire:click="delete" class="rounded-lg px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 transition">
                                            Confirm
                                        </button>
                                        <button wire:click="cancelDelete" class="rounded-lg px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100 transition">
                                            Cancel
                                        </button>
                                    </div>
                                @else
                                    <button wire:click="confirmDelete({{ $preset->id }})" class="rounded-lg p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600 transition">
                                        <x-icons.trash class="h-4 w-4" />
                                    </button>
                                @endif
                            </div>
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    </div>
</div>
