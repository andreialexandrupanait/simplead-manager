<div>
    <x-ui.page-header title="{{ __('Security Presets') }}" subtitle="{{ __('Manage reusable security hardening configurations') }}">
        <x-ui.button wire:click="$set('showForm', true)" size="sm">
            <x-icons.plus class="h-4 w-4 mr-1" /> {{ __('New Preset') }}
        </x-ui.button>
    </x-ui.page-header>

    <x-ui.flash-alert type="success" key="preset-success" />

    {{-- Create/Edit Form --}}
    @if($showForm)
        <x-ui.card class="mb-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4">
                {{ $editingId ? __('Edit Preset') : __('New Preset') }}
            </h3>
            <div class="space-y-4">
                <x-ui.form-group label="{{ __('Name') }}" for="presetName" error="{{ $errors->first('presetName') }}">
                    <x-ui.input type="text" id="presetName" wire:model="presetName" placeholder="{{ __('e.g. Maximum Security') }}" />
                </x-ui.form-group>

                <x-ui.form-group label="{{ __('Description') }}" for="presetDescription">
                    <textarea id="presetDescription" wire:model="presetDescription" rows="2"
                        class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500"
                        placeholder="{{ __('Describe what this preset configures...') }}"></textarea>
                </x-ui.form-group>

                <label class="flex items-center gap-2">
                    <x-ui.checkbox wire:model="isDefault" />
                    <span class="text-sm text-gray-700">{{ __('Set as default preset') }}</span>
                </label>

                <div class="flex gap-2 justify-end">
                    <x-ui.button variant="secondary" wire:click="resetForm">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button wire:click="savePreset" wire:loading.attr="disabled">
                        {{ $editingId ? __('Update') : __('Create') }}
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

    {{-- Create From Site --}}
    <x-ui.card class="mb-6">
        <h4 class="text-sm font-semibold text-gray-900 mb-3">{{ __('Create Preset from Site') }}</h4>
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-48">
                <x-ui.form-group label="{{ __('Source Site') }}" for="snapshotSiteId" error="{{ $errors->first('snapshotSiteId') }}">
                    <x-ui.select id="snapshotSiteId" wire:model="snapshotSiteId" class="text-sm">
                        <option value="">{{ __('Select site...') }}</option>
                        @foreach($this->availableSites as $site)
                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.form-group>
            </div>
            <div class="flex-1 min-w-[200px]">
                <x-ui.form-group label="{{ __('Preset Name') }}" for="snapshotName" error="{{ $errors->first('snapshotName') }}">
                    <x-ui.input type="text" id="snapshotName" wire:model="snapshotName" placeholder="{{ __('Preset name') }}" class="text-sm" />
                </x-ui.form-group>
            </div>
            <x-ui.button wire:click="createFromSite" wire:loading.attr="disabled" size="sm">
                {{ __('Create Snapshot') }}
            </x-ui.button>
        </div>
    </x-ui.card>

    {{-- Presets List --}}
    <x-ui.card>
        @if($this->presets->isEmpty())
            <x-ui.empty-state
                title="{{ __('No presets yet') }}"
                description="{{ __('Create a preset or run the seeder to get started.') }}"
                icon="shield"
            />
        @else
            <div class="space-y-4">
                @foreach($this->presets as $preset)
                    <div class="rounded-lg border border-gray-200 p-4">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h4 class="text-sm font-semibold text-gray-900">{{ $preset->name }}</h4>
                                    <span class="text-xs text-gray-400">v{{ $preset->version }}</span>
                                    @if($preset->is_default)
                                        <x-ui.badge variant="purple">{{ __('Default') }}</x-ui.badge>
                                    @endif
                                </div>
                                @if($preset->description)
                                    <p class="mt-1 text-xs text-gray-500">{{ $preset->description }}</p>
                                @endif
                                <p class="mt-1 text-xs text-gray-400">
                                    {{ count($preset->settings ?? []) }} {{ __('categories') }} &middot;
                                    {{ $preset->sites_count }} {{ __('site(s) applied') }}
                                </p>
                            </div>
                            <div class="flex gap-1">
                                <x-ui.button variant="secondary" size="sm" wire:click="startApply({{ $preset->id }})">
                                    {{ __('Apply') }}
                                </x-ui.button>
                                <x-ui.button variant="secondary" size="sm" wire:click="editPreset({{ $preset->id }})">
                                    {{ __('Edit') }}
                                </x-ui.button>
                                <x-ui.button variant="secondary" size="sm" wire:click="deletePreset({{ $preset->id }})"
                                    wire:confirm="{{ __('Delete preset') }} '{{ $preset->name }}'?">
                                    <x-icons.trash class="h-3.5 w-3.5 text-red-500" />
                                </x-ui.button>
                            </div>
                        </div>

                        {{-- Apply modal inline --}}
                        @if($applyingPresetId === $preset->id)
                            <div class="mt-4 border-t border-gray-100 pt-4">
                                <h5 class="text-sm font-medium text-gray-900 mb-2">{{ __('Select sites to apply:') }}</h5>
                                <div class="max-h-48 overflow-y-auto space-y-1 mb-3">
                                    @foreach($this->availableSites as $site)
                                        <label class="flex items-center gap-2 p-1 rounded hover:bg-gray-50">
                                            <x-ui.checkbox wire:model="applySiteIds" value="{{ $site->id }}" />
                                            <span class="text-sm text-gray-700">{{ $site->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="flex gap-2">
                                    <x-ui.button size="sm" wire:click="applyToSites"
                                        wire:confirm="{{ __('Apply') }} '{{ $preset->name }}' {{ __('to') }} {{ count($applySiteIds) }} {{ __('site(s)?') }}"
                                        wire:loading.attr="disabled">
                                        {{ __('Apply to') }} {{ count($applySiteIds) }} {{ __('site(s)') }}
                                    </x-ui.button>
                                    <x-ui.button variant="secondary" size="sm" wire:click="cancelApply">{{ __('Cancel') }}</x-ui.button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($this->presets->hasPages())
                <div class="mt-4">
                    {{ $this->presets->links() }}
                </div>
            @endif
        @endif
    </x-ui.card>
</div>
