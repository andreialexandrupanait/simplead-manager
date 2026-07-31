<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BackupConfig;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tell someone when a site stops being backed up.
 *
 * Failure alerting already works — BackupObserver watches `backups.status` for
 * a transition to `failed` and NotifyBackupFailed goes out. But that only ever
 * fires on a row that exists. A site whose backups quietly stopped being
 * dispatched, or that has never had one attempted at all, produces no
 * transition and therefore no alert, for as long as it takes anyone to look at
 * a screen. simplead.ro went 51 days that way.
 *
 * Three definitions of "stale" already existed in the UI (BackupsOverview,
 * DashboardService, SiteTodoService), all read-only and each carrying its own
 * hardcoded 36 hours. They read config now, and so does this — an alert that
 * disagrees with the screen it points at is worse than no alert.
 */
class CheckStaleBackups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $warnAfter = (int) config('backups.stale_after_hours', 36);
        $criticalAfter = (int) config('backups.stale_critical_after_hours', 72);
        $reAlertAfter = (int) config('backups.stale_realert_after_days', 3);

        $configs = BackupConfig::query()
            ->where('is_enabled', true)
            ->with('site')
            ->get();

        foreach ($configs as $config) {
            $site = $config->site;
            if (! $site instanceof Site || $site->trashed()) {
                continue;
            }

            $lastBackup = $site->last_backup_at;

            // Backed up recently: clear any marker so the next lapse alerts
            // afresh instead of staying silent behind a stale one.
            if ($lastBackup !== null && $lastBackup->gt(now()->subHours($warnAfter))) {
                if ($config->stale_alert_sent_at !== null) {
                    $config->updateQuietly(['stale_alert_sent_at' => null]);
                }

                continue;
            }

            // A site whose backup is merely late is a warning. One that has
            // never produced a backup at all is not late — it is unprotected,
            // and nobody has noticed since the day it was configured.
            if ($lastBackup === null) {
                $severity = 'critical';
                $summary = "*{$site->name}* has never produced a successful backup, and backups are enabled.";
            } else {
                $hours = (int) $lastBackup->diffInHours(now());
                $severity = $hours >= $criticalAfter ? 'critical' : 'warning';
                $summary = "*{$site->name}* has no backup since {$lastBackup->diffForHumans()}.";
            }

            // A daily sweep with NotificationService's five-minute dedup would
            // report the same site every morning until it was fixed, which is
            // how people learn to ignore a channel.
            $lastAlert = $config->stale_alert_sent_at;
            if ($lastAlert !== null && $lastAlert->gt(now()->subDays($reAlertAfter))) {
                continue;
            }

            NotificationService::notifySiteEventSlim(
                site: $site,
                event: 'backup_stale',
                summary: $summary,
                deepLink: '<'.route('sites.backups', $site).'|Open backups →>',
                severity: $severity,
            );

            $config->updateQuietly(['stale_alert_sent_at' => now()]);
        }
    }
}
