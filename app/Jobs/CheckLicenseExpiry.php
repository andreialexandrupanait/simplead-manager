<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SitePlugin;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Faza 5.4 — premium license-expiry alerting.
 *
 * Once-daily orchestrator: sweeps every licensed plugin (on a connected site)
 * that carries an expiry date and alerts when it has expired or is within 30
 * days of expiring. Reuses the existing SitePlugin license helpers and the slim
 * notification path — this job adds ONLY the alert, not the detection/sync.
 *
 * Dedup is per-plugin via `license_alert_sent_at`: an already-alerted plugin is
 * re-alerted at most once a week (RE_ALERT_AFTER_DAYS) so the daily sweep never
 * spams the same expiry every morning. A renewed license (expiry pushed far into
 * the future) resets the marker so the NEXT expiry alerts afresh.
 */
class CheckLicenseExpiry implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    /**
     * Days within which an upcoming expiry is treated as a warning.
     */
    private const WARNING_THRESHOLD_DAYS = 30;

    /**
     * Suppress re-alerting the same plugin's expiry for this many days so the
     * daily sweep does not spam a still-unresolved expiry every morning.
     */
    private const RE_ALERT_AFTER_DAYS = 7;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'check-license-expiry';
    }

    public function handle(): void
    {
        SitePlugin::query()
            ->whereNotNull('license_expires_at')
            ->whereHas('site', fn ($q) => $q->where('is_connected', true))
            ->with('site')
            ->chunkById(200, function ($plugins): void {
                foreach ($plugins as $plugin) {
                    $this->processPlugin($plugin);
                }
            });
    }

    private function processPlugin(SitePlugin $plugin): void
    {
        $site = $plugin->site;

        if ($site === null) {
            return;
        }

        if ($plugin->isLicenseExpired()) {
            $severity = 'critical';
        } elseif ($plugin->isLicenseExpiring(self::WARNING_THRESHOLD_DAYS)) {
            $severity = 'warning';
        } else {
            // License renewed / not near expiry — clear any prior alert marker so a
            // future expiry alerts afresh, then skip.
            if ($plugin->license_alert_sent_at !== null) {
                $plugin->updateQuietly(['license_alert_sent_at' => null]);
            }

            return;
        }

        // Dedup: only alert when we've never alerted, or the last alert is stale.
        $lastAlert = $plugin->license_alert_sent_at;
        if ($lastAlert !== null && $lastAlert->gt(now()->subDays(self::RE_ALERT_AFTER_DAYS))) {
            return;
        }

        $expiresAt = $plugin->license_expires_at;
        $when = $expiresAt->isPast()
            ? 'a expirat '.$expiresAt->diffForHumans()
            : 'expiră '.$expiresAt->diffForHumans();

        $summary = "\xF0\x9F\x94\x91 Licență · *{$plugin->name}* pe *{$site->name}* — {$when}.";

        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'license_expiring',
            summary: $summary,
            deepLink: '<'.route('sites.plugins', $site).'|Open plugins →>',
            severity: $severity,
        );

        $plugin->updateQuietly(['license_alert_sent_at' => now()]);
    }
}
