<?php

namespace App\Livewire\Sites\Detail\Tweaks;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SiteTweaksSettingsService;
use Livewire\Component;

class TweaksPerformance extends Component
{
    use WithSiteAuthorization;

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

        // Revisions
        $revisions = $settings->get('revisions_control');
        if ($revisions && $revisions->is_enabled) {
            $this->toggles['revisions_control'] = true;
            $config = $revisions->setting_value ?? [];
            $this->revisionsLimit = $config['limit'] ?? 5;
        } else {
            $this->toggles['revisions_control'] = false;
        }

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
    }

    public function toggleSetting(string $key): void
    {
        if (array_key_exists($key, $this->toggles)) {
            $this->toggles[$key] = !$this->toggles[$key];
            $this->isDirty = true;
        }
    }

    public function updated($property): void
    {
        $this->isDirty = true;
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

        $service->applyMultiple($this->site, 'performance', $settings);

        $this->isDirty = false;
        $this->loadCurrentState();

        session()->flash('success', 'Performance settings saved. Changes will be applied shortly.');
        $this->redirect(route('sites.security.performance', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.tweaks.tweaks-performance')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Performance Tweaks',
            ]);
    }
}
