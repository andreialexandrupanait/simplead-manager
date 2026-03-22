<?php

use App\Dispatchers\BackupDispatcher;
use App\Dispatchers\DataSyncDispatcher;
use App\Dispatchers\MonitoringDispatcher;
use App\Dispatchers\ReportDispatcher;
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

// Cleanup orphaned backup temp directories (from killed workers/crashes)
Schedule::command('backup:cleanup-temp')
    ->dailyAt('04:30')
    ->name('backup-temp-cleanup')
    ->onOneServer();

// Daily PostgreSQL dump (independent database backup)
Schedule::command('db:dump', ['--keep' => 7])
    ->dailyAt('02:30')
    ->name('database-dump')
    ->onOneServer();

// VACUUM ANALYZE — weekly Sunday 3 AM
Schedule::command('db:vacuum-analyze')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->name('vacuum-analyze')
    ->withoutOverlapping()
    ->onOneServer();

// Favicon backfill — daily
Schedule::command('sites:backfill-favicons')
    ->daily()
    ->name('favicon-backfill')
    ->withoutOverlapping()
    ->onOneServer();

// App backup scheduler
Schedule::command('app-backup:schedule-check')
    ->everyFifteenMinutes()
    ->name('scheduled-app-backups')
    ->withoutOverlapping()
    ->onOneServer();

// App backup cleanup
Schedule::command('app:backup-cleanup')
    ->dailyAt('04:00')
    ->name('expired-app-backup-cleanup')
    ->withoutOverlapping()
    ->onOneServer();

// Horizon health check
Schedule::command('horizon:health-check')
    ->everyFiveMinutes()
    ->name('horizon-health-check')
    ->withoutOverlapping()
    ->onOneServer();

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

// ==========================================================================
// Security Hardening
// ==========================================================================

// Cleanup stale security commands (picked_up >30min)
Schedule::command('security:maintenance stale-commands')
    ->everyFifteenMinutes()
    ->name('security-stale-commands-cleanup')
    ->withoutOverlapping()
    ->onOneServer();

// Prune old security activity logs
Schedule::command('security:maintenance prune-logs')
    ->dailyAt('03:30')
    ->name('security-activity-log-prune')
    ->onOneServer();

// Cleanup expired banned IPs
Schedule::command('security:maintenance expired-bans')
    ->hourly()
    ->name('security-expired-bans-cleanup')
    ->onOneServer();

// Recalculate all security hardening scores
Schedule::command('security:maintenance recalculate-scores')
    ->dailyAt('06:00')
    ->name('security-score-recalculation')
    ->onOneServer();
