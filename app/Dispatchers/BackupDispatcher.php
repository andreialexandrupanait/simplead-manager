<?php

namespace App\Dispatchers;

use App\Jobs\CreateBackup;
use App\Models\BackupConfig;
use App\Services\CircuitBreakerService;

class BackupDispatcher
{
    /**
     * Dispatch due backup jobs.
     * Called every minute from the scheduler.
     */
    public function __invoke(): void
    {
        CircuitBreakerService::checkHalfOpen();

        BackupConfig::query()
            ->where('is_enabled', true)
            ->where('next_backup_at', '<=', now())
            ->whereHas('site', fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('is_connected', true)
            )
            ->whereHas('site.healthState', fn ($q) => $q
                ->where('circuit_state', '!=', 'open')
                ->where('is_monitoring_disabled', false)
            )
            ->with('site')
            ->each(function (BackupConfig $config) {
                CreateBackup::dispatch(
                    $config->site,
                    $config->type,
                    'scheduled',
                    $config->storage_destination_id
                );

                // Calculate next backup time
                $next = match ($config->frequency) {
                    'daily' => now()->addDay(),
                    'weekly' => now()->addWeek(),
                    'monthly' => now()->addMonth(),
                    default => now()->addDay(),
                };

                if ($config->time) {
                    [$hour, $minute] = explode(':', $config->time);
                    $next->setTime((int) $hour, (int) $minute);
                }

                $config->update(['next_backup_at' => $next]);
            });
    }
}
