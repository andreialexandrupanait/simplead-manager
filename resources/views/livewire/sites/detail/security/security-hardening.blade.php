<div>
    <x-ui.page-header title="{{ __('Hardening') }}" subtitle="{{ __('Harden WordPress core settings and server configuration') }}">
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" x-on:click="$dispatch('open-modal-copy-settings')">
                {{ __('Copy to Sites') }}
            </x-ui.button>
            <x-ui.button variant="ghost" size="sm" wire:click="verifySettings" wire:loading.attr="disabled" wire:target="verifySettings">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="verifySettings" />
                {{ __('Verify') }}
            </x-ui.button>
            @unless($this->allRecommendedEnabled)
                <x-ui.button variant="secondary" size="sm" wire:click="enableRecommended">
                    {{ __('Enable Recommended') }}
                </x-ui.button>
            @endunless
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="verify-error" />

    @if($this->site->is_multisite)
        <x-ui.alert variant="warning" class="mb-6">
            {{ __('This site is a WordPress Multisite. Some hardening settings may affect all sites in the network.') }}
        </x-ui.alert>
    @endif

    {{-- WordPress Hardening --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('WordPress Hardening') }}</h3>
        <div class="space-y-3">
            @php
                $wpSettings = [
                    'disable_theme_editor' => ['label' => __('Disable Theme/Plugin Editor'), 'desc' => __('Prevents editing PHP files from the WordPress admin dashboard.')],
                    'disable_user_enumeration' => ['label' => __('Disable User Enumeration'), 'desc' => __('Blocks ?author=N and REST API user listing attacks.')],
                    'hide_wp_version' => ['label' => __('Hide WordPress Version'), 'desc' => __('Removes the WordPress version from page source and feeds.')],
                    'restrict_xmlrpc' => ['label' => __('Restrict XML-RPC'), 'desc' => __('Disables XML-RPC to prevent brute force and DDoS amplification attacks.')],
                    'security_headers' => ['label' => __('Security Headers'), 'desc' => __('Adds X-Content-Type-Options, X-Frame-Options, and Referrer-Policy headers.')],
                    'block_application_passwords' => ['label' => __('Block Application Passwords'), 'desc' => __('Disables the WordPress Application Passwords feature.')],
                    'restrict_rest_api' => ['label' => __('Restrict REST API'), 'desc' => __('Limits REST API access to authenticated users only.')],
                ];
            @endphp

            @foreach($wpSettings as $key => $info)
                <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900">{{ $info['label'] }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ $info['desc'] }}</p>
                            @if($hardeningToggles[$key] ?? false)
                                <x-security.setting-status :status="$settingStatuses[$key] ?? null" />
                            @endif
                        </div>
                    </div>
                    <x-ui.toggle
                        :enabled="$hardeningToggles[$key] ?? false"
                        wire:click="toggleSetting('hardening', '{{ $key }}')"
                    />
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- .htaccess Rules --}}
    <x-ui.card>
        <h3 class="text-base font-semibold text-gray-900 mb-4">.htaccess Rules</h3>
        <div class="space-y-3">
            @php
                $htaccessSettings = [
                    'block_default_files' => ['label' => __('Block Default Files'), 'desc' => __('Prevents access to wp-config.php, install.php, and other sensitive files.')],
                    'block_readme_access' => ['label' => __('Block Readme Access'), 'desc' => __('Blocks access to readme.html and license.txt files.')],
                    'block_debug_log' => ['label' => __('Block Debug Log'), 'desc' => __('Prevents direct access to debug.log file.')],
                    'disable_directory_listing' => ['label' => __('Disable Directory Listing'), 'desc' => __('Prevents directory browsing when no index file exists.')],
                    'firewall_enabled' => ['label' => __('Basic Firewall'), 'desc' => __('Enables server-level request filtering for common attack patterns.')],
                ];
            @endphp

            @foreach($htaccessSettings as $key => $info)
                <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900">{{ $info['label'] }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ $info['desc'] }}</p>
                            @if($htaccessToggles[$key] ?? false)
                                <x-security.setting-status :status="$settingStatuses[$key] ?? null" />
                            @endif
                        </div>
                    </div>
                    <x-ui.toggle
                        :enabled="$htaccessToggles[$key] ?? false"
                        wire:click="toggleSetting('htaccess', '{{ $key }}')"
                    />
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- Sticky Save Bar --}}
    @if($isDirty)
        <div class="sticky bottom-0 mt-6 -mx-6 -mb-6 rounded-b-lg border-t border-gray-200 bg-white px-6 py-4 flex items-center justify-between shadow-lg">
            <p class="text-sm text-gray-500">{{ __('You have unsaved changes') }}</p>
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                {{ __('Save Changes') }}
            </x-ui.button>
        </div>
    @endif

    <livewire:components.copy-settings-modal :source-site="$site" :show-security-option="true" :show-tweaks-option="false" :show-modules-option="false" />
</div>
