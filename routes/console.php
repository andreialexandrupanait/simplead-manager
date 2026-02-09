<?php

use App\Jobs\CheckUptime;
use App\Jobs\FetchAnalyticsData;
use App\Jobs\FetchSearchConsoleData;
use App\Jobs\GenerateReport;
use App\Jobs\RunPerformanceTest;
use App\Models\PerformanceMonitor;
use App\Models\ReportSchedule;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Uptime monitoring: dispatch checks for due monitors every minute
Schedule::call(function () {
    UptimeMonitor::active()
        ->whereHas('site')
        ->due()
        ->each(fn (UptimeMonitor $monitor) => CheckUptime::dispatch($monitor));
})->everyMinute()->name('uptime-checks')->withoutOverlapping()->onOneServer();

// SSL certificate checks — every 12 hours
Schedule::call(function () {
    \App\Models\SslCertificate::whereHas('site')
        ->where(function ($q) {
            $q->whereNull('next_check_at')
              ->orWhere('next_check_at', '<=', now());
        })->each(fn ($cert) => \App\Jobs\CheckSslCertificate::dispatch($cert));
})->cron('0 */12 * * *')->name('ssl-checks')->withoutOverlapping()->onOneServer();

// Domain expiry checks — daily
Schedule::call(function () {
    \App\Models\DomainMonitor::whereHas('site')
        ->where(function ($q) {
            $q->whereNull('next_check_at')
              ->orWhere('next_check_at', '<=', now());
        })->each(fn ($domain) => \App\Jobs\CheckDomainExpiry::dispatch($domain));
})->daily()->name('domain-checks')->withoutOverlapping()->onOneServer();

// Daily pruning: remove checks older than 90 days
Schedule::command('model:prune', ['--model' => [UptimeCheck::class]])->daily()->onOneServer();

// WordPress sync — every 6 hours
Schedule::call(function () {
    \App\Models\Site::where('is_connected', true)
        ->whereNotNull('api_endpoint')
        ->each(fn ($site) => \App\Jobs\SyncWordPressSite::dispatch($site));
})->everySixHours()->name('wordpress-sync')->withoutOverlapping()->onOneServer();

// Scheduled backups — every 15 minutes, check for due backup configs
Schedule::call(function () {
    \App\Models\BackupConfig::whereHas('site')
        ->where('is_enabled', true)
        ->where('next_backup_at', '<=', now())
        ->with('site')
        ->each(function (\App\Models\BackupConfig $config) {
            if (!$config->site?->is_connected) {
                return;
            }

            \App\Jobs\CreateBackup::dispatch(
                $config->site,
                $config->type,
                'scheduled',
                $config->storage_destination_id
            );

            // Calculate next backup time
            $next = match ($config->frequency) {
                'daily' => now()->addDay(),
                'weekly' => now()->addWeek(),
                'monthly' => now()->addMonth(),
                default => now()->addDay(),
            };

            // Apply configured time
            if ($config->time) {
                [$hour, $minute] = explode(':', $config->time);
                $next->setTime((int) $hour, (int) $minute);
            }

            $config->update(['next_backup_at' => $next]);
        });
})->everyFifteenMinutes()->name('scheduled-backups')->withoutOverlapping()->onOneServer();

// Performance tests — hourly
Schedule::call(function () {
    PerformanceMonitor::where('is_active', true)
        ->whereHas('site')
        ->where(fn ($q) => $q->whereNull('next_test_at')->orWhere('next_test_at', '<=', now()))
        ->each(fn ($monitor) => RunPerformanceTest::dispatch($monitor, 'both'));
})->hourly()->name('performance-tests')->withoutOverlapping()->onOneServer();

// Link scans — hourly
Schedule::call(function () {
    \App\Models\LinkMonitor::where('is_active', true)
        ->whereHas('site')
        ->where(fn ($q) => $q->whereNull('next_scan_at')->orWhere('next_scan_at', '<=', now()))
        ->each(function ($monitor) {
            if (!$monitor->scans()->whereIn('status', ['pending', 'in_progress'])->exists()) {
                \App\Jobs\RunLinkScan::dispatch($monitor, 'scheduled');
            }
        });
})->hourly()->name('link-scans')->withoutOverlapping()->onOneServer();

// Google Analytics & Search Console data fetch — daily at 06:00
Schedule::call(function () {
    Site::whereHas('analyticsConnection', fn ($q) => $q->where('is_active', true))
        ->each(function ($site) {
            FetchAnalyticsData::dispatch($site, '28d');
        });

    Site::whereHas('searchConsoleConnection', fn ($q) => $q->where('is_active', true))
        ->each(function ($site) {
            FetchSearchConsoleData::dispatch($site, '28d');
        });
})->dailyAt('06:00')->name('google-data-fetch')->withoutOverlapping()->onOneServer();

// Tracked keyword positions — daily at 06:30
Schedule::call(function () {
    Site::whereHas('trackedKeywords')
        ->whereHas('searchConsoleConnection', fn ($q) => $q->where('is_active', true))
        ->each(fn ($site) => \App\Jobs\FetchKeywordPositions::dispatch($site));
})->dailyAt('06:30')->name('keyword-position-fetch')->withoutOverlapping()->onOneServer();

// Scheduled reports — hourly
Schedule::call(function () {
    ReportSchedule::where('is_active', true)
        ->whereHas('site')
        ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
        ->with(['site', 'reportTemplate'])
        ->each(function (ReportSchedule $schedule) {
            if (!$schedule->site || !$schedule->reportTemplate) {
                return;
            }

            $period = $schedule->period ?? 'last_30_days';
            [$periodStart, $periodEnd] = match ($period) {
                'last_7_days' => [now()->subDays(7)->startOfDay(), now()->endOfDay()],
                'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
                default => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            };

            GenerateReport::dispatch(
                $schedule->site,
                $schedule->reportTemplate,
                $periodStart,
                $periodEnd,
                'scheduled',
                $schedule,
            );
        });
})->hourly()->name('scheduled-reports')->withoutOverlapping()->onOneServer();

// Maintenance windows — every minute
Schedule::call(function () {
    \App\Services\MaintenanceService::processScheduledWindows();
    \App\Services\MaintenanceService::processEndingWindows();
})->everyMinute()->name('maintenance-windows')->withoutOverlapping()->onOneServer();

// Error log sync — every 15 minutes
Schedule::call(function () {
    Site::where('is_connected', true)
        ->each(fn ($site) => \App\Jobs\SyncErrorLogsJob::dispatch($site));
})->everyFifteenMinutes()->name('error-log-sync')->withoutOverlapping()->onOneServer();

// Expired backup cleanup — daily
Schedule::call(function () {
    \App\Models\Backup::where('expires_at', '<=', now())
        ->where('is_locked', false)
        ->each(function (\App\Models\Backup $backup) {
            try {
                $destination = $backup->storageDestination;
                if ($destination && $backup->file_path) {
                    $driver = \App\Services\Backup\Storage\StorageFactory::make($destination);
                    $driver->delete($backup->file_path);
                    $destination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
                }
                $backup->delete();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to clean expired backup {$backup->id}: {$e->getMessage()}");
            }
        });
})->daily()->name('expired-backup-cleanup')->withoutOverlapping()->onOneServer();

// Scheduled app backups — every 15 minutes, check if due
Schedule::call(function () {
    $config = \App\Models\AppBackupConfig::query()
        ->where('is_enabled', true)
        ->where('next_backup_at', '<=', now())
        ->first();

    if ($config) {
        \App\Jobs\CreateAppBackup::dispatch(
            $config->type,
            'scheduled',
            $config->storage_destination_id,
        );

        $config->update(['next_backup_at' => $config->calculateNextBackupAt()]);
    }
})->everyFifteenMinutes()->name('scheduled-app-backups')->withoutOverlapping()->onOneServer();

// Expired app backup cleanup — daily at 04:00
Schedule::call(function () {
    app(\App\Services\AppBackup\AppBackupService::class)->cleanupExpired();
})->dailyAt('04:00')->name('expired-app-backup-cleanup')->withoutOverlapping()->onOneServer();

// Security scans — weekly Sunday 2 AM
Schedule::call(function () {
    Site::where('is_connected', true)
        ->each(fn ($site) => \App\Jobs\RunSecurityScan::dispatch($site));
})->weekly()->sundays()->at('02:00')->name('security-scans')->withoutOverlapping()->onOneServer();

// Vulnerability checks — daily 3 AM
Schedule::call(function () {
    Site::where('is_connected', true)
        ->each(fn ($site) => \App\Jobs\CheckVulnerabilities::dispatch($site));
})->daily()->at('03:00')->name('vulnerability-checks')->withoutOverlapping()->onOneServer();

// Audit log sync — every 30 minutes
Schedule::call(function () {
    Site::where('is_connected', true)
        ->each(fn ($site) => \App\Jobs\SyncAuditLogs::dispatch($site));
})->everyThirtyMinutes()->name('audit-log-sync')->withoutOverlapping()->onOneServer();

// Fetch blocked requests — hourly
Schedule::call(function () {
    Site::where('is_connected', true)
        ->each(fn ($site) => \App\Jobs\FetchBlockedRequests::dispatch($site));
})->hourly()->name('fetch-blocked-requests')->withoutOverlapping()->onOneServer();

// Sync Cloudflare zone details — daily
Schedule::call(function () {
    \App\Models\SiteCloudflare::with('cloudflareConnection')
        ->each(fn ($sc) => \App\Jobs\SyncCloudflareZone::dispatch($sc));
})->daily()->name('cloudflare-zone-sync')->withoutOverlapping()->onOneServer();

// Clean expired IP rules + old audit logs + old blocked requests — daily
Schedule::call(function () {
    \App\Models\IpRule::whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
    \App\Models\WpAuditLog::where('action_at', '<=', now()->subDays(90))->delete();
    \App\Models\BlockedRequest::where('blocked_at', '<=', now()->subDays(30))->delete();
})->daily()->name('security-cleanup')->withoutOverlapping()->onOneServer();

// Clean expired rollback points — daily
Schedule::call(function () {
    app(\App\Services\RollbackService::class)->cleanExpired();
})->daily()->name('rollback-cleanup')->withoutOverlapping()->onOneServer();

// Resource checks — every 15 minutes
Schedule::call(function () {
    Site::where('is_connected', true)
        ->each(fn ($site) => \App\Jobs\CheckResourceUsage::dispatch($site));
})->everyFifteenMinutes()->name('resource-checks')->withoutOverlapping()->onOneServer();

// Prune old resource checks — daily (older than 90 days)
Schedule::call(function () {
    \App\Models\ResourceCheck::where('checked_at', '<=', now()->subDays(90))->delete();
})->daily()->name('resource-check-prune')->withoutOverlapping()->onOneServer();

// SEO checks — weekly Monday at 5 AM
Schedule::call(function () {
    Site::where('is_connected', true)
        ->each(fn ($site) => \App\Jobs\RunSeoCheck::dispatch($site));
})->weekly()->mondays()->at('05:00')->name('seo-checks')->withoutOverlapping()->onOneServer();

// Email deliverability checks — weekly Wednesday at 4 AM
Schedule::call(function () {
    Site::where('is_connected', true)
        ->each(fn ($site) => \App\Jobs\CheckEmailDeliverabilityJob::dispatch($site));
})->weekly()->wednesdays()->at('04:00')->name('email-deliverability-checks')->withoutOverlapping()->onOneServer();

// WooCommerce stats sync — every 6 hours
Schedule::call(function () {
    Site::where('is_connected', true)
        ->where('has_woocommerce', true)
        ->each(fn ($site) => \App\Jobs\SyncWooCommerceStats::dispatch($site));
})->everySixHours()->name('woocommerce-sync')->withoutOverlapping()->onOneServer();

// ==========================================================================
// DB Maintenance
// ==========================================================================

// VACUUM ANALYZE — weekly Sunday 3 AM
Schedule::call(function () {
    DB::statement('VACUUM ANALYZE');
})->weekly()->sundays()->at('03:00')->name('vacuum-analyze')->withoutOverlapping()->onOneServer();

// Activity log purge — daily 4 AM, delete records older than 180 days
Schedule::call(function () {
    \App\Models\ActivityLog::where('created_at', '<=', now()->subDays(180))->delete();
})->dailyAt('04:00')->name('activity-log-purge')->withoutOverlapping()->onOneServer();

// Performance test purge — daily 4:10 AM, delete records older than 90 days
Schedule::call(function () {
    \App\Models\PerformanceTest::where('created_at', '<=', now()->subDays(90))->delete();
})->dailyAt('04:10')->name('performance-test-purge')->withoutOverlapping()->onOneServer();

// Failed jobs cleanup — daily, prune jobs older than 7 days
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily()->name('failed-jobs-prune')->onOneServer();

// Horizon health check — every 5 minutes
Schedule::call(function () {
    $cacheKey = 'horizon_stopped_notified';

    try {
        $supervisors = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();

        if (empty($supervisors)) {
            if (!\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                \App\Services\Notifications\NotificationService::notifyAppEvent(
                    event: 'horizon_stopped',
                    title: 'Horizon Is Not Running',
                    message: 'No Horizon supervisor processes were found. Queue jobs are not being processed.',
                    severity: 'critical',
                );
                \Illuminate\Support\Facades\Cache::put($cacheKey, true, 3600);
            }
        } else {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('Horizon health check failed: ' . $e->getMessage());
    }
})->everyFiveMinutes()->name('horizon-health-check')->withoutOverlapping()->onOneServer();

// Horizon metrics snapshot — every 5 minutes
Schedule::command('horizon:snapshot')->everyFiveMinutes()->name('horizon-snapshot')->onOneServer();

// Favicon backfill — daily, fetch favicons for sites that don't have one
Schedule::call(function () {
    Site::whereNull('favicon_path')
        ->each(fn ($site) => \App\Jobs\FetchSiteFavicon::dispatch($site));
})->daily()->name('favicon-backfill')->withoutOverlapping()->onOneServer();

// Daily health digest email — 7:00 AM
Schedule::job(new \App\Jobs\SendDailyDigest)->dailyAt('07:00')->name('daily-digest')->onOneServer();

// Screenshot refresh — weekly Sunday 3:00 AM, refresh screenshots for 10 sites
Schedule::call(function () {
    $sites = Site::with('performanceMonitor')
        ->whereNotNull('screenshot_path')
        ->where('status', 'active')
        ->orderBy('updated_at')
        ->limit(10)
        ->get();

    foreach ($sites as $site) {
        if ($site->performanceMonitor) {
            RunPerformanceTest::dispatch($site->performanceMonitor, 'desktop');
        }
    }
})->weekly()->sundays()->at('03:00')->name('screenshot-refresh')->withoutOverlapping()->onOneServer();
