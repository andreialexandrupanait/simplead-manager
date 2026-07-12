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

        if ($isDown) {
            $cause = $this->incident->cause ?? 'unknown cause';
            $summary = "\xF0\x9F\x94\xB4 Site down · *{$site->name}* — {$cause}";
            $deepLink = '<'.route('sites.uptime', $site).'|Open uptime →>';
        } else {
            $summary = "\xE2\x9C\x85 *{$site->name}* recovered after {$this->incident->duration}";
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
