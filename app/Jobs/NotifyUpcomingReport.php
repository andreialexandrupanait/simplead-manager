<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ReportSchedule;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyUpcomingReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ReportSchedule $schedule,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $site = $this->schedule->site;
        if (! $site) {
            return;
        }

        $scheduledDate = $this->schedule->next_run_at?->format('d/m/Y H:i') ?? 'soon';
        $reviewUrl = url("/sites/{$site->id}/reports");

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'report_reminder',
            title: 'Upcoming Report: '.$site->name,
            message: "Report for {$site->name} is scheduled for {$scheduledDate}. Review recommendations before generation.",
            fields: [
                'Site' => $site->name,
                'Scheduled' => $scheduledDate,
                'Review URL' => $reviewUrl,
            ],
            severity: 'info',
        );
    }
}
