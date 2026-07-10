<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Tweaks;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SiteTweaksSettingsService;
use Livewire\Component;

class TweaksAdminUx extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public array $toggles = [];

    public array $settingStatuses = [];

    public bool $isDirty = false;

    // Clean admin bar config
    public bool $adminBarRemoveWpLogo = true;

    public bool $adminBarRemoveComments = true;

    public bool $adminBarRemoveNewContent = false;

    public bool $adminBarRemoveCustomize = true;

    // Dashboard widgets config
    public bool $dashboardRemoveWelcome = true;

    public bool $dashboardRemoveQuickPress = false;

    public bool $dashboardRemoveActivity = false;

    public bool $dashboardRemovePrimary = true;

    public bool $dashboardRemoveEvents = true;

    // Custom CSS
    public string $customAdminCss = '';

    public string $customFrontendCss = '';

    // Hide admin bar config
    public string $hideAdminBarFor = 'non_admins';

    // Admin menu organizer
    public array $hiddenMenuItems = [];

    // Custom admin footer
    public string $customAdminFooterText = '';

    protected array $simpleToggleKeys = [
        'hide_admin_notices',
        'wider_admin_menu',
    ];

    protected array $availableMenuItems = [
        'edit-comments.php' => 'Comments',
        'tools.php' => 'Tools',
        'upload.php' => 'Media',
        'edit.php' => 'Posts',
        'themes.php' => 'Appearance',
        'plugins.php' => 'Plugins',
        'users.php' => 'Users',
        'options-general.php' => 'Settings',
    ];

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadCurrentState();
    }

    protected function loadCurrentState(): void
    {
        $settings = app(SiteTweaksSettingsService::class)
            ->getSettingsForCategory($this->site, 'admin_ux');

        // Simple toggles
        foreach ($this->simpleToggleKeys as $key) {
            $this->toggles[$key] = $settings->get($key)?->is_enabled ?? false;
            $this->settingStatuses[$key] = $settings->get($key)?->status;
        }

        // Clean admin bar
        $adminBar = $settings->get('clean_admin_bar');
        if ($adminBar && $adminBar->is_enabled) {
            $this->toggles['clean_admin_bar'] = true;
            $config = $adminBar->setting_value ?? [];
            $this->adminBarRemoveWpLogo = $config['remove_wp_logo'] ?? true;
            $this->adminBarRemoveComments = $config['remove_comments'] ?? true;
            $this->adminBarRemoveNewContent = $config['remove_new_content'] ?? false;
            $this->adminBarRemoveCustomize = $config['remove_customize'] ?? true;
        } else {
            $this->toggles['clean_admin_bar'] = false;
        }
        $this->settingStatuses['clean_admin_bar'] = $adminBar?->status;

        // Dashboard widgets
        $dashWidgets = $settings->get('disable_dashboard_widgets');
        if ($dashWidgets && $dashWidgets->is_enabled) {
            $this->toggles['disable_dashboard_widgets'] = true;
            $config = $dashWidgets->setting_value ?? [];
            $this->dashboardRemoveWelcome = $config['remove_welcome'] ?? true;
            $this->dashboardRemoveQuickPress = $config['remove_quick_press'] ?? false;
            $this->dashboardRemoveActivity = $config['remove_activity'] ?? false;
            $this->dashboardRemovePrimary = $config['remove_primary'] ?? true;
            $this->dashboardRemoveEvents = $config['remove_events'] ?? true;
        } else {
            $this->toggles['disable_dashboard_widgets'] = false;
        }
        $this->settingStatuses['disable_dashboard_widgets'] = $dashWidgets?->status;

        // Custom admin CSS
        $adminCss = $settings->get('custom_admin_css');
        if ($adminCss && $adminCss->is_enabled) {
            $this->toggles['custom_admin_css'] = true;
            $config = $adminCss->setting_value ?? [];
            $this->customAdminCss = $config['css'] ?? '';
        } else {
            $this->toggles['custom_admin_css'] = false;
        }
        $this->settingStatuses['custom_admin_css'] = $adminCss?->status;

        // Custom frontend CSS
        $frontendCss = $settings->get('custom_frontend_css');
        if ($frontendCss && $frontendCss->is_enabled) {
            $this->toggles['custom_frontend_css'] = true;
            $config = $frontendCss->setting_value ?? [];
            $this->customFrontendCss = $config['css'] ?? '';
        } else {
            $this->toggles['custom_frontend_css'] = false;
        }
        $this->settingStatuses['custom_frontend_css'] = $frontendCss?->status;

        // Hide admin bar
        $hideBar = $settings->get('hide_admin_bar');
        if ($hideBar && $hideBar->is_enabled) {
            $this->toggles['hide_admin_bar'] = true;
            $config = $hideBar->setting_value ?? [];
            $this->hideAdminBarFor = $config['hide_for'] ?? 'non_admins';
        } else {
            $this->toggles['hide_admin_bar'] = false;
        }
        $this->settingStatuses['hide_admin_bar'] = $hideBar?->status;

        // Admin menu organizer
        $menuOrg = $settings->get('admin_menu_organizer');
        if ($menuOrg && $menuOrg->is_enabled) {
            $this->toggles['admin_menu_organizer'] = true;
            $config = $menuOrg->setting_value ?? [];
            $this->hiddenMenuItems = $config['hidden_items'] ?? [];
        } else {
            $this->toggles['admin_menu_organizer'] = false;
        }
        $this->settingStatuses['admin_menu_organizer'] = $menuOrg?->status;

        // Custom admin footer
        $footer = $settings->get('custom_admin_footer');
        if ($footer && $footer->is_enabled) {
            $this->toggles['custom_admin_footer'] = true;
            $config = $footer->setting_value ?? [];
            $this->customAdminFooterText = $config['text'] ?? '';
        } else {
            $this->toggles['custom_admin_footer'] = false;
        }
        $this->settingStatuses['custom_admin_footer'] = $footer?->status;
    }

    public function toggleSetting(string $key): void
    {
        if (array_key_exists($key, $this->toggles)) {
            $this->toggles[$key] = ! $this->toggles[$key];
            $this->isDirty = true;
        }
    }

    public function toggleMenuItem(string $item): void
    {
        if (in_array($item, $this->hiddenMenuItems, true)) {
            $this->hiddenMenuItems = array_values(array_diff($this->hiddenMenuItems, [$item]));
        } else {
            $this->hiddenMenuItems[] = $item;
        }
        $this->isDirty = true;
    }

    public function updated($property): void
    {
        $this->isDirty = true;
    }

    public function save(): void
    {
        $this->authorizeSiteModification($this->site);
        $service = app(SiteTweaksSettingsService::class);
        $settings = [];

        // Simple toggles
        foreach ($this->simpleToggleKeys as $key) {
            $enabled = $this->toggles[$key] ?? false;
            $settings[$key] = ['enabled' => $enabled, 'value' => $enabled];
        }

        // Clean admin bar
        $settings['clean_admin_bar'] = [
            'enabled' => $this->toggles['clean_admin_bar'] ?? false,
            'value' => [
                'remove_wp_logo' => $this->adminBarRemoveWpLogo,
                'remove_comments' => $this->adminBarRemoveComments,
                'remove_new_content' => $this->adminBarRemoveNewContent,
                'remove_customize' => $this->adminBarRemoveCustomize,
            ],
        ];

        // Dashboard widgets
        $settings['disable_dashboard_widgets'] = [
            'enabled' => $this->toggles['disable_dashboard_widgets'] ?? false,
            'value' => [
                'remove_welcome' => $this->dashboardRemoveWelcome,
                'remove_quick_press' => $this->dashboardRemoveQuickPress,
                'remove_activity' => $this->dashboardRemoveActivity,
                'remove_primary' => $this->dashboardRemovePrimary,
                'remove_events' => $this->dashboardRemoveEvents,
            ],
        ];

        // Custom admin CSS (max 10KB)
        $settings['custom_admin_css'] = [
            'enabled' => $this->toggles['custom_admin_css'] ?? false,
            'value' => ['css' => mb_substr($this->customAdminCss, 0, 10240)],
        ];

        // Custom frontend CSS (max 10KB)
        $settings['custom_frontend_css'] = [
            'enabled' => $this->toggles['custom_frontend_css'] ?? false,
            'value' => ['css' => mb_substr($this->customFrontendCss, 0, 10240)],
        ];

        // Hide admin bar
        $settings['hide_admin_bar'] = [
            'enabled' => $this->toggles['hide_admin_bar'] ?? false,
            'value' => ['hide_for' => $this->hideAdminBarFor],
        ];

        // Admin menu organizer
        $settings['admin_menu_organizer'] = [
            'enabled' => $this->toggles['admin_menu_organizer'] ?? false,
            'value' => ['hidden_items' => $this->hiddenMenuItems],
        ];

        // Custom admin footer
        $settings['custom_admin_footer'] = [
            'enabled' => $this->toggles['custom_admin_footer'] ?? false,
            'value' => ['text' => mb_substr($this->customAdminFooterText, 0, 500)],
        ];

        $service->applyMultiple($this->site, 'admin_ux', $settings);

        $this->isDirty = false;
        $this->loadCurrentState();

        session()->flash('success', __('Admin UX settings saved. Changes will be applied shortly.'));
        $this->redirect(route('sites.tweaks.admin-ux', $this->site), navigate: false);
    }

    public function verifySettings(): void
    {
        $this->authorizeSiteModification($this->site);
        try {
            $api = app(\App\Services\WordPressApiServiceFactory::class)->make($this->site);
            $response = $api->request('GET', '/site-tweaks-state');

            if (! $response->successful()) {
                session()->flash('verify-error', 'Could not reach site (HTTP '.$response->status().')');
                $this->redirect(route('sites.tweaks.admin-ux', $this->site), navigate: false);

                return;
            }

            $data = $response->json();
            $auxVerified = $data['admin_ux']['verified'] ?? [];
            $auxSettings = $data['admin_ux']['settings'] ?? [];

            $settings = $this->site->securitySettings()
                ->where('category', 'admin_ux')
                ->where('is_enabled', true)
                ->get();

            $verified = 0;
            $mismatches = 0;
            $now = now();

            foreach ($settings as $setting) {
                $key = $setting->setting_key;
                $active = ! empty($auxVerified[$key]['active'])
                    || (empty($auxVerified) && ! empty($auxSettings[$key]));

                if ($active) {
                    $setting->update(['applied_at' => $now, 'failed_at' => null, 'failure_reason' => null]);
                    $verified++;
                } else {
                    $setting->update(['failed_at' => $now, 'failure_reason' => 'Not active on WordPress']);
                    $mismatches++;
                }
            }

            if ($mismatches > 0) {
                app(SiteTweaksSettingsService::class)->pushToPlugin($this->site);
                session()->flash('success', "{$verified} verified, {$mismatches} mismatches — re-push triggered.");
            } else {
                session()->flash('success', "All {$verified} settings verified on WordPress.");
            }
        } catch (\Exception $e) {
            \Log::error('Verify admin UX settings failed', ['site' => $this->site->id, 'error' => $e->getMessage()]);
            session()->flash('verify-error', 'Verification failed: '.$e->getMessage());
        }

        $this->loadCurrentState();
        $this->redirect(route('sites.tweaks.admin-ux', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.tweaks.tweaks-admin-ux')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Admin UX',
            ]);
    }
}
