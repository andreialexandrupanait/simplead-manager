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

        $summary = "\xF0\x9F\x93\x89 *{$site->name}* {$this->device} score {$this->previousScore}\xE2\x86\x92{$this->currentScore} (-{$drop})";
        $deepLink = '<'.route('sites.performance', $site).'|Open performance →>';

        $webhookPayload = [
            'device' => $this->device,
            'previous_score' => $this->previousScore,
            'current_score' => $this->currentScore,
            'drop' => $drop,
        ];

        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'performance_drop',
            summary: $summary,
            deepLink: $deepLink,
            severity: 'warning',
            webhookPayload: $webhookPayload,
            mailableClass: PerformanceAlertMail::class,
            mailableArgs: [$this->monitor, $this->device, $this->previousScore, $this->currentScore],
        );
    }
}
