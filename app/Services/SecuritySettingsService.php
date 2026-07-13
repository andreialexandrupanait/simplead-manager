<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SecurityCategory;
use App\Jobs\PushSecuritySettings;
use App\Models\SecurityBannedIp;
use App\Models\SecurityPreset;
use App\Models\SecuritySetting;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SecuritySettingsService
{
    public const VALID_SETTING_KEYS = [
        'hardening' => [
            'disable_theme_editor',
            'disable_user_enumeration',
            'hide_wp_version',
            'restrict_xmlrpc',
            'security_headers',
            'block_application_passwords',
            'restrict_rest_api',
        ],
        'htaccess' => [
            'block_default_files',
            'block_readme_access',
            'block_debug_log',
            'disable_directory_listing',
            'firewall_enabled',
        ],
        'login' => [
            'brute_force_protection',
            'custom_login_url',
            'two_factor_auth',
        ],
        'captcha' => [
            'captcha_config',
        ],
        'ip_management' => [
            'firewall_config',
        ],
        'activity_log' => [
            'activity_log_config',
        ],
    ];

    /**
     * Bulk-safe whitelist — the SINGLE source of truth for which security
     * settings may be replicated across sites (plan snapshots, presets, bulk
     * copy). Enforced at BOTH snapshot time and apply time.
     *
     * Site-specific and credential-bearing settings are deliberately EXCLUDED
     * so a snapshot built from one site can never overwrite another site's:
     *   - login.custom_login_url       (per-site login slug)
     *   - login.two_factor_auth        (per-site / per-user 2FA config)
     *   - captcha.captcha_config        (holds the encrypted CAPTCHA secret key)
     *   - ip_management.firewall_config (per-site firewall + IP lists)
     *
     * Existing plans/presets that already contain excluded keys are filtered
     * on read at apply time — there is NO plan-JSON migration.
     */
    public const BULK_SAFE_SETTING_KEYS = [
        'hardening' => [
            'disable_theme_editor',
            'disable_user_enumeration',
            'hide_wp_version',
            'restrict_xmlrpc',
            'security_headers',
            'block_application_passwords',
            'restrict_rest_api',
        ],
        'htaccess' => [
            'block_default_files',
            'block_readme_access',
            'block_debug_log',
            'disable_directory_listing',
            'firewall_enabled',
        ],
        'login' => [
            'brute_force_protection',
        ],
        'activity_log' => [
            'activity_log_config',
        ],
    ];

    public const SCORE_WEIGHTS = [
        'brute_force_protection' => 15,
        'disable_theme_editor' => 10,
        'restrict_xmlrpc' => 10,
        'security_headers' => 10,
        'disable_user_enumeration' => 8,
        'block_default_files' => 7,
        'hide_wp_version' => 5,
        'block_readme_access' => 5,
        'block_debug_log' => 5,
        'disable_directory_listing' => 5,
        'block_application_passwords' => 5,
        'restrict_rest_api' => 5,
        'firewall_config' => 5,
        'activity_log_config' => 5,
    ];

    public function getSettingsForSite(Site $site): Collection
    {
        return $site->securitySettings()
            ->orderBy('category')
            ->orderBy('setting_key')
            ->get()
            ->groupBy('category');
    }

    public function isValidSetting(string $category, string $key): bool
    {
        return isset(self::VALID_SETTING_KEYS[$category])
            && in_array($key, self::VALID_SETTING_KEYS[$category], true);
    }

    /**
     * Whether a setting is safe to replicate across sites via a plan snapshot,
     * preset, or bulk copy. See self::BULK_SAFE_SETTING_KEYS.
     */
    public function isBulkSafeSetting(string $category, string $key): bool
    {
        return isset(self::BULK_SAFE_SETTING_KEYS[$category])
            && in_array($key, self::BULK_SAFE_SETTING_KEYS[$category], true);
    }

    /**
     * Filter a plan/preset settings blob (category => key => config) down to
     * only bulk-safe entries. Applied on read so existing plans/presets that
     * already contain site-specific or credential keys stop propagating them.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, array<string, mixed>>
     */
    public function filterBulkSafeSettings(array $settings): array
    {
        $filtered = [];

        foreach ($settings as $category => $categorySettings) {
            if (! is_array($categorySettings)) {
                continue;
            }

            foreach ($categorySettings as $key => $config) {
                if ($this->isBulkSafeSetting((string) $category, (string) $key)) {
                    $filtered[(string) $category][(string) $key] = $config;
                }
            }
        }

        return $filtered;
    }

    public function applySetting(Site $site, string $category, string $key, mixed $value, bool $enabled): SecuritySetting
    {
        if (! $this->isValidSetting($category, $key)) {
            throw new \InvalidArgumentException("Invalid security setting: {$category}/{$key}");
        }

        $setting = SecuritySetting::updateOrCreate(
            ['site_id' => $site->id, 'category' => $category, 'setting_key' => $key],
            ['setting_value' => $value, 'is_enabled' => $enabled, 'failed_at' => null, 'failure_reason' => null],
        );

        // Push settings to the WordPress plugin via REST API — the only
        // enforcement path (the old SecurityCommand pull-queue had no consumer
        // and was removed; see the agent-pull removal PR).
        $this->pushToPlugin($site);

        return $setting;
    }

    public function applyPreset(SecurityPreset $preset, Collection $sites): void
    {
        // Filter on read: never apply site-specific/credential keys that an
        // older preset may still carry (custom login URL, 2FA, CAPTCHA secret,
        // firewall config). Single source of truth: BULK_SAFE_SETTING_KEYS.
        $settings = $this->filterBulkSafeSettings($preset->settings ?? []);

        foreach ($sites as $site) {
            foreach ($settings as $category => $categorySettings) {
                foreach ($categorySettings as $key => $config) {
                    $this->applySetting(
                        $site,
                        $category,
                        $key,
                        $config['value'] ?? null,
                        $config['enabled'] ?? false,
                    );
                }
            }

            // Update pivot
            $site->securityPresets()->syncWithoutDetaching([
                $preset->id => [
                    'applied_at' => now(),
                    'applied_version' => $preset->version,
                ],
            ]);
        }
    }

    public function syncSettingsFromAgent(Site $site, array $reportedSettings): void
    {
        foreach ($reportedSettings as $item) {
            if (! $this->isValidSetting($item['category'], $item['key'])) {
                continue;
            }

            $setting = SecuritySetting::where('site_id', $site->id)
                ->where('category', $item['category'])
                ->where('setting_key', $item['key'])
                ->first();

            if (! $setting) {
                continue;
            }

            if ($item['applied'] ?? false) {
                $setting->update([
                    'applied_at' => now(),
                    'failed_at' => null,
                    'failure_reason' => null,
                ]);
            } elseif ($item['failed'] ?? false) {
                $setting->update([
                    'failed_at' => now(),
                    'failure_reason' => $item['reason'] ?? 'Unknown error',
                ]);
            }
        }

        $site->update(['security_hardening_score' => $this->getSecurityScore($site)]);
    }

    public function getSecurityScore(Site $site): int
    {
        $appliedSettings = SecuritySetting::where('site_id', $site->id)
            ->where('is_enabled', true)
            ->whereNotNull('applied_at')
            ->whereNull('failed_at')
            ->pluck('setting_key')
            ->toArray();

        $score = 0;
        foreach (self::SCORE_WEIGHTS as $key => $weight) {
            if (in_array($key, $appliedSettings)) {
                $score += $weight;
            }
        }

        return min(100, $score);
    }

    /**
     * P3-21: credit the Laravel-only activity_log setting only once the
     * connector has actually confirmed enforcement. Unlike hardening/htaccess/
     * login/captcha/ip_management, activity_log is never part of the security
     * push payload — its "enforcement" is that the connector's audit-log endpoint
     * responds, proving audit logging is live and we can ingest events. Callers
     * invoke this after a successful audit-log fetch. Idempotent: only enabled,
     * not-yet-applied rows are touched, and the score is recomputed only when a
     * row actually flips so we don't thrash the site record.
     */
    public function markActivityLogVerified(Site $site): void
    {
        $updated = SecuritySetting::where('site_id', $site->id)
            ->where('category', SecurityCategory::ActivityLog)
            ->where('is_enabled', true)
            ->whereNull('applied_at')
            ->update([
                'applied_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ]);

        if ($updated > 0) {
            $site->update(['security_hardening_score' => $this->getSecurityScore($site)]);
        }
    }

    /**
     * Dispatch a job to push security settings to the WordPress plugin.
     * Uses a 5-second delay to consolidate rapid changes.
     */
    public function pushToPlugin(Site $site): void
    {
        PushSecuritySettings::dispatch($site)->delay(now()->addSeconds(1));
    }

    public function syncBannedIps(Site $site, array $bannedIps): void
    {
        $seenIps = [];

        foreach ($bannedIps as $ip => $data) {
            $bannedAt = isset($data['banned_at']) ? Carbon::createFromTimestamp($data['banned_at']) : now();
            $expiresAt = isset($data['expires_at']) ? Carbon::createFromTimestamp($data['expires_at']) : null;

            // Skip already-expired entries
            if ($expiresAt && $expiresAt->isPast()) {
                continue;
            }

            SecurityBannedIp::updateOrCreate(
                ['site_id' => $site->id, 'ip_address' => $ip],
                [
                    'reason' => $data['reason'] ?? 'Brute force',
                    'blocked_attempts' => $data['attempts'] ?? 0,
                    'banned_at' => $bannedAt,
                    'expires_at' => $expiresAt,
                ],
            );

            $seenIps[] = $ip;
        }

        // Remove entries no longer present on WordPress side
        SecurityBannedIp::where('site_id', $site->id)
            ->when(! empty($seenIps), fn ($q) => $q->whereNotIn('ip_address', $seenIps))
            ->delete();
    }

    public function recalculateAllScores(): void
    {
        Site::whereHas('securitySettings')->chunk(100, function ($sites) {
            foreach ($sites as $site) {
                $site->update(['security_hardening_score' => $this->getSecurityScore($site)]);
            }
        });
    }
}
