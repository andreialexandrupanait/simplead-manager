<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\UptimeAlertMail;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Renders (or sends) an uptime alert without waiting for a site to fall over.
 *
 * Nothing is written to the database: the incident, monitor and site are built
 * in memory, so this is safe to run against production when checking how a
 * template renders in a real inbox.
 */
class PreviewUptimeMailCommand extends Command
{
    protected $signature = 'mail:preview-uptime
        {--type=down : down or up}
        {--site= : ID of a real site to render against, instead of a fabricated one}
        {--cause= : Override the failure reason, e.g. "HTTP 502"}
        {--format=html : html or text — which alternative to print}
        {--send= : Send the rendered alert to this address instead of printing it}';

    protected $description = 'Render or send an uptime DOWN/UP alert email with sample data';

    public function handle(): int
    {
        $type = (string) $this->option('type');
        if (! in_array($type, ['down', 'up'], true)) {
            $this->error('--type must be "down" or "up".');

            return self::FAILURE;
        }

        $incident = $this->buildIncident($type);
        if ($incident === null) {
            return self::FAILURE;
        }

        $mailable = new UptimeAlertMail($incident, $type);

        $address = $this->option('send');
        if ($address === null) {
            $this->line($this->option('format') === 'text'
                ? view('mail.uptime-alert-text', $mailable->payload())->render()
                : $mailable->render());

            return self::SUCCESS;
        }

        // Mirror the production path so the greeting, timezone and locale are
        // resolved exactly as they would be for a real send.
        $mailable->withRecipient(
            (string) $address,
            User::whereRaw('lower(email) = ?', [mb_strtolower((string) $address)])->first(),
            $this->previewChannel(),
        );

        Mail::to((string) $address)->send($mailable);
        $this->info("Sent the {$type} alert to {$address}.");

        return self::SUCCESS;
    }

    private function buildIncident(string $type): ?UptimeIncident
    {
        $siteId = $this->option('site');

        if ($siteId !== null) {
            $site = Site::find($siteId);
            if ($site === null) {
                $this->error("No site with ID {$siteId}.");

                return null;
            }
            $monitor = $site->uptimeMonitor ?? $this->fabricatedMonitor($site->url);
        } else {
            $site = new Site;
            $site->forceFill(['id' => 1, 'name' => 'Example Shop', 'url' => 'https://www.example-shop.de/']);
            $monitor = $this->fabricatedMonitor('https://www.example-shop.de/');
        }

        $monitor->setRelation('site', $site);

        $startedAt = now()->subMinutes(4);

        $incident = new UptimeIncident;
        $incident->forceFill([
            'id' => 1,
            'status' => $type === 'down' ? 'ongoing' : 'resolved',
            'cause' => (string) ($this->option('cause') ?? 'HTTP 502 (confirmed from FRA)'),
            'started_at' => $startedAt,
            'resolved_at' => $type === 'down' ? null : $startedAt->copy()->addMinutes(4),
        ]);
        $incident->setRelation('monitor', $monitor);

        return $incident;
    }

    private function fabricatedMonitor(string $url): UptimeMonitor
    {
        $monitor = new UptimeMonitor;
        $monitor->forceFill(['id' => 1, 'url' => $url, 'type' => 'http', 'timeout' => 30]);

        return $monitor;
    }

    private function previewChannel(): NotificationChannel
    {
        $channel = new NotificationChannel;
        $channel->forceFill(['id' => 1, 'name' => 'Preview', 'type' => 'email']);

        return $channel;
    }
}
