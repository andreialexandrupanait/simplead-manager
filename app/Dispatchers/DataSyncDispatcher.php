<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Jobs\FetchAnalyticsData;
use App\Jobs\FetchSearchConsoleData;
use App\Jobs\SyncCloudflareZone;
use App\Jobs\SyncWordPressSite;
use App\Models\AnalyticsConnection;
use App\Models\SearchConsoleConnection;
use App\Models\Site;
use App\Models\SiteCloudflare;
use App\Services\CircuitBreakerService;
use Illuminate\Database\Eloquent\Builder;

class DataSyncDispatcher
{
    /**
     * LEFT-JOIN-safe health-state gate (E-28 / P1-10). Includes sites whose
     * circuit breaker is not open and whose monitoring is not disabled — AND
     * sites with NO health-state row, which the previous inner-join
     * `whereHas('site.healthState')` silently dropped from sync forever.
     */
    private function healthStateGate(string $relation = 'site.healthState'): \Closure
    {
        return function (Builder $query) use ($relation) {
            $query->whereDoesntHave($relation)
                ->orWhereHas($relation, function (Builder $q) {
                    $q->where('circuit_state', '!=', 'open')
                        ->where('is_monitoring_disabled', false);
                });
        };
    }

    /**
     * Dispatch due data sync jobs: analytics, search console, cloudflare, WP sync.
     * Called every minute from the scheduler.
     */
    public function __invoke(): void
    {
        CircuitBreakerService::checkHalfOpen();

        $this->dispatchAnalyticsSync();
        $this->dispatchSearchConsoleSync();
        $this->dispatchCloudflareSync();
        $this->dispatchWordPressSync();
    }

    private function dispatchAnalyticsSync(): void
    {
        AnalyticsConnection::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->where($this->healthStateGate())
            ->with('site')
            ->each(function (AnalyticsConnection $conn) {
                /** @var \App\Models\Site $site */
                $site = $conn->site;
                FetchAnalyticsData::dispatch($site, '28d');
                $conn->update(['next_sync_at' => now()->addMinutes($conn->interval_minutes)]);
            });
    }

    private function dispatchSearchConsoleSync(): void
    {
        SearchConsoleConnection::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->where($this->healthStateGate())
            ->with('site')
            ->each(function (SearchConsoleConnection $conn) {
                /** @var \App\Models\Site $site */
                $site = $conn->site;
                FetchSearchConsoleData::dispatch($site, '28d');
                $conn->update(['next_sync_at' => now()->addMinutes($conn->interval_minutes)]);
            });
    }

    private function dispatchCloudflareSync(): void
    {
        SiteCloudflare::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at'))
            ->each(function (SiteCloudflare $sc) {
                SyncCloudflareZone::dispatch($sc);
                $sc->update(['next_sync_at' => now()->addMinutes($sc->interval_minutes)]);
            });
    }

    private function dispatchWordPressSync(): void
    {
        Site::query()
            ->where('is_connected', true)
            ->whereNotNull('api_endpoint')
            ->whereNull('deleted_at')
            ->where($this->healthStateGate('healthState'))
            ->where(fn ($q) => $q
                ->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<=', now()->subHours(6))
            )
            ->each(fn (Site $site) => SyncWordPressSite::dispatch($site));
    }
}
