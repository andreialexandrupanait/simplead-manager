<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use App\Services\SeoReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateSeoReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [60, 120];

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'seo-report-'.$this->site->id;
    }

    public function handle(): void
    {
        $service = app(SeoReportService::class);

        $periodEnd = now();
        $periodStart = now()->subDays(30);

        $data = $service->generateReport($this->site, $periodStart, $periodEnd);

        NotificationService::notifySiteEvent(
            site: $this->site,
            event: 'seo_report_ready',
            title: 'SEO Report Generated',
            message: "Monthly SEO report for {$this->site->name} is ready. Score: {$data['audit']['score']}/100.",
            fields: [
                'Score' => $data['audit'] ? "{$data['audit']['score']}/100" : 'N/A',
                'Issues' => $data['audit'] ? (string) $data['audit']['total_issues'] : '0',
                'Keywords Tracked' => (string) count($data['keywords']),
            ],
            severity: 'info',
        );

        ActivityLogger::log(
            type: 'seo',
            severity: 'info',
            title: "SEO report generated for {$this->site->name}",
            description: 'Monthly SEO report has been generated and notification sent.',
            site: $this->site,
            metadata: ['score' => $data['audit']['score'] ?? null, 'issues' => $data['audit']['total_issues'] ?? 0],
            icon: 'document-chart-bar',
        );
    }

    public function failed(?\Throwable $exception): void
    {
        ActivityLogger::log(
            type: 'seo',
            severity: 'warning',
            title: "SEO report generation failed for {$this->site->name}",
            description: $exception?->getMessage() ?? 'Unknown error',
            site: $this->site,
            metadata: ['error' => $exception?->getMessage()],
            icon: 'document-chart-bar',
        );
    }
}
