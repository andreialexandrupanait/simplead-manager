<?php

use App\Jobs\CheckUptime;
use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Uptime monitoring: dispatch checks for due monitors every minute
Schedule::call(function () {
    UptimeMonitor::active()
        ->due()
        ->each(fn (UptimeMonitor $monitor) => CheckUptime::dispatch($monitor));
})->everyMinute()->name('uptime-checks')->withoutOverlapping();

// SSL certificate checks — every 12 hours
Schedule::call(function () {
    \App\Models\SslCertificate::where(function ($q) {
        $q->whereNull('next_check_at')
          ->orWhere('next_check_at', '<=', now());
    })->each(fn ($cert) => \App\Jobs\CheckSslCertificate::dispatch($cert));
})->cron('0 */12 * * *')->name('ssl-checks')->withoutOverlapping();

// Domain expiry checks — daily
Schedule::call(function () {
    \App\Models\DomainMonitor::where(function ($q) {
        $q->whereNull('next_check_at')
          ->orWhere('next_check_at', '<=', now());
    })->each(fn ($domain) => \App\Jobs\CheckDomainExpiry::dispatch($domain));
})->daily()->name('domain-checks')->withoutOverlapping();

// Daily pruning: remove checks older than 90 days
Schedule::command('model:prune', ['--model' => [UptimeCheck::class]])->daily();

// WordPress sync — every 6 hours
Schedule::call(function () {
    \App\Models\Site::where('is_connected', true)
        ->whereNotNull('api_endpoint')
        ->each(fn ($site) => \App\Jobs\SyncWordPressSite::dispatch($site));
})->everySixHours()->name('wordpress-sync')->withoutOverlapping();

// Scheduled backups — every 15 minutes, check for due backup configs
Schedule::call(function () {
    \App\Models\BackupConfig::where('is_enabled', true)
        ->where('next_backup_at', '<=', now())
        ->with('site')
        ->each(function (\App\Models\BackupConfig $config) {
            if (!$config->site?->is_connected) {
                return;
            }

            \App\Jobs\CreateBackup::dispatch(
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

            // Apply configured time
            if ($config->time) {
                [$hour, $minute] = explode(':', $config->time);
                $next->setTime((int) $hour, (int) $minute);
            }

            $config->update(['next_backup_at' => $next]);
        });
})->everyFifteenMinutes()->name('scheduled-backups')->withoutOverlapping();

// Expired backup cleanup — daily
Schedule::call(function () {
    \App\Models\Backup::where('expires_at', '<=', now())
        ->where('is_locked', false)
        ->each(function (\App\Models\Backup $backup) {
            try {
                $destination = $backup->storageDestination;
                if ($destination && $backup->file_path) {
                    $driver = \App\Services\Backup\Storage\StorageFactory::make($destination);
                    $driver->delete($backup->file_path);
                    $destination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
                }
                $backup->delete();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to clean expired backup {$backup->id}: {$e->getMessage()}");
            }
        });
})->daily()->name('expired-backup-cleanup')->withoutOverlapping();
