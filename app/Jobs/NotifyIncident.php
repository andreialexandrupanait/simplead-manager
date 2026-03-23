<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\UptimeAlertMail;
use App\Models\UptimeIncident;
use App\Services\Notifications\NotificationService;
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
        $event = $isDown ? 'site_down' : 'site_up';
        $severity = $isDown ? 'critical' : 'success';
        $title = $isDown ? "DOWN: {$site->name}" : "RECOVERED: {$site->name}";
        $message = $isDown
            ? "{$site->name} is not responding."
            : "{$site->name} has recovered.";

        $fields = [
            ['title' => 'URL', 'value' => $monitor->url, 'short' => true],
            ['title' => 'Cause', 'value' => $this->incident->cause ?? 'Unknown', 'short' => true],
            ['title' => 'Duration', 'value' => $this->incident->duration, 'short' => true],
        ];

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

        // Use monitor-specific contacts if defined
        $channelIds = null;
        if ($monitor->alert_contacts && count($monitor->alert_contacts) > 0) {
            $channelIds = $monitor->alert_contacts;
        }

        NotificationService::notifySiteEvent(
            site: $site,
            event: $event,
            title: $title,
            message: $message,
            fields: $fields,
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
