<?php

use App\Dispatchers\BackupDispatcher;
use App\Dispatchers\DataSyncDispatcher;
use App\Dispatchers\MonitoringDispatcher;
use App\Dispatchers\ReportDispatcher;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Schedule;

// ==========================================================================
// P1-36: scheduled-task failure alerting
// ==========================================================================
// Critical scheduled tasks (db:dump, backup verification, app backups, retention,
// vulnerability scan, monthly aggregation) previously failed SILENTLY — a non-zero
// exit or thrown exception only reached the log, never an operator. `criticalFailureAlert`
// returns an ->onFailure() callback that fires a critical app-event notification.
// The dedup key is namespaced per task so simultaneous failures of different tasks
// are not collapsed into a single alert (NotificationService::isDuplicate keys on event).
$criticalFailureAlert = static function (string $task): Closure {
    return static function () use ($task): void {
        NotificationService::notifyAppEvent(
            event: 'scheduled_task_failed:'.$task,
            title: 'Scheduled task failed',
            message: "The critical scheduled task \"{$task}\" failed (non-zero exit / exception). Check the scheduler logs.",
            fields: ['task' => $task],
            severity: 'critical',
        );
    };
};

// ==========================================================================
// Core Dispatchers (4)
// ==========================================================================

// Monitoring: uptime checks, SSL checks, security scans
//
// P1-53: high-frequency tasks use a 10-minute mutex TTL instead of the framework
// default of 24h. A scheduler killed with SIGKILL never releases its mutex, so a
// bare withoutOverlapping() would silently wedge these dispatchers for a full day
// (no uptime/security/DNS/backup dispatch) while the heartbeat stays green. The
// scheduler's stop_grace_period (docker-compose.coolify.yml) is the graceful side of
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

// Daily health score snapshot
Schedule::job(new \App\Jobs\RecordHealthScores)->dailyAt('01:00')->name('daily-health-scores')->onOneServer();

// P3-14: recompute the long uptime window (uptime_365d) once daily instead of on
// every check, bounded honestly to the retained uptime-checks coverage.
Schedule::job(new \App\Jobs\AggregateUptimeWindows)->dailyAt('01:30')->name('aggregate-uptime-windows')->onOneServer();

// SPEC §14.1: fold raw pings into hourly buckets kept 13 months. Hourly, and well
// clear of the 03:00 retention run — pings for hours not yet folded up would
// otherwise be pruned before they were ever aggregated.
Schedule::job(new \App\Jobs\AggregateUptimeHourly)
    ->hourlyAt(5)
    ->name('aggregate-uptime-hourly')
    ->onOneServer();

// SPEC §14.1: raw PHP errors live 30 days, the aggregate 13 months. Ahead of the
// 03:00 retention run for the same reason as the uptime fold above.
Schedule::job(new \App\Jobs\AggregatePhpErrorsMonthly)
    ->dailyAt('01:45')
    ->name('aggregate-php-errors-monthly')
    ->onOneServer();

// ==========================================================================
// Monthly Aggregation
// ==========================================================================

Schedule::job(new \App\Jobs\AggregateMonthlySnapshots)
    ->monthlyOn(1, '02:00')
    ->name('monthly-aggregation')
    ->onOneServer()
    ->onFailure($criticalFailureAlert('monthly-aggregation'));

// ==========================================================================
// Retention Cleanup
// ==========================================================================

Schedule::job(new \App\Jobs\RetentionCleanup)
    ->dailyAt('03:00')
    ->name('retention-cleanup')
    ->onOneServer()
    ->onFailure($criticalFailureAlert('retention-cleanup'));

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
    ->onOneServer()
    ->onFailure($criticalFailureAlert('database-dump'));

// Push the daily dump off-host so the platform's own DB backup survives loss
// of the app host. Runs after db:dump; alerts critically if no remote
// destination is configured or the push cannot be verified.
Schedule::command('db:dump-offsite')
    ->dailyAt('02:45')
    ->name('database-dump-offsite')
    ->withoutOverlapping()
    ->onOneServer()
    // Belt-and-braces: the command self-alerts on handled failures, but a hard
    // crash before its own try/catch (e.g. DI/boot error) would still be silent.
    ->onFailure($criticalFailureAlert('database-dump-offsite'));

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
    ->onOneServer()
    ->onFailure($criticalFailureAlert('scheduled-app-backups'));

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

// Weekly Level B backup verification — sample N recent backups, full integrity
// re-check. Sample size is config-driven (backups.level_b_sample_size) inside the
// command so it can scale with the fleet (P2-33).
Schedule::command('backup:verify-restore')
    ->weeklyOn(0, '03:00')
    ->name('backup-verify-restore-weekly')
    ->withoutOverlapping()
    ->onOneServer()
    ->onFailure($criticalFailureAlert('backup-verify-restore-weekly'));

// C-08: proven restore — actually restore a pilot site's latest backup into the
// isolated sandbox WP and health-check it (stronger than the offline integrity
// verify above). Staggered after it so the two heavy jobs don't collide.
Schedule::job(new \App\Jobs\RunProvenRestore)
    ->weeklyOn(0, '04:30')
    ->name('proven-restore-weekly')
    ->withoutOverlapping()
    ->onOneServer()
    ->onFailure($criticalFailureAlert('proven-restore-weekly'));

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

// SPEC §11 — pull per-site tasks from app.simplead.ro (read-only). No-op until
// SIMPLEAD_APP_TOKEN is configured.
Schedule::job(new \App\Jobs\SyncSiteTasks)
    ->dailyAt('06:15')
    ->name('sync-site-tasks')
    ->withoutOverlapping()
    ->onOneServer();

// Daily vulnerability check across all sites (Wordfence Intelligence API)
Schedule::job(new \App\Jobs\CheckPluginVulnerabilities)
    ->dailyAt('05:00')
    ->name('daily-vulnerability-check')
    ->onOneServer()
    ->onFailure($criticalFailureAlert('daily-vulnerability-check'));

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

// Faza 5.4 — premium license-expiry alerting. Cheap once-daily sweep of licensed
// plugins carrying an expiry date; alerts (with per-plugin weekly dedup) when a
// license has expired or is within 30 days of expiring.
Schedule::job(new \App\Jobs\CheckLicenseExpiry)
    ->dailyAt('06:00')
    ->name('check-license-expiry')
    ->onOneServer();

// A site that stops being backed up produces no failed row, so the observer that
// alerts on backup failures never sees it. Nothing noticed simplead.ro going 51
// days without one. Runs after the overnight backup window so a site that ran at
// 03:00 is not reported at 07:00 for missing the night before.
Schedule::job(new \App\Jobs\CheckStaleBackups)
    ->dailyAt('07:30')
    ->name('check-stale-backups')
    ->withoutOverlapping(10)
    ->onOneServer();

// Backfill DNS monitors for any connected site still missing one — a safety net
// for sites onboarded before DNS was a plan default (P1-56). The command only
// touches sites with no monitor, so this is idempotent.
Schedule::command('dns:backfill-monitors')
    ->dailyAt('04:45')
    ->name('dns-backfill-monitors')
    ->withoutOverlapping()
    ->onOneServer();

// The same safety net for the backup engine. Installing it was only ever possible
// by hand from a console nothing linked to, so a site connected after the fleet
// migration stayed on the old engine silently — which is how m1-beauty.ro came to
// take its first backup on V1 weeks after the fleet had left it. Idempotent: it
// only touches connected sites that are ready and still missing the engine, and it
// neither pushes the connector nor starts a schedule on its own.
Schedule::command('backup:backfill-engine')
    ->dailyAt('04:15')
    ->name('backup-engine-backfill')
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

// Faza 5.3 — broken-link sweep (connector /content-urls + Manager HEAD-check, diff-based).
//
// 00:30 on the 1st, not 02:00: SPEC §5.7 wants the sweep to finish BEFORE the
// month's report is generated, and 02:00 was a straight collision with the monthly
// aggregation below — two long jobs starting on the same minute.
Schedule::job(new \App\Jobs\CheckBrokenLinks)
    ->monthlyOn(1, '00:30')
    ->name('check-broken-links')
    ->onOneServer();

// Faza 5.2 — WooCommerce health (only sites with Woo detected; skips cleanly otherwise).
Schedule::job(new \App\Jobs\CheckWooHealth)
    ->dailyAt('05:30')
    ->name('check-woo-health')
    ->onOneServer();

// SPEC §5.4 — the real weekly contact-form submission. The service behind it had
// no caller at all until now. Monday morning, so a form that broke over the
// weekend is found before the week's enquiries are lost.
Schedule::job(new \App\Jobs\CheckContactForms)
    ->weeklyOn(1, '06:30')
    ->name('check-contact-forms')
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

// The same job for backup sessions, plus the attendant for parked ones. Kept
// separate from the V1 stuck sweep on purpose: that one "recovers" a backup by
// dispatching CreateBackup, which on a site running the new engine would put the
// old one on top of a live session. A session parked in retry_wait is only
// honest if something comes back for it — without this, retry_wait is the same
// silent stall as a session frozen at `uploading`, with a nicer name.
Schedule::command('backups:recover-stuck-sessions')
    ->everyFifteenMinutes()
    ->name('recover-stuck-backup-sessions')
    ->onOneServer();

// Recompute what each storage destination actually holds. V1 retention adjusts
// used_bytes incrementally on every delete, and quota enforcement reads the same
// number — so without a periodic recount the figure quotas are enforced on drifts
// away from the truth and nothing ever corrects it. Inert unless the engine is
// enabled; it only ever writes the reconciled total.
Schedule::call(function (): void {
    \App\Models\StorageDestination::query()
        ->where('is_active', true)
        ->pluck('id')
        ->each(fn (int $id) => \App\Backup\V2\Jobs\ReconcileUsedBytesJob::dispatch($id));
})
    ->weeklyOn(1, '02:30')
    ->name('reconcile-storage-used-bytes')
    ->onOneServer();

// P2-27: recover safe updates whose worker died mid-pipeline without running
// failed() — a row stuck in an intermediate state (backing_up/updating/
// health_checking/rolling_back) otherwise wedges the site's safe-update slot and
// the UI polls it forever. The 30-min threshold is well above RunSafeUpdate's
// 600s timeout, so anything it catches is genuinely dead, not slow.
Schedule::command('safe-updates:recover-stuck')
    ->everyFifteenMinutes()
    ->name('recover-stuck-safe-updates')
    ->onOneServer();

// P2-05: recover reports whose worker died mid-render without running failed() —
// an over-budget Gotenberg render that blows the job timeout leaves the row stuck
// in 'generating' and the UI shows it in progress forever. The 45-min threshold is
// above GenerateReport's timeout × tries envelope, so anything caught is dead.
Schedule::command('reports:recover-stuck')
    ->everyFifteenMinutes()
    ->name('recover-stuck-reports')
    ->onOneServer();
