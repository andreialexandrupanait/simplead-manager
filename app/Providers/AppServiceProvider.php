<?php

namespace App\Providers;

use App\Services\Notifications\NotificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\LongWaitDetected;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = $request->input('email', '') . '|' . $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('status-page', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Horizon long wait alert
        $this->app['events']->listen(LongWaitDetected::class, function (LongWaitDetected $event) {
            NotificationService::notifyAppEvent(
                event: 'horizon_long_wait',
                title: 'Horizon: Long Queue Wait Detected',
                message: "Queue '{$event->queue}' on connection '{$event->connection}' has a long wait time.",
                severity: 'warning',
            );
        });

        // Job failure tracking — notify on 3rd failure within an hour
        Queue::failing(function (JobFailed $event) {
            $jobClass = get_class($event->job->resolveName());
            $cacheKey = "job_failures:{$jobClass}";

            $failures = Cache::get($cacheKey, 0) + 1;
            Cache::put($cacheKey, $failures, 3600);

            if ($failures === 3) {
                NotificationService::notifyAppEvent(
                    event: 'job_failures',
                    title: 'Repeated Job Failures',
                    message: "{$jobClass} has failed {$failures} times in the last hour.",
                    fields: ['exception' => $event->exception->getMessage()],
                    severity: 'critical',
                );
            }

            Log::error("Job failed: {$jobClass}", [
                'exception' => $event->exception->getMessage(),
                'failures_in_hour' => $failures,
            ]);
        });
    }
}
