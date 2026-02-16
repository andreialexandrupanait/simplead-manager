<?php

use App\Dispatchers\BackupDispatcher;
use App\Dispatchers\DataSyncDispatcher;
use App\Dispatchers\MonitoringDispatcher;
use App\Dispatchers\ReportDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// ==========================================================================
// Core Dispatchers (4)
// ==========================================================================

// Monitoring: uptime checks, SSL checks, security scans
Schedule::call(new MonitoringDispatcher)
    ->everyMinute()
    ->name('monitoring-dispatcher')
    ->withoutOverlapping()
    ->onOneServer();

// Data Sync: analytics, search console, cloudflare, WP sync
Schedule::call(new DataSyncDispatcher)
    ->everyMinute()
    ->name('data-sync-dispatcher')
    ->withoutOverlapping()
    ->onOneServer();

// Backups: site backups + app backups
Schedule::call(new BackupDispatcher)
    ->everyMinute()
    ->name('backup-dispatcher')
    ->withoutOverlapping()
    ->onOneServer();

// Reports: scheduled report generation
Schedule::call(new ReportDispatcher)
    ->everyFiveMinutes()
    ->name('report-dispatcher')
    ->withoutOverlapping()
    ->onOneServer();

// ==========================================================================
// Monthly Aggregation
// ==========================================================================

Schedule::job(new \App\Jobs\AggregateMonthlySnapshots)
    ->monthlyOn(1, '02:00')
    ->name('monthly-aggregation')
    ->onOneServer();

// ==========================================================================
// Retention Cleanup
// ==========================================================================

Schedule::job(new \App\Jobs\RetentionCleanup)
    ->dailyAt('03:00')
    ->name('retention-cleanup')
    ->onOneServer();

// ==========================================================================
// Infrastructure
// ==========================================================================

// Horizon metrics snapshot
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->name('horizon-snapshot')
    ->onOneServer();

// Prune failed jobs older than 7 days
Schedule::command('queue:prune-failed', ['--hours' => 168])
    ->daily()
    ->name('failed-jobs-prune')
    ->onOneServer();

// Prune MassPrunable models (UptimeCheck 45-day retention)
Schedule::command('model:prune', ['--model' => [\App\Models\UptimeCheck::class]])
    ->dailyAt('03:30')
    ->name('model-prune')
    ->onOneServer();

// Daily PostgreSQL dump (independent database backup)
Schedule::command('db:dump', ['--keep' => 7])
    ->dailyAt('02:30')
    ->name('database-dump')
    ->onOneServer();

// VACUUM ANALYZE — weekly Sunday 3 AM
Schedule::call(fn () => DB::statement('VACUUM ANALYZE'))
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->name('vacuum-analyze')
    ->withoutOverlapping()
    ->onOneServer();

// Favicon backfill — daily
Schedule::call(function () {
    \App\Models\Site::whereNull('favicon_path')
        ->each(fn ($site) => \App\Jobs\FetchSiteFavicon::dispatch($site));
})->daily()->name('favicon-backfill')->withoutOverlapping()->onOneServer();

// Domain expiry checks — daily
Schedule::call(function () {
    \App\Models\DomainMonitor::whereHas('site')
        ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
        ->each(fn ($domain) => \App\Jobs\CheckDomainExpiry::dispatch($domain));
})->daily()->name('domain-checks')->withoutOverlapping()->onOneServer();

// App backup scheduler
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

// App backup cleanup
Schedule::call(function () {
    app(\App\Services\AppBackup\AppBackupService::class)->cleanupExpired();
})->dailyAt('04:00')->name('expired-app-backup-cleanup')->withoutOverlapping()->onOneServer();

// Horizon health check
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

// Validate external connections (Google, Cloudflare, Dropbox, WordPress)
Schedule::job(new \App\Jobs\ValidateExternalConnections)
    ->dailyAt('06:00')
    ->name('validate-external-connections')
    ->onOneServer();

// Process buffered notifications (grouping/batching)
Schedule::job(new \App\Jobs\ProcessNotificationBatch)
    ->everyMinute()
    ->name('process-notification-batch')
    ->onOneServer()
    ->withoutOverlapping();

// Daily health digest email
Schedule::job(new \App\Jobs\SendDailyDigest)
    ->dailyAt('07:00')
    ->name('daily-digest')
    ->onOneServer();
