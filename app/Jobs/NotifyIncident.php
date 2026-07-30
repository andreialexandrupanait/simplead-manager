<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\UptimeAlertMail;
use App\Models\UptimeIncident;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyIncident implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public UptimeIncident $incident,
        public string $type // 'down' or 'recovery'
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $monitor = $this->incident->monitor;
        $site = $monitor->site;

        $isDown = $this->type === 'down';

        // P1-24: honour the per-install notify_down / notify_recovery toggles.
        // Defaults preserve the previous behaviour (both on) so nothing that was
        // alerting before goes quiet after this change.
        $settings = app(SettingsService::class);
        $settingKey = $isDown ? 'notify_down' : 'notify_recovery';
        if (! (bool) $settings->get($settingKey, true)) {
            return;
        }

        // P1-05: emit the canonical `site_recovered` event (present in
        // NotificationTemplate::EVENTS + the subscription UI) instead of the
        // orphaned `site_up`, which no template/subscription ever matched.
        // NotificationChannel::subscribedTo() aliases the two so legacy jsonb
        // subscription rows keep working.
        $event = $isDown ? 'site_down' : 'site_recovered';
        $severity = $isDown ? 'critical' : 'success';

        // Written as sentences, not Slack log lines. The in-app notification is
        // built by stripping the mrkdwn out of this string, so anything terse
        // here reads as terse there — and "🔴 Site down · *name* — cause" is not
        // something a person says.
        if ($isDown) {
            $cause = $this->incident->cause;
            $summary = $cause
                ? "Your site *{$site->domain}* has gone down. {$cause}"
                : "Your site *{$site->domain}* has gone down.";
            $deepLink = '<'.route('sites.uptime', $site).'|Open uptime →>';
        } else {
            $summary = "Your site *{$site->domain}* is back up. It was down for {$this->incident->duration}.";
            $deepLink = null;
        }

        $webhookPayload = [
            'monitor' => [
                'url' => $monitor->url,
                'type' => $monitor->type,
            ],
            'incident' => [
                'cause' => $this->incident->cause,
                'started_at' => $this->incident->started_at->toIso8601String(),
                'resolved_at' => $this->incident->resolved_at?->toIso8601String(),
                'duration' => $this->incident->duration,
            ],
        ];

        $channelIds = $monitor->alert_contacts ?: null;

        NotificationService::notifySiteEventSlim(
            site: $site,
            event: $event,
            summary: $summary,
            deepLink: $deepLink,
            severity: $severity,
            webhookPayload: $webhookPayload,
            mailableClass: UptimeAlertMail::class,
            mailableArgs: [$this->incident, $this->type],
            channelIds: $channelIds,
        );

        // Track which channels were notified
        $notifiedVia = $this->incident->notified_via ?? [];
        $notifiedVia[] = 'dispatched';

        $this->incident->update([
            'notified_via' => array_unique($notifiedVia),
            'notified_at' => now(),
        ]);
    }
}
