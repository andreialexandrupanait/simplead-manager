<?php

use App\Dispatchers\BackupDispatcher;
use App\Dispatchers\BrokenResourceDispatcher;
use App\Dispatchers\DataSyncDispatcher;
use App\Dispatchers\IncidentResponseDispatcher;
use App\Dispatchers\MonitoringDispatcher;
use App\Dispatchers\ReportDispatcher;
use App\Dispatchers\SeoAuditDispatcher;
use Illuminate\Support\Facades\Schedule;

// ==========================================================================
// Core Dispatchers (4)
// ==========================================================================

// Monitoring: uptime checks, SSL checks, security scans
//
// P1-53: high-frequency tasks use a 10-minute mutex TTL instead of the framework
// default of 24h. A scheduler killed with SIGKILL never releases its mutex, so a
// bare withoutOverlapping() would silently wedge these dispatchers for a full day
// (no uptime/security/DNS/backup dispatch) while the heartbeat stays green. The
// scheduler's stop_grace_period (docker-compose.prod.yml) is the graceful side of
// the same fix. 10 min comfortably exceeds every dispatch-only task's runtime.
Schedule::call(new MonitoringDispatcher)
    ->everyMinute()
    ->name('monitoring-dispatcher')
    ->withoutOverlapping(10)
    ->onOneServer();

// Data Sync: analytics, search console, cloudflare, WP sync
Schedule::call(new DataSyncDispatcher)
    ->everyMinute()
    ->name('data-sync-dispatcher')
    ->withoutOverlapping(10)
    ->onOneServer();

// Backups: site backups + app backups
Schedule::call(new BackupDispatcher)
    ->everyMinute()
    ->name('backup-dispatcher')
    ->withoutOverlapping(10)
    ->onOneServer();

// Reports: scheduled report generation
Schedule::call(new ReportDispatcher)
    ->everyFiveMinutes()
    ->name('report-dispatcher')
    ->withoutOverlapping(10)
    ->onOneServer();

// Incident Response: proactive security/vulnerability detection
Schedule::call(new IncidentResponseDispatcher)
    ->everyFiveMinutes()
    ->name('incident-response-dispatcher')
    ->withoutOverlapping(10)
    ->onOneServer();

// Belt-and-braces for SEC-A2-11: a kill -9'd worker never runs failed(), so
// sweep incidents stuck non-terminal past 2× the job timeout (900s) — they
// silently extend the cooldown window otherwise.
Schedule::call(function () {
    \App\Models\IncidentResponse::whereIn('status', [
        \App\Enums\IncidentResponseStatus::Pending,
        \App\Enums\IncidentResponseStatus::Diagnosing,
        \App\Enums\IncidentResponseStatus::Executing,
    ])
        ->where('created_at', '<', now()->subMinutes(30))
        ->each(fn ($incident) => $incident->markFailed('Stale — worker died without cleanup (auto-swept).'));
})
    ->everyFifteenMinutes()
    ->name('incident-response-stale-sweep')
    ->onOneServer();

Schedule::call(new SeoAuditDispatcher)->everyFiveMinutes()->name('seo-audit-dispatcher')->withoutOverlapping(10)->onOneServer();

// Daily broken links/images re-check (lightweight, no re-crawl)
Schedule::call(new BrokenResourceDispatcher)->dailyAt('02:00')->name('broken-resource-dispatcher')->withoutOverlapping()->onOneServer();

// Daily keyword ranking fetch from Search Console
Schedule::call(function () {
    \App\Models\Site::query()
        ->whereHas('searchConsoleConnection', fn ($q) => $q->where('is_active', true))
        ->each(fn ($site) => \App\Jobs\FetchKeywordRankings::dispatch($site)->delay(now()->addSeconds(rand(0, 60))));
})->dailyAt('04:00')->name('keyword-rankings-fetch')->onOneServer();

// Daily health score snapshot
Schedule::job(new \App\Jobs\RecordHealthScores)->dailyAt('01:00')->name('daily-health-scores')->onOneServer();

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

// Push the daily dump off-host so the platform's own DB backup survives loss
// of the app host. Runs after db:dump; alerts critically if no remote
// destination is configured or the push cannot be verified.
Schedule::command('db:dump-offsite')
    ->dailyAt('02:45')
    ->name('database-dump-offsite')
    ->withoutOverlapping()
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

// Recover app backups whose worker died without cleanup (P1-38) — the
// heartbeat threshold exceeds CreateAppBackup's 1800s timeout, so anything it
// catches is genuinely dead and would otherwise wedge all future app backups.
Schedule::command('app-backups:recover-stuck')
    ->everyFifteenMinutes()
    ->name('recover-stuck-app-backups')
    ->onOneServer();

// App backup cleanup
Schedule::command('app:backup-cleanup')
    ->dailyAt('04:00')
    ->name('expired-app-backup-cleanup')
    ->withoutOverlapping()
    ->onOneServer();

// Weekly Level B backup verification — sample 3 recent backups, full integrity re-check
Schedule::command('backup:verify-restore --count=3')
    ->weeklyOn(0, '03:00')
    ->name('backup-verify-restore-weekly')
    ->withoutOverlapping()
    ->onOneServer();

// Horizon health check
Schedule::command('horizon:health-check')
    ->everyFiveMinutes()
    ->name('horizon-health-check')
    ->withoutOverlapping(10)
    ->onOneServer();

// P1-06: near-limit OOM alert. Runs inside the Horizon container (dispatched to a
// queue Horizon processes) and reads that container's own cgroup memory usage,
// alerting before a cgroup OOM SIGKILL can kill a worker mid-backup/restore.
Schedule::job(new \App\Jobs\MonitorHorizonMemory)
    ->everyFiveMinutes()
    ->name('horizon-memory-monitor')
    ->onOneServer();

// P1-09: recover performance tests stuck in 'running' after a SIGKILL'd worker
// skipped the job's failed() hook — otherwise the UI polls those rows forever.
Schedule::command('performance:recover-stuck-tests')
    ->everyFifteenMinutes()
    ->name('recover-stuck-performance-tests')
    ->onOneServer();

// External heartbeat (dead-man's switch) — pings every minute so an external
// monitor alerts if the scheduler/app goes dark. No-op unless configured.
Schedule::command('monitoring:heartbeat')
    ->everyMinute()
    ->name('scheduler-heartbeat')
    ->withoutOverlapping(10);

// PHP error log fetch — every 6 hours across all sites
Schedule::call(function () {
    \App\Models\Site::where('is_connected', true)->each(function ($site) {
        \App\Jobs\FetchPhpErrorLogs::dispatch($site)->delay(now()->addSeconds(rand(0, 120)));
    });
})->everySixHours()
    ->name('php-error-log-fetch')
    ->withoutOverlapping()
    ->onOneServer();

// Daily vulnerability check across all sites (Wordfence Intelligence API)
Schedule::job(new \App\Jobs\CheckPluginVulnerabilities)
    ->dailyAt('05:00')
    ->name('daily-vulnerability-check')
    ->onOneServer();

// Domain-registration expiry (RDAP) — re-check each site weekly, staggered.
Schedule::call(function () {
    $queued = 0;
    \App\Models\Site::query()
        ->where(fn ($q) => $q->whereNull('domain_checked_at')->orWhere('domain_checked_at', '<', now()->subDays(7)))
        ->each(function (\App\Models\Site $site) use (&$queued) {
            \App\Jobs\CheckDomainExpiry::dispatch($site)->delay(now()->addSeconds($queued * 15));
            $queued++;
        });
})->dailyAt('04:30')
    ->name('domain-expiry-check')
    ->withoutOverlapping()
    ->onOneServer();

// Validate external connections (Google, Cloudflare, Dropbox, WordPress)
Schedule::job(new \App\Jobs\ValidateExternalConnections)
    ->dailyAt('06:00')
    ->name('validate-external-connections')
    ->onOneServer();

// Reconnect probe (self-healing) — read-only getInfo() against every site
// flagged disconnected; a recovered site is flipped back to connected within
// the hour so a transient sync failure never permanently halts its pipelines.
Schedule::command('sites:reconnect-probe')
    ->hourly()
    ->name('sites-reconnect-probe')
    ->withoutOverlapping()
    ->onOneServer();

// Process buffered notifications (grouping/batching)
Schedule::job(new \App\Jobs\ProcessNotificationBatch)
    ->everyMinute()
    ->name('process-notification-batch')
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::job(new \App\Jobs\ProcessNotificationEscalations)
    ->everyFiveMinutes()
    ->name('process-notification-escalations')
    ->onOneServer()
    ->withoutOverlapping(10);

// Release notifications deferred during quiet hours (P1-21) once the window ends.
Schedule::job(new \App\Jobs\FlushDeferredNotifications)
    ->everyMinute()
    ->name('flush-deferred-notifications')
    ->onOneServer()
    ->withoutOverlapping(10);

// Daily health digest email
Schedule::job(new \App\Jobs\SendDailyDigest)
    ->dailyAt('07:00')
    ->name('daily-digest')
    ->onOneServer();

// ==========================================================================
// Security Hardening
// ==========================================================================

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

// Recover restores whose worker died without cleanup (audit E-23) — the
// heartbeat threshold exceeds RestoreBackup's 3600s timeout, so anything it
// catches is genuinely dead, not slow.
Schedule::command('backups:recover-stuck-restores')
    ->everyFifteenMinutes()
    ->name('recover-stuck-restores')
    ->onOneServer();
