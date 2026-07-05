<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Tweaks;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SiteTweaksSettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TweaksPerformance extends Component
{
    use WithSiteAuthorization;

    public const RECOMMENDED_TOGGLES = [
        'disable_emojis',
        'disable_dashicons',
        'disable_jquery_migrate',
        'disable_generator_tag',
        'disable_wlw_manifest',
        'disable_rsd_link',
        'disable_shortlinks',
    ];

    public Site $site;

    // Simple toggles
    public array $toggles = [];

    // Heartbeat config
    public string $heartbeatFrontend = 'disable';

    public string $heartbeatDashboard = 'default';

    public string $heartbeatEditor = 'default';

    public int $heartbeatInterval = 60;

    // Revisions config
    public int $revisionsLimit = 5;

    // Image config
    public int $imageMaxWidth = 2560;

    public int $imageMaxHeight = 2560;

    public int $jpegQuality = 82;

    // WooCommerce config
    public bool $wooDisableCartFragments = true;

    public bool $wooDisableScriptsNonWc = true;

    public array $settingStatuses = [];

    public bool $isDirty = false;

    protected array $simpleToggleKeys = [
        'disable_generator_tag',
        'disable_wlw_manifest',
        'disable_rsd_link',
        'disable_shortlinks',
        'disable_emojis',
        'disable_dashicons',
        'disable_jquery_migrate',
        'disable_lazy_load',
        'disable_block_widgets',
        'disable_self_pingbacks',
        'disable_rest_api_links',
        'disable_dns_prefetch',
        'disable_xml_sitemap',
        'disable_google_fonts',
        'disable_global_styles',
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
            ->getSettingsForCategory($this->site, 'performance');

        // Simple toggles
        foreach ($this->simpleToggleKeys as $key) {
            $this->toggles[$key] = $settings->get($key)?->is_enabled ?? false;
            $this->settingStatuses[$key] = $settings->get($key)?->status;
        }

        // Heartbeat
        $heartbeat = $settings->get('heartbeat_control');
        if ($heartbeat && $heartbeat->is_enabled) {
            $this->toggles['heartbeat_control'] = true;
            $config = $heartbeat->setting_value ?? [];
            $this->heartbeatFrontend = $config['frontend'] ?? 'disable';
            $this->heartbeatDashboard = $config['dashboard'] ?? 'default';
            $this->heartbeatEditor = $config['editor'] ?? 'default';
            $this->heartbeatInterval = $config['interval'] ?? 60;
        } else {
            $this->toggles['heartbeat_control'] = false;
        }
        $this->settingStatuses['heartbeat_control'] = $heartbeat?->status;

        // Revisions
        $revisions = $settings->get('revisions_control');
        if ($revisions && $revisions->is_enabled) {
            $this->toggles['revisions_control'] = true;
            $config = $revisions->setting_value ?? [];
            $this->revisionsLimit = $config['limit'] ?? 5;
        } else {
            $this->toggles['revisions_control'] = false;
        }
        $this->settingStatuses['revisions_control'] = $revisions?->status;

        // Image upload
        $image = $settings->get('image_upload_control');
        if ($image && $image->is_enabled) {
            $this->toggles['image_upload_control'] = true;
            $config = $image->setting_value ?? [];
            $this->imageMaxWidth = $config['max_width'] ?? 2560;
            $this->imageMaxHeight = $config['max_height'] ?? 2560;
            $this->jpegQuality = $config['jpeg_quality'] ?? 82;
        } else {
            $this->toggles['image_upload_control'] = false;
        }
        $this->settingStatuses['image_upload_control'] = $image?->status;

        // WooCommerce optimization
        $woo = $settings->get('optimize_woocommerce');
        if ($woo && $woo->is_enabled) {
            $this->toggles['optimize_woocommerce'] = true;
            $config = $woo->setting_value ?? [];
            $this->wooDisableCartFragments = $config['disable_cart_fragments'] ?? true;
            $this->wooDisableScriptsNonWc = $config['disable_wc_scripts_non_wc'] ?? true;
        } else {
            $this->toggles['optimize_woocommerce'] = false;
        }
        $this->settingStatuses['optimize_woocommerce'] = $woo?->status;
    }

    public function enableRecommended(): void
    {
        foreach (self::RECOMMENDED_TOGGLES as $key) {
            $this->toggles[$key] = true;
        }
        $this->isDirty = true;
    }

    #[Computed]
    public function allRecommendedEnabled(): bool
    {
        foreach (self::RECOMMENDED_TOGGLES as $key) {
            if (! ($this->toggles[$key] ?? false)) {
                return false;
            }
        }

        return true;
    }

    public function toggleSetting(string $key): void
    {
        if (array_key_exists($key, $this->toggles)) {
            $this->toggles[$key] = ! $this->toggles[$key];
            $this->isDirty = true;
        }
    }

    public function updated($property): void
    {
        $this->isDirty = true;
    }

    public function verifySettings(): void
    {
        try {
            $api = app(\App\Services\WordPressApiServiceFactory::class)->make($this->site);
            $response = $api->request('GET', '/site-tweaks-state');

            if (! $response->successful()) {
                session()->flash('verify-error', 'Could not reach site (HTTP '.$response->status().')');
                $this->redirect(route('sites.tweaks.performance', $this->site), navigate: false);

                return;
            }

            $data = $response->json();
            $perfVerified = $data['performance']['verified'] ?? [];
            $perfSettings = $data['performance']['settings'] ?? [];

            $settings = $this->site->securitySettings()
                ->where('category', 'performance')
                ->where('is_enabled', true)
                ->get();

            $verified = 0;
            $mismatches = 0;
            $now = now();

            foreach ($settings as $setting) {
                /** @var \App\Models\SecuritySetting $setting */
                $key = $setting->setting_key;
                $active = ! empty($perfVerified[$key]['active'])
                    || (empty($perfVerified) && ! empty($perfSettings[$key]));

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
            \Log::error('Verify tweaks settings failed', ['site' => $this->site->id, 'error' => $e->getMessage()]);
            session()->flash('verify-error', 'Verification failed: '.$e->getMessage());
        }

        $this->loadCurrentState();
        $this->redirect(route('sites.tweaks.performance', $this->site), navigate: false);
    }

    public function save(): void
    {
        $service = app(SiteTweaksSettingsService::class);
        $settings = [];

        // Simple toggles
        foreach ($this->simpleToggleKeys as $key) {
            $enabled = $this->toggles[$key] ?? false;
            $settings[$key] = ['enabled' => $enabled, 'value' => $enabled];
        }

        // Heartbeat
        $settings['heartbeat_control'] = [
            'enabled' => $this->toggles['heartbeat_control'] ?? false,
            'value' => [
                'frontend' => $this->heartbeatFrontend,
                'dashboard' => $this->heartbeatDashboard,
                'editor' => $this->heartbeatEditor,
                'interval' => max(15, $this->heartbeatInterval),
            ],
        ];

        // Revisions
        $settings['revisions_control'] = [
            'enabled' => $this->toggles['revisions_control'] ?? false,
            'value' => ['limit' => max(0, $this->revisionsLimit)],
        ];

        // Image upload
        $settings['image_upload_control'] = [
            'enabled' => $this->toggles['image_upload_control'] ?? false,
            'value' => [
                'max_width' => max(100, $this->imageMaxWidth),
                'max_height' => max(100, $this->imageMaxHeight),
                'jpeg_quality' => max(10, min(100, $this->jpegQuality)),
            ],
        ];

        // WooCommerce optimization
        $settings['optimize_woocommerce'] = [
            'enabled' => $this->toggles['optimize_woocommerce'] ?? false,
            'value' => [
                'disable_cart_fragments' => $this->wooDisableCartFragments,
                'disable_wc_scripts_non_wc' => $this->wooDisableScriptsNonWc,
            ],
        ];

        $service->applyMultiple($this->site, 'performance', $settings);

        $this->isDirty = false;
        $this->loadCurrentState();

        session()->flash('success', 'Performance settings saved. Changes will be applied shortly.');
        $this->redirect(route('sites.tweaks.performance', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.tweaks.tweaks-performance')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Performance Tweaks',
            ]);
    }
}
