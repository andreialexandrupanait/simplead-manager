<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Tweaks;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SettingsService;
use App\Services\SiteTweaksSettingsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TweaksEmail extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public array $toggles = [];

    public array $settingStatuses = [];

    public bool $isDirty = false;

    // Custom email from config
    public string $emailFromName = '';

    public string $emailFromAddress = '';

    // Postmark per-site override (optional)
    public string $postmarkOverrideToken = '';

    public string $postmarkMessageStream = 'outbound';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadCurrentState();
    }

    #[Computed]
    public function hasGlobalPostmarkToken(): bool
    {
        $encrypted = app(SettingsService::class)->get('postmark_server_token');

        if (! $encrypted) {
            return false;
        }

        try {
            return decrypt($encrypted) !== '';
        } catch (DecryptException) {
            return false;
        }
    }

    protected function loadCurrentState(): void
    {
        $settings = app(SiteTweaksSettingsService::class)
            ->getSettingsForCategory($this->site, 'email');

        // Email logging (simple toggle)
        $this->toggles['email_logging'] = $settings->get('email_logging')?->is_enabled ?? false;
        $this->settingStatuses['email_logging'] = $settings->get('email_logging')?->status;

        // Custom email from
        $emailFrom = $settings->get('custom_email_from');
        if ($emailFrom && $emailFrom->is_enabled) {
            $this->toggles['custom_email_from'] = true;
            $config = $emailFrom->setting_value ?? [];
            $this->emailFromName = $config['from_name'] ?? '';
            $this->emailFromAddress = $config['from_email'] ?? '';
        } else {
            $this->toggles['custom_email_from'] = false;
        }
        $this->settingStatuses['custom_email_from'] = $emailFrom?->status;

        // Postmark config
        $postmark = $settings->get('postmark_config');
        if ($postmark && $postmark->is_enabled) {
            $this->toggles['postmark_config'] = true;
            $config = $postmark->setting_value ?? [];
            $this->postmarkOverrideToken = $config['override_token'] ?? '';
            $this->postmarkMessageStream = $config['message_stream'] ?? 'outbound';
        } else {
            $this->toggles['postmark_config'] = false;
        }
        $this->settingStatuses['postmark_config'] = $postmark?->status;
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

    /**
     * Resolve the Postmark server token: per-site override or global default.
     */
    protected function resolvePostmarkToken(): string
    {
        if ($this->postmarkOverrideToken !== '') {
            return $this->postmarkOverrideToken;
        }

        $encrypted = app(SettingsService::class)->get('postmark_server_token');
        if (! $encrypted) {
            return '';
        }

        try {
            return decrypt($encrypted);
        } catch (DecryptException) {
            return '';
        }
    }

    public function save(): void
    {
        $service = app(SiteTweaksSettingsService::class);
        $settings = [];

        // Email logging
        $settings['email_logging'] = [
            'enabled' => $this->toggles['email_logging'] ?? false,
            'value' => $this->toggles['email_logging'] ?? false,
        ];

        // Custom email from
        $settings['custom_email_from'] = [
            'enabled' => $this->toggles['custom_email_from'] ?? false,
            'value' => [
                'from_name' => $this->emailFromName,
                'from_email' => $this->emailFromAddress,
            ],
        ];

        // Postmark config — resolve final token (global or per-site override)
        $resolvedToken = $this->resolvePostmarkToken();
        $settings['postmark_config'] = [
            'enabled' => $this->toggles['postmark_config'] ?? false,
            'value' => [
                'server_token' => $resolvedToken,
                'message_stream' => $this->postmarkMessageStream,
                'override_token' => $this->postmarkOverrideToken,
            ],
        ];

        $service->applyMultiple($this->site, 'email', $settings);

        $this->isDirty = false;
        $this->loadCurrentState();

        session()->flash('success', __('Email settings saved. Changes will be applied shortly.'));
        $this->redirect(route('sites.tweaks.email', $this->site), navigate: false);
    }

    public function verifySettings(): void
    {
        try {
            $api = new \App\Services\WordPressApiService($this->site);
            $response = $api->request('GET', '/site-tweaks-state');

            if (! $response->successful()) {
                session()->flash('verify-error', 'Could not reach site (HTTP '.$response->status().')');
                $this->redirect(route('sites.tweaks.email', $this->site), navigate: false);

                return;
            }

            $data = $response->json();
            $emailVerified = $data['email']['verified'] ?? [];
            $emailSettings = $data['email']['settings'] ?? [];

            $settings = $this->site->securitySettings()
                ->where('category', 'email')
                ->where('is_enabled', true)
                ->get();

            $verified = 0;
            $mismatches = 0;
            $now = now();

            foreach ($settings as $setting) {
                $key = $setting->setting_key;
                $active = ! empty($emailVerified[$key]['active'])
                    || (empty($emailVerified) && ! empty($emailSettings[$key]));

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
            \Log::error('Verify email settings failed', ['site' => $this->site->id, 'error' => $e->getMessage()]);
            session()->flash('verify-error', 'Verification failed: '.$e->getMessage());
        }

        $this->loadCurrentState();
        $this->redirect(route('sites.tweaks.email', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.tweaks.tweaks-email')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Email',
            ]);
    }
}
