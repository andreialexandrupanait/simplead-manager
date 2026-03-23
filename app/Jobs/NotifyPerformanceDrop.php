<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\PerformanceAlertMail;
use App\Models\PerformanceMonitor;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyPerformanceDrop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public PerformanceMonitor $monitor,
        public string $device,
        public int $previousScore,
        public int $currentScore
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        /** @var Site $site */
        $site = $this->monitor->site;
        $drop = $this->previousScore - $this->currentScore;

        $title = "PERFORMANCE DROP: {$site->name} ({$this->device})";
        $message = ucfirst($this->device)." performance score dropped by {$drop} points (from {$this->previousScore} to {$this->currentScore}).";

        $fields = [
            ['title' => 'Site', 'value' => $site->name, 'short' => true],
            ['title' => 'Device', 'value' => ucfirst($this->device), 'short' => true],
            ['title' => 'Previous Score', 'value' => (string) $this->previousScore, 'short' => true],
            ['title' => 'Current Score', 'value' => (string) $this->currentScore, 'short' => true],
            ['title' => 'Drop', 'value' => "-{$drop} points", 'short' => true],
        ];

        $webhookPayload = [
            'device' => $this->device,
            'previous_score' => $this->previousScore,
            'current_score' => $this->currentScore,
            'drop' => $drop,
        ];

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'performance_drop',
            title: $title,
            message: $message,
            fields: $fields,
            severity: 'warning',
            webhookPayload: $webhookPayload,
            mailableClass: PerformanceAlertMail::class,
            mailableArgs: [$this->monitor, $this->device, $this->previousScore, $this->currentScore],
        );
    }
}
