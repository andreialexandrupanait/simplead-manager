<?php

namespace App\Jobs;

use App\Mail\BrokenLinksAlertMail;
use App\Models\LinkMonitor;
use App\Models\LinkScan;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyBrokenLinks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public LinkMonitor $monitor,
        public LinkScan $scan,
        public int $brokenCount,
    ) {}

    public function handle(): void
    {
        $site = $this->monitor->site;

        $title = "BROKEN LINKS: {$this->brokenCount} found on {$site->name}";
        $message = "{$this->brokenCount} broken links were found during a scan of {$site->name}.";

        $fields = [
            ['title' => 'Site', 'value' => $site->name, 'short' => true],
            ['title' => 'Broken Links', 'value' => (string) $this->brokenCount, 'short' => true],
            ['title' => 'Total Links', 'value' => (string) $this->scan->total_links, 'short' => true],
            ['title' => 'Pages Scanned', 'value' => (string) $this->scan->pages_scanned, 'short' => true],
        ];

        $webhookPayload = [
            'scan' => [
                'broken_links' => $this->brokenCount,
                'total_links' => $this->scan->total_links,
                'redirects' => $this->scan->redirects,
                'pages_scanned' => $this->scan->pages_scanned,
                'duration_seconds' => $this->scan->duration_seconds,
            ],
        ];

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'broken_links',
            title: $title,
            message: $message,
            fields: $fields,
            severity: 'warning',
            webhookPayload: $webhookPayload,
            mailableClass: BrokenLinksAlertMail::class,
            mailableArgs: [$this->monitor, $this->scan, $this->brokenCount],
        );
    }
}
