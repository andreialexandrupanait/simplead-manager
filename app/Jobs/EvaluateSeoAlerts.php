<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\SeoAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EvaluateSeoAlerts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'seo-alerts-'.$this->site->id;
    }

    public function handle(): void
    {
        $triggered = app(SeoAlertService::class)->evaluateAlerts($this->site);

        if ($triggered > 0) {
            ActivityLogger::log(
                type: 'seo',
                severity: 'info',
                title: "SEO alerts evaluated for {$this->site->name}",
                description: "{$triggered} alert(s) triggered",
                site: $this->site,
                metadata: ['triggered' => $triggered],
                icon: 'bell-alert',
            );
        }
    }
}
