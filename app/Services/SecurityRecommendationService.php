<?php

namespace App\Services;

use App\Models\SecurityRecommendation;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

class SecurityRecommendationService
{
    public static function check(Site $site): array
    {
        // Seed defaults if none exist
        static::seedDefaults($site);

        try {
            $api = new WordPressApiService($site);
            $result = $api->getSecurityCheck();

            $checks = $result['checks'] ?? [];

            foreach ($checks as $key => $status) {
                SecurityRecommendation::where('site_id', $site->id)
                    ->where('key', $key)
                    ->update([
                        'status' => $status ? 'passed' : 'failed',
                        'last_checked_at' => now(),
                    ]);
            }

            $passed = SecurityRecommendation::where('site_id', $site->id)->passed()->count();
            $failed = SecurityRecommendation::where('site_id', $site->id)->failed()->count();
            $total = SecurityRecommendation::where('site_id', $site->id)->count();

            return compact('passed', 'failed', 'total');
        } catch (\Exception $e) {
            Log::warning("Security recommendation check failed for site {$site->id}: {$e->getMessage()}");
            return ['passed' => 0, 'failed' => 0, 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    public static function fix(Site $site, string $key): bool
    {
        $rec = SecurityRecommendation::where('site_id', $site->id)->where('key', $key)->first();

        if (!$rec || !$rec->can_auto_fix) {
            return false;
        }

        try {
            $api = new WordPressApiService($site);
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
        } catch (\Exception $e) {
            Log::warning("Security fix failed for site {$site->id}, key {$key}: {$e->getMessage()}");
            return false;
        }
    }

    public static function ignore(SecurityRecommendation $rec): void
    {
        $rec->update(['status' => 'ignored']);
    }

    public static function seedDefaults(Site $site): void
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
