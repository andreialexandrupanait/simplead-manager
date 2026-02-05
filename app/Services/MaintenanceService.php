<?php

namespace App\Services;

use App\Models\MaintenanceWindow;
use App\Models\Site;
use App\Models\StatusPage;
use App\Services\Notifications\NotificationService;

class MaintenanceService
{
    public static function isSiteInMaintenance(Site $site, string $monitorType): bool
    {
        $window = $site->activeMaintenanceWindow;

        if (!$window) {
            return false;
        }

        return $window->isPausing($monitorType);
    }

    public static function processScheduledWindows(): void
    {
        MaintenanceWindow::where('status', 'scheduled')
            ->where('scheduled_start_at', '<=', now())
            ->each(function (MaintenanceWindow $window) {
                static::startMaintenance($window);
            });
    }

    public static function processEndingWindows(): void
    {
        MaintenanceWindow::where('status', 'active')
            ->where('scheduled_end_at', '<=', now())
            ->each(function (MaintenanceWindow $window) {
                static::endMaintenance($window);
            });
    }

    public static function startMaintenance(MaintenanceWindow $window): void
    {
        $window->update([
            'status' => 'active',
            'actual_start_at' => now(),
        ]);

        $site = $window->site;

        ActivityLogger::log(
            type: 'maintenance',
            severity: 'info',
            title: "Maintenance started: {$window->title}",
            description: "Maintenance window started for {$site->name}",
            site: $site,
            metadata: ['window_id' => $window->id],
            icon: 'wrench',
        );

        // Create status page maintenance incidents
        if ($window->update_status_page) {
            $statusPages = StatusPage::whereHas('statusPageSites', function ($q) use ($site) {
                $q->where('site_id', $site->id);
            })->get();

            foreach ($statusPages as $statusPage) {
                StatusPageService::createMaintenanceIncident($statusPage, $window);
            }
        }

        if ($window->notify_on_start) {
            $paused = collect(['uptime', 'ssl', 'performance', 'backups', 'links'])
                ->filter(fn ($type) => $window->{"pause_{$type}"})
                ->implode(', ');

            NotificationService::notifySiteEvent(
                site: $site,
                event: 'maintenance_start',
                title: "Maintenance Started: {$site->name}",
                message: "Maintenance window \"{$window->title}\" has started. Paused monitors: {$paused}.",
                fields: [
                    ['title' => 'Window', 'value' => $window->title, 'short' => true],
                    ['title' => 'Paused', 'value' => $paused ?: 'None', 'short' => true],
                    ['title' => 'Ends At', 'value' => $window->scheduled_end_at->format('M d, H:i'), 'short' => true],
                ],
                severity: 'info',
            );
        }
    }

    public static function endMaintenance(MaintenanceWindow $window): void
    {
        $window->update([
            'status' => 'completed',
            'actual_end_at' => now(),
        ]);

        $site = $window->site;

        $duration = $window->actual_start_at
            ? (int) $window->actual_start_at->diffInMinutes(now())
            : 0;

        ActivityLogger::log(
            type: 'maintenance',
            severity: 'success',
            title: "Maintenance completed: {$window->title}",
            description: "Maintenance window completed for {$site->name} after {$duration} minutes",
            site: $site,
            metadata: ['window_id' => $window->id, 'duration_minutes' => $duration],
            icon: 'wrench',
        );

        // Resolve status page maintenance incidents
        if ($window->update_status_page) {
            $statusPages = StatusPage::whereHas('statusPageSites', function ($q) use ($site) {
                $q->where('site_id', $site->id);
            })->get();

            foreach ($statusPages as $statusPage) {
                StatusPageService::resolveMaintenanceIncident($statusPage, $window);
            }
        }

        if ($window->notify_on_end) {
            NotificationService::notifySiteEvent(
                site: $site,
                event: 'maintenance_end',
                title: "Maintenance Completed: {$site->name}",
                message: "Maintenance window \"{$window->title}\" has ended after {$duration} minutes.",
                fields: [
                    ['title' => 'Window', 'value' => $window->title, 'short' => true],
                    ['title' => 'Duration', 'value' => "{$duration} min", 'short' => true],
                ],
                severity: 'success',
            );
        }
    }

    public static function cancelMaintenance(MaintenanceWindow $window): void
    {
        $wasActive = $window->status === 'active';

        $window->update([
            'status' => 'cancelled',
            'actual_end_at' => $wasActive ? now() : null,
        ]);

        $site = $window->site;

        ActivityLogger::log(
            type: 'maintenance',
            severity: 'info',
            title: "Maintenance cancelled: {$window->title}",
            description: "Maintenance window cancelled for {$site->name}",
            site: $site,
            metadata: ['window_id' => $window->id],
            icon: 'wrench',
        );
    }
}
