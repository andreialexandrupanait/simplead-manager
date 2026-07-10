<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HealthScoreHistory;
use App\Models\Site;
use App\Services\HealthScoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RecordHealthScores implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'record-health-scores';
    }

    public function handle(): void
    {
        $today = now()->toDateString();

        Site::where('is_connected', true)
            ->with(['uptimeMonitor', 'securityMonitor', 'performanceMonitor'])
            ->each(function (Site $site) use ($today): void {
                try {
                    $breakdown = HealthScoreService::calculate($site);
                    $components = $breakdown['components'];

                    HealthScoreHistory::upsert(
                        [
                            [
                                'site_id' => $site->id,
                                'score' => $breakdown['total'],
                                'uptime_score' => $components['uptime']['score'],
                                'security_score' => $components['security']['score'],
                                'updates_score' => $components['updates']['score'],
                                'performance_score' => $components['performance']['score'],
                                'recorded_at' => $today,
                                'created_at' => now(),
                            ],
                        ],
                        uniqueBy: ['site_id', 'recorded_at'],
                        update: ['score', 'uptime_score', 'security_score', 'updates_score', 'performance_score'],
                    );

                    // Persist the current score onto the site so the dashboard
                    // health filter/sort and the /v1 API stop running on NULL.
                    // updateQuietly avoids firing Site::saved for every site
                    // (which would stampede the dashboard cache nightly).
                    $site->updateQuietly(['health_score' => (int) $breakdown['total']]);
                } catch (\Throwable $e) {
                    Log::warning('RecordHealthScores: failed for site', [
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
