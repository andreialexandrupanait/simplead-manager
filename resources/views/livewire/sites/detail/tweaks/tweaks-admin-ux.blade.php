<div>
    <x-ui.page-header title="{{ __('Admin UX') }}" subtitle="{{ __('Customize the WordPress admin experience') }}">
        <x-slot:actions>
            <x-ui.button variant="ghost" size="sm" x-on:click="$dispatch('open-modal-copy-settings')">
                {{ __('Copy to Sites') }}
            </x-ui.button>
            <x-ui.button variant="ghost" size="sm" wire:click="verifySettings" wire:loading.attr="disabled" wire:target="verifySettings">
                <x-ui.spinner size="sm" class="hidden" wire:loading.class.remove="hidden" wire:target="verifySettings" />
                {{ __('Verify') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.sites.detail.tweaks.partials.tweaks-tabs', ['site' => $site])

    <x-ui.flash-alert type="success" key="success" />
    <x-ui.flash-alert type="error" key="verify-error" />

    {{-- Admin Bar --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Admin Bar') }}</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Clean Admin Bar') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Remove unnecessary items from the WordPress admin bar.') }}</p>
                    @if($toggles['clean_admin_bar'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['clean_admin_bar'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['clean_admin_bar'] ?? false"
                    wire:click="toggleSetting('clean_admin_bar')"
                />
            </div>

            @if($toggles['clean_admin_bar'] ?? false)
                <div class="ml-6 space-y-2 bg-gray-50 rounded-lg p-4">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="adminBarRemoveWpLogo" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                        <span class="text-sm text-gray-700">{{ __('Remove WordPress logo') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="adminBarRemoveComments" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                        <span class="text-sm text-gray-700">{{ __('Remove comments link') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="adminBarRemoveNewContent" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                        <span class="text-sm text-gray-700">{{ __('Remove "+ New" menu') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="adminBarRemoveCustomize" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                        <span class="text-sm text-gray-700">{{ __('Remove Customize link') }}</span>
                    </label>
                </div>
            @endif

            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Hide Admin Bar on Frontend') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Hide the admin bar for specific user roles on the frontend.') }}</p>
                    @if($toggles['hide_admin_bar'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['hide_admin_bar'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['hide_admin_bar'] ?? false"
                    wire:click="toggleSetting('hide_admin_bar')"
                />
            </div>

            @if($toggles['hide_admin_bar'] ?? false)
                <div class="ml-6 bg-gray-50 rounded-lg p-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Hide for') }}</label>
                    <select wire:model.live="hideAdminBarFor" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500 max-w-xs">
                        <option value="all">{{ __('All users') }}</option>
                        <option value="non_admins">{{ __('Non-administrators') }}</option>
                        <option value="non_editors">{{ __('Non-editors and below') }}</option>
                    </select>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Admin Interface --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Admin Interface') }}</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ __('Hide Admin Notices') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Suppress all admin notices and nag messages.') }}</p>
                        @if($toggles['hide_admin_notices'] ?? false)
                            <x-security.setting-status :status="$settingStatuses['hide_admin_notices'] ?? null" />
                        @endif
                    </div>
                </div>
                <x-ui.toggle
                    :enabled="$toggles['hide_admin_notices'] ?? false"
                    wire:click="toggleSetting('hide_admin_notices')"
                />
            </div>

            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900">{{ __('Wider Admin Menu') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Make the admin sidebar menu wider for better readability.') }}</p>
                        @if($toggles['wider_admin_menu'] ?? false)
                            <x-security.setting-status :status="$settingStatuses['wider_admin_menu'] ?? null" />
                        @endif
                    </div>
                </div>
                <x-ui.toggle
                    :enabled="$toggles['wider_admin_menu'] ?? false"
                    wire:click="toggleSetting('wider_admin_menu')"
                />
            </div>

            {{-- Dashboard Widgets --}}
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Disable Dashboard Widgets') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Remove default dashboard widgets for a cleaner admin experience.') }}</p>
                    @if($toggles['disable_dashboard_widgets'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['disable_dashboard_widgets'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['disable_dashboard_widgets'] ?? false"
                    wire:click="toggleSetting('disable_dashboard_widgets')"
                />
            </div>

            @if($toggles['disable_dashboard_widgets'] ?? false)
                <div class="ml-6 space-y-2 bg-gray-50 rounded-lg p-4">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="dashboardRemoveWelcome" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                        <span class="text-sm text-gray-700">{{ __('Welcome panel') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="dashboardRemoveQuickPress" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                        <span class="text-sm text-gray-700">{{ __('Quick Draft') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="dashboardRemoveActivity" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                        <span class="text-sm text-gray-700">{{ __('Activity') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="dashboardRemovePrimary" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                        <span class="text-sm text-gray-700">{{ __('WordPress Events and News') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="dashboardRemoveEvents" class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                        <span class="text-sm text-gray-700">{{ __('At a Glance') }}</span>
                    </label>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Admin Menu --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Admin Menu') }}</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Admin Menu Organizer') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Hide specific items from the WordPress admin menu.') }}</p>
                    @if($toggles['admin_menu_organizer'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['admin_menu_organizer'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['admin_menu_organizer'] ?? false"
                    wire:click="toggleSetting('admin_menu_organizer')"
                />
            </div>

            @if($toggles['admin_menu_organizer'] ?? false)
                <div class="ml-6 space-y-2 bg-gray-50 rounded-lg p-4">
                    @php
                        $menuItems = [
                            'edit-comments.php' => __('Comments'),
                            'tools.php' => __('Tools'),
                            'upload.php' => __('Media'),
                            'edit.php' => __('Posts'),
                            'themes.php' => __('Appearance'),
                            'plugins.php' => __('Plugins'),
                            'users.php' => __('Users'),
                            'options-general.php' => __('Settings'),
                        ];
                    @endphp
                    @foreach($menuItems as $slug => $label)
                        <label class="flex items-center gap-2">
                            <input type="checkbox"
                                {{ in_array($slug, $hiddenMenuItems) ? 'checked' : '' }}
                                wire:click="toggleMenuItem('{{ $slug }}')"
                                class="rounded border-gray-300 text-accent-600 focus:ring-accent-500" />
                            <span class="text-sm text-gray-700">{{ __('Hide') }} {{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Custom CSS --}}
    <x-ui.card class="mb-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Custom CSS') }}</h3>
        <div class="space-y-3">
            {{-- Admin CSS --}}
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Custom Admin CSS') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Inject custom CSS into the WordPress admin area.') }}</p>
                    @if($toggles['custom_admin_css'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['custom_admin_css'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['custom_admin_css'] ?? false"
                    wire:click="toggleSetting('custom_admin_css')"
                />
            </div>

            @if($toggles['custom_admin_css'] ?? false)
                <div class="ml-6 bg-gray-50 rounded-lg p-4">
                    <textarea wire:model.live="customAdminCss" rows="6" class="block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" placeholder="/* Custom admin CSS */"></textarea>
                    <p class="mt-1 text-xs text-gray-400">{{ __('Maximum 10KB.') }}</p>
                </div>
            @endif

            {{-- Frontend CSS --}}
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Custom Frontend CSS') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Inject custom CSS into the site frontend (like Additional CSS in Customizer).') }}</p>
                    @if($toggles['custom_frontend_css'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['custom_frontend_css'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['custom_frontend_css'] ?? false"
                    wire:click="toggleSetting('custom_frontend_css')"
                />
            </div>

            @if($toggles['custom_frontend_css'] ?? false)
                <div class="ml-6 bg-gray-50 rounded-lg p-4">
                    <textarea wire:model.live="customFrontendCss" rows="6" class="block w-full rounded-md border-gray-300 font-mono text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" placeholder="/* Custom frontend CSS */"></textarea>
                    <p class="mt-1 text-xs text-gray-400">{{ __('Maximum 10KB.') }}</p>
                </div>
            @endif
        </div>
    </x-ui.card>

    {{-- Custom Footer --}}
    <x-ui.card>
        <h3 class="text-base font-semibold text-gray-900 mb-4">{{ __('Admin Footer') }}</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between rounded-lg border border-gray-100 p-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900">{{ __('Custom Admin Footer Text') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Replace the default WordPress admin footer text.') }}</p>
                    @if($toggles['custom_admin_footer'] ?? false)
                        <x-security.setting-status :status="$settingStatuses['custom_admin_footer'] ?? null" />
                    @endif
                </div>
                <x-ui.toggle
                    :enabled="$toggles['custom_admin_footer'] ?? false"
                    wire:click="toggleSetting('custom_admin_footer')"
                />
            </div>

            @if($toggles['custom_admin_footer'] ?? false)
                <div class="ml-6 bg-gray-50 rounded-lg p-4">
                    <input type="text" wire:model.live="customAdminFooterText" class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500" placeholder="{{ __('Managed by SimpleAd') }}" />
                </div>
            @endif
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

    <livewire:components.copy-settings-modal :source-site="$site" :show-security-option="false" :show-tweaks-option="true" :show-modules-option="false" />
</div>
