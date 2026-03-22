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

    public array $settingStatuses = [];

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
            $this->settingStatuses[$key] = $settings->get($key)?->status;
        }
    }

    public function toggleSetting(string $key): void
    {
        if (array_key_exists($key, $this->toggles)) {
            $this->toggles[$key] = ! $this->toggles[$key];
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

    public function verifySettings(): void
    {
        try {
            $api = new \App\Services\WordPressApiService($this->site);
            $response = $api->request('GET', '/site-tweaks-state');

            if (! $response->successful()) {
                session()->flash('verify-error', 'Could not reach site (HTTP '.$response->status().')');
                $this->redirect(route('sites.security.site-control', $this->site), navigate: false);

                return;
            }

            $data = $response->json();
            $scVerified = $data['site_control']['verified'] ?? [];
            $scSettings = $data['site_control']['settings'] ?? [];

            $settings = $this->site->securitySettings()
                ->where('category', 'site_control')
                ->where('is_enabled', true)
                ->get();

            $verified = 0;
            $mismatches = 0;
            $now = now();

            foreach ($settings as $setting) {
                $key = $setting->setting_key;
                $active = ! empty($scVerified[$key]['active'])
                    || (empty($scVerified) && ! empty($scSettings[$key]));

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
            \Log::error('Verify site control settings failed', ['site' => $this->site->id, 'error' => $e->getMessage()]);
            session()->flash('verify-error', 'Verification failed: '.$e->getMessage());
        }

        $this->loadCurrentState();
        $this->redirect(route('sites.security.site-control', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.tweaks.tweaks-site-control')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Site Control',
            ]);
    }
}
