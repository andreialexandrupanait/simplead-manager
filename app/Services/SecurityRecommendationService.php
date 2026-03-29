<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SecurityRecommendation;
use App\Models\Site;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class SecurityRecommendationService
{
    public function __construct(
        protected WordPressApiServiceFactory $apiFactory,
    ) {}

    public function check(Site $site): array
    {
        $this->seedDefaults($site);

        $keyMap = [
            'file_editor_disabled' => 'disable_file_editing',
            'directory_listing_disabled' => 'disable_directory_listing',
            'wp_config_permissions' => 'protect_wp_config',
            'htaccess_permissions' => 'protect_htaccess',
            'no_default_admin' => 'change_admin_username',
            'custom_db_prefix' => 'change_table_prefix',
            'xmlrpc_disabled' => 'disable_xmlrpc',
            'ssl_active' => 'force_https',
        ];

        try {
            $api = $this->apiFactory->make($site);
            $result = $api->getSecurityCheck();

            $checks = $result['checks'] ?? [];

            foreach ($checks as $pluginKey => $status) {
                $definitionKey = $keyMap[$pluginKey] ?? null;
                if (! $definitionKey) {
                    continue;
                }

                $passed = is_array($status) ? ($status['pass'] ?? false) : (bool) $status;

                SecurityRecommendation::where('site_id', $site->id)
                    ->where('key', $definitionKey)
                    ->update([
                        'status' => $passed ? 'passed' : 'failed',
                        'last_checked_at' => now(),
                    ]);
            }

            $passed = SecurityRecommendation::where('site_id', $site->id)->passed()->count();
            $failed = SecurityRecommendation::where('site_id', $site->id)->failed()->count();
            $total = SecurityRecommendation::where('site_id', $site->id)->count();

            return compact('passed', 'failed', 'total');
        } catch (RequestException|\RuntimeException $e) {
            Log::warning("Security recommendation check failed for site {$site->id}: {$e->getMessage()}");

            return ['passed' => 0, 'failed' => 0, 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    public function fix(Site $site, string $key): bool
    {
        $rec = SecurityRecommendation::where('site_id', $site->id)->where('key', $key)->first();

        if (! $rec || ! $rec->can_auto_fix) {
            return false;
        }

        try {
            $api = $this->apiFactory->make($site);
            $result = $api->applySecurityFix($key);

            if ($result['success'] ?? false) {
                $rec->update([
                    'status' => 'passed',
                    'last_checked_at' => now(),
                ]);

                ActivityLogger::log(
                    'security',
                    'info',
                    "Security fix applied: {$rec->title}",
                    null,
                    $site,
                    ['key' => $key],
                    'shield'
                );

                return true;
            }

            return false;
        } catch (RequestException|\RuntimeException $e) {
            Log::warning("Security fix failed for site {$site->id}, key {$key}: {$e->getMessage()}");

            return false;
        }
    }

    public function ignore(SecurityRecommendation $rec): void
    {
        $rec->update(['status' => 'ignored']);
    }

    public function seedDefaults(Site $site): void
    {
        $existing = SecurityRecommendation::where('site_id', $site->id)->pluck('key')->toArray();

        foreach (SecurityRecommendation::DEFINITIONS as $key => $def) {
            if (in_array($key, $existing)) {
                continue;
            }

            SecurityRecommendation::create([
                'site_id' => $site->id,
                'key' => $key,
                'category' => $def['category'],
                'title' => $def['title'],
                'can_auto_fix' => $def['can_auto_fix'],
                'status' => 'unchecked',
            ]);
        }
    }
}
