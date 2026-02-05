<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteCronJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CronManagerService
{
    public static function sync(Site $site): void
    {
        $api = new WordPressApiService($site);
        $data = $api->getCronList();

        $cronJobs = $data['cron_jobs'] ?? [];
        $existingIds = [];

        foreach ($cronJobs as $job) {
            $cronJob = $site->siteCronJobs()->updateOrCreate(
                [
                    'hook' => $job['hook'],
                    'schedule' => $job['schedule'] ?? null,
                ],
                [
                    'interval' => $job['interval'] ?? null,
                    'next_run' => isset($job['next_run']) ? Carbon::createFromTimestamp($job['next_run']) : null,
                    'last_run' => isset($job['last_run']) ? Carbon::createFromTimestamp($job['last_run']) : null,
                    'arguments' => $job['args'] ?? null,
                    'is_disabled' => $job['is_disabled'] ?? false,
                ]
            );
            $existingIds[] = $cronJob->id;
        }

        // Remove stale cron jobs
        $site->siteCronJobs()
            ->whereNotIn('id', $existingIds)
            ->delete();
    }

    public static function run(Site $site, SiteCronJob $cronJob): array
    {
        try {
            $api = new WordPressApiService($site);
            $result = $api->runCron($cronJob->hook, $cronJob->arguments);

            $cronJob->update(['last_run' => now()]);

            ActivityLogger::log(
                type: 'cron',
                severity: 'info',
                title: "Cron job executed on {$site->name}",
                description: "Hook: {$cronJob->hook}",
                site: $site,
                icon: 'clock',
                url: route('sites.cron', $site),
            );

            return ['success' => true, 'message' => $result['message'] ?? 'Cron job executed successfully.'];
        } catch (\Exception $e) {
            Log::warning("Cron run failed for {$cronJob->hook} on site {$site->id}: {$e->getMessage()}");
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function disable(Site $site, SiteCronJob $cronJob): array
    {
        try {
            $api = new WordPressApiService($site);
            $api->disableCron($cronJob->hook, $cronJob->arguments);

            $cronJob->update(['is_disabled' => true]);

            ActivityLogger::log(
                type: 'cron',
                severity: 'info',
                title: "Cron job disabled on {$site->name}",
                description: "Hook: {$cronJob->hook}",
                site: $site,
                icon: 'clock',
                url: route('sites.cron', $site),
            );

            return ['success' => true, 'message' => 'Cron job disabled.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function enable(Site $site, SiteCronJob $cronJob): array
    {
        try {
            $api = new WordPressApiService($site);
            $api->enableCron($cronJob->hook, $cronJob->schedule ?? 'hourly', $cronJob->arguments);

            $cronJob->update(['is_disabled' => false]);

            ActivityLogger::log(
                type: 'cron',
                severity: 'info',
                title: "Cron job enabled on {$site->name}",
                description: "Hook: {$cronJob->hook}",
                site: $site,
                icon: 'clock',
                url: route('sites.cron', $site),
            );

            return ['success' => true, 'message' => 'Cron job enabled.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
