<div>
    <x-ui.page-header title="Performance" subtitle="Optimize WordPress performance settings">
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" wire:click="verifySettings" wire:loading.attr="disabled" wire:target="verifySettings">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="verifySettings" />
                Verify
            </x-ui.button>
            @unless($this->allRecommendedEnabled)
                <x-ui.button variant="secondary" size="sm" wire:click="enableRecommended">
                    Enable Recommended
                </x-ui.button>
            @endunless
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="verify-error" />

    {{-- Heartbeat Control --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Heartbeat Control</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">Enable Heartbeat Control</p>
                    <p class="text-xs text-gray-500">Control the WordPress heartbeat API to reduce server load.</p>
                    @if($toggles['heartbeat_control'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['heartbeat_control'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['heartbeat_control'] ?? false"
                    wire:click="toggleSetting('heartbeat_control')"
                />
            </div>

            @if($toggles['heartbeat_control'] ?? false)
                <div class="ml-4 space-y-3 border-l-2 border-purple-100 pl-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Frontend</label>
                        <select wire:model.live="heartbeatFrontend" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500">
                            <option value="disable">Disable</option>
                            <option value="throttle">Throttle</option>
                            <option value="default">Default</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Dashboard</label>
                        <select wire:model.live="heartbeatDashboard" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500">
                            <option value="default">Default</option>
                            <option value="throttle">Throttle</option>
                            <option value="disable">Disable</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Post Editor</label>
                        <select wire:model.live="heartbeatEditor" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500">
                            <option value="default">Default</option>
                            <option value="throttle">Throttle</option>
                            <option value="disable">Disable</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Interval (seconds)</label>
                        <input type="number" wire:model.live="heartbeatInterval" min="15" max="300" class="block w-32 rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500" />
                    </div>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Revisions Control --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Revisions Control</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">Limit Post Revisions</p>
                    <p class="text-xs text-gray-500">Control how many revisions WordPress keeps for each post.</p>
                    @if($toggles['revisions_control'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['revisions_control'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['revisions_control'] ?? false"
                    wire:click="toggleSetting('revisions_control')"
                />
            </div>

            @if($toggles['revisions_control'] ?? false)
                <div class="ml-4 border-l-2 border-purple-100 pl-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Maximum Revisions</label>
                    <input type="number" wire:model.live="revisionsLimit" min="0" max="100" class="block w-32 rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500" />
                    <p class="mt-1 text-xs text-gray-400">Set to 0 to disable revisions entirely.</p>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Image Upload Control --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Image Upload Control</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">Enable Image Optimization</p>
                    <p class="text-xs text-gray-500">Auto-resize uploaded images and control JPEG quality.</p>
                    @if($toggles['image_upload_control'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['image_upload_control'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['image_upload_control'] ?? false"
                    wire:click="toggleSetting('image_upload_control')"
                />
            </div>

            @if($toggles['image_upload_control'] ?? false)
                <div class="ml-4 space-y-3 border-l-2 border-purple-100 pl-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Max Width (px)</label>
                            <input type="number" wire:model.live="imageMaxWidth" min="100" max="10000" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Max Height (px)</label>
                            <input type="number" wire:model.live="imageMaxHeight" min="100" max="10000" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-purple-500 focus:ring-purple-500" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">JPEG Quality ({{ $jpegQuality }}%)</label>
                        <input type="range" wire:model.live="jpegQuality" min="10" max="100" step="1" class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-purple-600" />
                        <div class="flex justify-between text-xs text-gray-400 mt-1">
                            <span>10% (smallest)</span>
                            <span>100% (best quality)</span>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Disable Components --}}
    <x-ui.card>
        <h3 class="text-base font-semibold text-gray-900 mb-4">Disable Unnecessary Components</h3>
        <div class="space-y-3">
            @php
                $components = [
                    'disable_emojis' => ['label' => 'Disable Emojis', 'desc' => 'Remove WordPress emoji scripts and styles from the frontend.'],
                    'disable_dashicons' => ['label' => 'Disable Dashicons', 'desc' => 'Remove Dashicons CSS on the frontend for non-logged-in users.'],
                    'disable_jquery_migrate' => ['label' => 'Disable jQuery Migrate', 'desc' => 'Remove the jQuery Migrate script from the frontend.'],
                    'disable_generator_tag' => ['label' => 'Disable Generator Tag', 'desc' => 'Remove the WordPress version meta tag from the page source.'],
                    'disable_wlw_manifest' => ['label' => 'Disable WLW Manifest', 'desc' => 'Remove the Windows Live Writer manifest link.'],
                    'disable_rsd_link' => ['label' => 'Disable RSD Link', 'desc' => 'Remove the Really Simple Discovery link from the header.'],
                    'disable_shortlinks' => ['label' => 'Disable Shortlinks', 'desc' => 'Remove WordPress shortlink tags from the header.'],
                    'disable_lazy_load' => ['label' => 'Disable Native Lazy Load', 'desc' => 'Remove the native lazy loading attribute from images.'],
                    'disable_block_widgets' => ['label' => 'Disable Block Widgets', 'desc' => 'Restore classic widgets instead of block-based widgets.'],
                ];
            @endphp

            @foreach($components as $key => $info)
                <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900">{{ $info['label'] }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ $info['desc'] }}</p>
                            @if($toggles[$key] ?? false)
                                <x-security.setting-status :status="$settingStatuses[$key] ?? null" />
                            @endif
                        </div>
                    </div>
                    <x-ui.toggle
                        :enabled="$toggles[$key] ?? false"
                        wire:click="toggleSetting('{{ $key }}')"
                    />
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- Sticky Save Bar --}}
    @if($isDirty)
        <div class="sticky bottom-0 mt-6 -mx-6 -mb-6 rounded-b-lg border-t border-gray-200 bg-white px-6 py-4 flex items-center justify-between shadow-lg">
            <p class="text-sm text-gray-500">You have unsaved changes</p>
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                Save Changes
            </x-ui.button>
        </div>
    @endif
</div>
