<?php

namespace App\Jobs;

use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use App\Services\WordPressApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VerifySecurityState implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('security');
    }

    public function handle(): void
    {
        try {
            $api = new WordPressApiService($this->site);
            $state = $api->getSecurityState();
        } catch (\Exception $e) {
            Log::warning('VerifySecurityState: failed to fetch state', [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $reported = [];
        $now = now();

        // Verify hardening settings
        if (isset($state['hardening']['state'])) {
            $verifiedState = $state['hardening']['state'];
            foreach ($verifiedState as $key => $isActive) {
                $reported[] = [
                    'category' => 'hardening',
                    'key' => $key,
                    'applied' => (bool) $isActive,
                    'failed' => !$isActive,
                    'reason' => $isActive ? null : 'Setting not active on WordPress',
                ];
            }
        }

        // Verify htaccess settings
        if (isset($state['htaccess']['active_sections'])) {
            $activeSections = $state['htaccess']['active_sections'];
            $htaccessSettings = SecuritySetting::where('site_id', $this->site->id)
                ->where('category', 'htaccess')
                ->where('is_enabled', true)
                ->pluck('setting_key')
                ->toArray();

            foreach ($htaccessSettings as $key) {
                $isActive = in_array($key, $activeSections);
                $reported[] = [
                    'category' => 'htaccess',
                    'key' => $key,
                    'applied' => $isActive,
                    'failed' => !$isActive,
                    'reason' => $isActive ? null : 'Htaccess rule not active',
                ];
            }
        }

        // Verify login settings
        if (isset($state['login']['settings'])) {
            $loginState = $state['login']['settings'];
            $loginSettings = SecuritySetting::where('site_id', $this->site->id)
                ->where('category', 'login')
                ->where('is_enabled', true)
                ->get();

            foreach ($loginSettings as $setting) {
                $key = $setting->setting_key;
                $isActive = isset($loginState[$key]['enabled']) && $loginState[$key]['enabled'];
                $reported[] = [
                    'category' => 'login',
                    'key' => $key,
                    'applied' => $isActive,
                    'failed' => !$isActive,
                    'reason' => $isActive ? null : 'Login setting not active on WordPress',
                ];
            }
        }

        // Verify captcha
        if (isset($state['captcha'])) {
            $captchaState = $state['captcha'];
            $isActive = !empty($captchaState['enabled']);
            $captchaSetting = SecuritySetting::where('site_id', $this->site->id)
                ->where('category', 'captcha')
                ->where('is_enabled', true)
                ->first();

            if ($captchaSetting) {
                $reported[] = [
                    'category' => 'captcha',
                    'key' => $captchaSetting->setting_key,
                    'applied' => $isActive,
                    'failed' => !$isActive,
                    'reason' => $isActive ? null : 'CAPTCHA not active on WordPress',
                ];
            }
        }

        // Verify IP management
        if (isset($state['ip_management'])) {
            $ipState = $state['ip_management'];
            $isActive = !empty($ipState['enabled']);
            $ipSetting = SecuritySetting::where('site_id', $this->site->id)
                ->where('category', 'ip_management')
                ->where('is_enabled', true)
                ->first();

            if ($ipSetting) {
                $reported[] = [
                    'category' => 'ip_management',
                    'key' => $ipSetting->setting_key,
                    'applied' => $isActive,
                    'failed' => !$isActive,
                    'reason' => $isActive ? null : 'IP management not active on WordPress',
                ];
            }
        }

        if (!empty($reported)) {
            app(SecuritySettingsService::class)->syncSettingsFromAgent($this->site, $reported);
        }
    }
}
