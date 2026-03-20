<?php

namespace App\Livewire\Sites\Detail\Tweaks;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SiteTweaksSettingsService;
use Livewire\Component;

class TweaksSiteControl extends Component
{
    use WithSiteAuthorization;

    public Site $site;
    public array $toggles = [];
    public bool $isDirty = false;

    protected array $settingKeys = [
        'disable_all_updates',
        'disable_comments',
        'disable_feeds',
        'disable_embeds',
        'redirect_404',
        'disable_gutenberg',
        'disable_author_archives',
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
            ->getSettingsForCategory($this->site, 'site_control');

        foreach ($this->settingKeys as $key) {
            $this->toggles[$key] = $settings->get($key)?->is_enabled ?? false;
        }
    }

    public function toggleSetting(string $key): void
    {
        if (array_key_exists($key, $this->toggles)) {
            $this->toggles[$key] = !$this->toggles[$key];
            $this->isDirty = true;
        }
    }

    public function save(): void
    {
        $service = app(SiteTweaksSettingsService::class);
        $settings = [];

        foreach ($this->settingKeys as $key) {
            $enabled = $this->toggles[$key] ?? false;
            $settings[$key] = ['enabled' => $enabled, 'value' => $enabled];
        }

        $service->applyMultiple($this->site, 'site_control', $settings);

        $this->isDirty = false;
        $this->loadCurrentState();

        session()->flash('success', 'Site control settings saved. Changes will be applied shortly.');
        $this->redirect(route('sites.security.site-control', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.tweaks.tweaks-site-control')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Site Control',
            ]);
    }
}
