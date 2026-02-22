<?php

namespace App\Providers;

use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\LongWaitDetected;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\SettingsService::class);
        $this->app->singleton(\App\Services\DashboardService::class);
        $this->app->singleton(\App\Services\GotenbergService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        RateLimiter::for('login', function (Request $request) {
            $key = $request->input('email', '') . '|' . $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('authenticated', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('status-page', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('status-page-auth', function (Request $request) {
            $slug = $request->route('slug', 'unknown');
            return Limit::perMinute(5)->by($slug . '|' . $request->ip());
        });

        // Load Google API credentials from DB if not set in env
        $this->app->booted(function () {
            try {
                $settings = app(SettingsService::class);

                if (empty(config('services.dropbox.app_key'))) {
                    config(['services.dropbox.app_key' => $settings->get('dropbox_app_key')]);
                }
                if (empty(config('services.dropbox.app_secret'))) {
                    $secret = $settings->get('dropbox_app_secret');
                    config(['services.dropbox.app_secret' => $secret ? decrypt($secret) : null]);
                }

                if (empty(config('services.google.client_id'))) {
                    config(['services.google.client_id' => $settings->get('google_client_id')]);
                }
                if (empty(config('services.google.client_secret'))) {
                    $secret = $settings->get('google_client_secret');
                    config(['services.google.client_secret' => $secret ? decrypt($secret) : null]);
                }
            } catch (\Exception $e) {
                // DB may not be available during migrations
            }
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
            $jobClass = $event->job->resolveName();
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
