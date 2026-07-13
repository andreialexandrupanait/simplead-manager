<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SecurityIpList;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushSecuritySettings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public int $uniqueFor = 180; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('security');
    }

    public function handle(): void
    {
        $settings = SecuritySetting::where('site_id', $this->site->id)
            ->where('is_enabled', true)
            ->get();

        // P3-21: activity_log is NOT pushed here — the connector exposes no
        // security-settings hook for it (buildPayload omits the category). Its
        // enforcement is verified out-of-band: PullSecurityActivityLogs marks it
        // applied only once the connector's audit-log endpoint actually responds,
        // proving audit logging is live. Marking it applied here (as before) would
        // credit the hardening score even for an unreachable/unconfigured site.

        $payload = $this->buildPayload($settings);

        if (empty($payload)) {
            return;
        }

        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $response = $api->request('POST', '/security-settings', $payload);

            if ($response->successful()) {
                $results = $response->json('results') ?? [];
                $this->processResults($results);

                // Sync banned IPs reported by WordPress. An empty array is
                // authoritative ("no bans") â skipping it left a stale local
                // row after the last IP was unbanned. Only a missing key
                // (old connector) skips the sync.
                $bannedIps = $response->json('banned_ips');
                if (is_array($bannedIps)) {
                    app(SecuritySettingsService::class)->syncBannedIps($this->site, $bannedIps);
                }
            } else {
                Log::warning('PushSecuritySettings failed', [
                    'site_id' => $this->site->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $this->markAllFailed('Plugin returned HTTP '.$response->status());
            }
        } catch (\Exception $e) {
            Log::error('PushSecuritySettings exception', [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Let the queue retry
        }

        // Recalculate security score
        $score = app(SecuritySettingsService::class)->getSecurityScore($this->site);
        $this->site->update(['security_hardening_score' => $score]);
    }

    protected function buildPayload($settings): array
    {
        $payload = [];

        foreach ($settings as $setting) {
            $category = $setting->category->value ?? $setting->category;
            $key = $setting->setting_key;
            $value = $setting->setting_value;

            if ($category === 'hardening') {
                $payload['hardening'][$key] = true;
            } elseif ($category === 'htaccess') {
                $payload['htaccess'][$key] = true;
            } elseif ($category === 'login') {
                $payload['login'][$key] = [
                    'enabled' => true,
                    ...(is_array($value) ? $value : []),
                ];
            } elseif ($category === 'captcha') {
                $payload['captcha'] = array_merge(['enabled' => true], is_array($value) ? $value : []);
            } elseif ($category === 'ip_management') {
                $payload['ip_management'] = is_array($value) ? $value : [];
            }
        }

        // Also include disabled settings so the plugin stops enforcing them
        $allDisabled = SecuritySetting::where('site_id', $this->site->id)
            ->whereIn('category', ['hardening', 'htaccess', 'login', 'captcha', 'ip_management'])
            ->where('is_enabled', false)
            ->get();

        foreach ($allDisabled as $setting) {
            $category = $setting->category->value ?? $setting->category;
            $key = $setting->setting_key;

            if (in_array($category, ['hardening', 'htaccess'])) {
                $payload[$category][$key] = false;
            } elseif ($category === 'login') {
                $payload['login'][$key] = ['enabled' => false];
            } elseif ($category === 'captcha') {
                // If captcha is fully disabled, send disabled flag
                if (! isset($payload['captcha'])) {
                    $payload['captcha'] = ['enabled' => false];
                }
            } elseif ($category === 'ip_management') {
                if (! isset($payload['ip_management'])) {
                    $payload['ip_management'] = ['enabled' => false];
                }
            }
        }

        // Decrypt the CAPTCHA secret key (stored encrypted in Laravel DB)
        if (isset($payload['captcha']['secret_key'])) {
            try {
                $payload['captcha']['secret_key'] = decrypt($payload['captcha']['secret_key']);
            } catch (\Throwable $e) {
                // Not encrypted or decryption failed â leave as-is
            }
        }

        // Include IP lists from the database in the push payload
        if (isset($payload['ip_management'])) {
            $payload['ip_management']['whitelist'] = SecurityIpList::forSite($this->site->id)
                ->whitelist()->active()->pluck('ip_address')->toArray();
            $payload['ip_management']['blocklist'] = SecurityIpList::forSite($this->site->id)
                ->blocklist()->active()->pluck('ip_address')->toArray();
        }

        return $payload;
    }

    protected function processResults(array $results): void
    {
        $now = now();
        $reported = [];

        // Hardening results
        if (isset($results['hardening']['applied'])) {
            foreach ($results['hardening']['applied'] as $key) {
                $reported[] = ['category' => 'hardening', 'key' => $key, 'applied' => true];
            }
        }

        // Htaccess results
        if (isset($results['htaccess'])) {
            foreach ($results['htaccess'] as $key => $result) {
                $reported[] = [
                    'category' => 'htaccess',
                    'key' => $key,
                    'applied' => $result['success'] ?? false,
                    'failed' => ! ($result['success'] ?? false),
                    'reason' => $result['message'] ?? null,
                ];
            }
        }

        // Login/captcha/ip_management â simple success flags
        foreach (['login', 'captcha', 'ip_management'] as $cat) {
            if (isset($results[$cat]['success']) && $results[$cat]['success']) {
                $settings = SecuritySetting::where('site_id', $this->site->id)
                    ->where('category', $cat)
                    ->where('is_enabled', true)
                    ->get();

                foreach ($settings as $s) {
                    $reported[] = ['category' => $cat, 'key' => $s->setting_key, 'applied' => true];
                }

                // Store custom login slug on the site when login settings are applied
                if ($cat === 'login') {
                    $this->syncCustomLoginSlug();
                }
            }
        }

        if (! empty($reported)) {
            app(SecuritySettingsService::class)->syncSettingsFromAgent($this->site, $reported);
        }
    }

    protected function syncCustomLoginSlug(): void
    {
        $loginSetting = SecuritySetting::where('site_id', $this->site->id)
            ->where('category', 'login')
            ->where('setting_key', 'custom_login_url')
            ->first();

        if ($loginSetting?->is_enabled) {
            $this->site->update(['custom_login_slug' => $loginSetting->setting_value['slug'] ?? null]);
        } else {
            $this->site->update(['custom_login_slug' => null]);
        }
    }

    protected function markAllFailed(string $reason): void
    {
        SecuritySetting::where('site_id', $this->site->id)
            ->where('is_enabled', true)
            ->where('category', '!=', 'activity_log')
            ->whereNull('applied_at')
            ->update([
                'failed_at' => now(),
                'failure_reason' => $reason,
            ]);
    }

    public function uniqueId(): string
    {
        return 'push-security-'.$this->site->id;
    }

    /**
     * P1-62: without a failed() handler, enabled-but-unapplied settings sit as
     * "Pending" forever once retries are exhausted (or the worker is hard-killed
     * mid-push), and the hardening score stays stale-optimistic. Mark the stuck
     * settings failed so the security dashboard surfaces the real error state,
     * and recompute the score so it reflects that the push never landed.
     */
    public function failed(?\Throwable $e): void
    {
        $reason = $e?->getMessage() ?? 'unknown error';

        Log::error('PushSecuritySettings permanently failed', [
            'site_id' => $this->site->id,
            'error' => $reason,
        ]);

        $this->markAllFailed('Push failed: '.$reason);

        $score = app(SecuritySettingsService::class)->getSecurityScore($this->site);
        $this->site->update(['security_hardening_score' => $score]);
    }
}
