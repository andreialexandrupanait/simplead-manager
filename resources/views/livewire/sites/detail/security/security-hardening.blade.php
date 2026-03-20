<div>
    @include('livewire.sites.detail.security.partials.security-tabs', ['site' => $site])

    {{-- Flash Messages --}}
    <x-ui.flash-alert type="success" key="success" />

    @if($this->site->is_multisite)
        <x-ui.alert variant="warning" class="mb-6">
            This site is a WordPress Multisite. Some hardening settings may affect all sites in the network.
        </x-ui.alert>
    @endif

    {{-- WordPress Hardening --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">WordPress Hardening</h3>
        <div class="space-y-3">
            @php
                $wpSettings = [
                    'disable_theme_editor' => ['label' => 'Disable Theme/Plugin Editor', 'desc' => 'Prevents editing PHP files from the WordPress admin dashboard.'],
                    'disable_user_enumeration' => ['label' => 'Disable User Enumeration', 'desc' => 'Blocks ?author=N and REST API user listing attacks.'],
                    'hide_wp_version' => ['label' => 'Hide WordPress Version', 'desc' => 'Removes the WordPress version from page source and feeds.'],
                    'restrict_xmlrpc' => ['label' => 'Restrict XML-RPC', 'desc' => 'Disables XML-RPC to prevent brute force and DDoS amplification attacks.'],
                    'security_headers' => ['label' => 'Security Headers', 'desc' => 'Adds X-Content-Type-Options, X-Frame-Options, and Referrer-Policy headers.'],
                    'block_application_passwords' => ['label' => 'Block Application Passwords', 'desc' => 'Disables the WordPress Application Passwords feature.'],
                    'restrict_rest_api' => ['label' => 'Restrict REST API', 'desc' => 'Limits REST API access to authenticated users only.'],
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
                    'block_default_files' => ['label' => 'Block Default Files', 'desc' => 'Prevents access to wp-config.php, install.php, and other sensitive files.'],
                    'block_readme_access' => ['label' => 'Block Readme Access', 'desc' => 'Blocks access to readme.html and license.txt files.'],
                    'block_debug_log' => ['label' => 'Block Debug Log', 'desc' => 'Prevents direct access to debug.log file.'],
                    'disable_directory_listing' => ['label' => 'Disable Directory Listing', 'desc' => 'Prevents directory browsing when no index file exists.'],
                    'firewall_enabled' => ['label' => 'Basic Firewall', 'desc' => 'Enables server-level request filtering for common attack patterns.'],
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
            <p class="text-sm text-gray-500">You have unsaved changes</p>
            <x-ui.button wire:click="save" wire:loading.attr="disabled">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="save" />
                Save Changes
            </x-ui.button>
        </div>
    @endif
</div>
