<div>
    <x-ui.page-header title="Bulk Settings" subtitle="Apply settings to multiple sites at once" />

    <x-ui.flash-alert type="success" key="bulk-success" />
    <x-ui.flash-alert type="error" key="bulk-error" />

    {{-- Step Indicator --}}
    <div class="mb-6 flex items-center gap-2 text-sm">
        @foreach([1 => 'Select Sites', 2 => 'Choose Operation', 3 => 'Configure & Apply'] as $num => $label)
            <div class="flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-medium {{ $step >= $num ? 'bg-purple-600 text-white' : 'bg-gray-200 text-gray-500' }}">
                    {{ $num }}
                </span>
                <span class="{{ $step >= $num ? 'text-gray-900 font-medium' : 'text-gray-400' }}">{{ $label }}</span>
            </div>
            @if($num < 3)
                <div class="h-px w-8 {{ $step > $num ? 'bg-purple-600' : 'bg-gray-200' }}"></div>
            @endif
        @endforeach
    </div>

    {{-- Step 1: Select Sites --}}
    @if($step === 1)
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-gray-900">Select Sites</h3>
                <div class="flex items-center gap-3">
                    <label class="flex items-center gap-2 text-xs text-gray-500">
                        <input type="checkbox" wire:model.live="selectAll" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        Select All
                    </label>
                </div>
            </div>

            <div class="mb-4">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search sites..." class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500" />
            </div>

            <div class="max-h-96 overflow-y-auto rounded-lg border border-gray-200">
                @forelse($this->sites as $site)
                    <label class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                        <input
                            type="checkbox"
                            wire:model="selectedSiteIds"
                            value="{{ $site->id }}"
                            class="rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                        >
                        <x-site-favicon :site="$site" class="h-5 w-5" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $site->name }}</p>
                            <p class="text-xs text-gray-400 truncate">{{ $site->domain }}</p>
                        </div>
                        <span class="shrink-0 text-xs {{ $site->is_connected ? 'text-green-600' : 'text-gray-400' }}">
                            {{ $site->is_connected ? 'Connected' : 'Disconnected' }}
                        </span>
                    </label>
                @empty
                    <p class="px-3 py-4 text-sm text-gray-400 text-center">No sites found.</p>
                @endforelse
            </div>

            @if(count($selectedSiteIds) > 0)
                <p class="mt-2 text-sm text-gray-500">{{ count($selectedSiteIds) }} site(s) selected</p>
            @endif

            <div class="mt-4 flex justify-end">
                <x-ui.button wire:click="goToStep(2)" {{ empty($selectedSiteIds) ? 'disabled' : '' }}>
                    Next
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- Step 2: Choose Operation --}}
    @if($step === 2)
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">Choose Operation</h3>
            <p class="text-sm text-gray-500 mb-4">What would you like to do with the {{ count($selectedSiteIds) }} selected site(s)?</p>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <label class="relative cursor-pointer rounded-lg border-2 p-4 hover:bg-gray-50 {{ $operation === 'copy_from_site' ? 'border-purple-600 bg-purple-50' : 'border-gray-200' }}">
                    <input type="radio" wire:model.live="operation" value="copy_from_site" class="sr-only">
                    <div>
                        <p class="text-sm font-semibold text-gray-900">Copy from Site</p>
                        <p class="mt-1 text-xs text-gray-500">Copy security, tweaks, or module settings from an existing site.</p>
                    </div>
                </label>

                <label class="relative cursor-pointer rounded-lg border-2 p-4 hover:bg-gray-50 {{ $operation === 'security_preset' ? 'border-purple-600 bg-purple-50' : 'border-gray-200' }}">
                    <input type="radio" wire:model.live="operation" value="security_preset" class="sr-only">
                    <div>
                        <p class="text-sm font-semibold text-gray-900">Security Preset</p>
                        <p class="mt-1 text-xs text-gray-500">Apply a predefined security hardening preset.</p>
                    </div>
                </label>

                <label class="relative cursor-pointer rounded-lg border-2 p-4 hover:bg-gray-50 {{ $operation === 'module_preset' ? 'border-purple-600 bg-purple-50' : 'border-gray-200' }}">
                    <input type="radio" wire:model.live="operation" value="module_preset" class="sr-only">
                    <div>
                        <p class="text-sm font-semibold text-gray-900">Module Preset</p>
                        <p class="mt-1 text-xs text-gray-500">Apply a site module preset (uptime, backups, etc.).</p>
                    </div>
                </label>
            </div>

            <div class="mt-4 flex justify-between">
                <x-ui.button variant="secondary" wire:click="goToStep(1)">Back</x-ui.button>
                <x-ui.button wire:click="goToStep(3)" {{ !$operation ? 'disabled' : '' }}>Next</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    {{-- Step 3: Configure & Apply --}}
    @if($step === 3)
        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900 mb-4">
                @if($operation === 'copy_from_site')
                    Copy from Site
                @elseif($operation === 'security_preset')
                    Apply Security Preset
                @elseif($operation === 'module_preset')
                    Apply Module Preset
                @endif
            </h3>

            @if($operation === 'copy_from_site')
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Source Site</label>
                        <x-ui.select wire:model="sourceSiteId">
                            <option value="">-- Select source site --</option>
                            @foreach($this->sourceSites as $site)
                                <option value="{{ $site->id }}">{{ $site->name }} ({{ $site->domain }})</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div>
                        <p class="text-sm font-medium text-gray-700 mb-2">What to copy:</p>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model="copySecuritySettings" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                Security Settings (hardening, .htaccess, login, captcha, IP management, activity log)
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model="copyTweakSettings" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                Tweak Settings (performance, site control, admin UX, content/media, email)
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" wire:model="copyModuleConfig" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                Module Configuration (uptime, backup, performance, security monitors)
                            </label>
                        </div>
                    </div>
                </div>
            @elseif($operation === 'security_preset')
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Security Preset</label>
                    <x-ui.select wire:model="securityPresetId">
                        <option value="">-- Select preset --</option>
                        @foreach($this->securityPresets as $preset)
                            <option value="{{ $preset->id }}">
                                {{ $preset->name }}{{ $preset->is_default ? ' (Default)' : '' }}
                            </option>
                        @endforeach
                    </x-ui.select>
                    @if($this->securityPresets->isEmpty())
                        <p class="mt-2 text-xs text-gray-400">No security presets found. Create one in Security &gt; Presets.</p>
                    @endif
                </div>
            @elseif($operation === 'module_preset')
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Module Preset</label>
                    <x-ui.select wire:model="modulePresetId">
                        <option value="">-- Select preset --</option>
                        @foreach($this->modulePresets as $preset)
                            <option value="{{ $preset->id }}">
                                {{ $preset->name }}{{ $preset->is_default ? ' (Default)' : '' }}
                            </option>
                        @endforeach
                    </x-ui.select>
                    @if($this->modulePresets->isEmpty())
                        <p class="mt-2 text-xs text-gray-400">No module presets found. Create one in Settings &gt; Site Presets.</p>
                    @endif
                </div>
            @endif

            <x-ui.alert variant="warning" class="mt-4">
                This will apply settings to <strong>{{ count($selectedSiteIds) }}</strong> site(s). Existing settings will be overwritten. Changes will be pushed to each site.
            </x-ui.alert>

            <div class="mt-4 flex justify-between">
                <x-ui.button variant="secondary" wire:click="goToStep(2)">Back</x-ui.button>
                <x-ui.button wire:click="apply" wire:loading.attr="disabled">
                    <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="apply" />
                    Apply to {{ count($selectedSiteIds) }} Site(s)
                </x-ui.button>
            </div>
        </x-ui.card>
    @endif
</div>
