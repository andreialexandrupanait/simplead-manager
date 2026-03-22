<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class HorizonHealthCheckCommand extends Command
{
    protected $signature = 'horizon:health-check';
    protected $description = 'Check if Horizon supervisors are running and alert if not';

    public function handle(): void
    {
        $cacheKey = 'horizon_stopped_notified';

        try {
            $supervisors = app(MasterSupervisorRepository::class)->all();

            if (empty($supervisors)) {
                if (!Cache::has($cacheKey)) {
                    NotificationService::notifyAppEvent(
                        event: 'horizon_stopped',
                        title: 'Horizon Is Not Running',
                        message: 'No Horizon supervisor processes were found. Queue jobs are not being processed.',
                        severity: 'critical',
                    );
                    Cache::put($cacheKey, true, 3600);
                }
            } else {
                Cache::forget($cacheKey);
            }
        } catch (\Throwable $e) {
            Log::warning('Horizon health check failed: ' . $e->getMessage());
        }
    }
}
