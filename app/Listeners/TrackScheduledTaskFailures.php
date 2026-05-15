<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Notifications\NotificationService;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks consecutive failures of scheduled tasks. Once a task has failed
 * THRESHOLD times in a row, fires a critical notification.
 *
 * Wired in app/Providers/AppServiceProvider.php to both events.
 */
class TrackScheduledTaskFailures
{
    private const THRESHOLD = 3;

    private const ALERT_COOLDOWN_SECONDS = 3600;

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $name = $this->taskName($event->task);
        $key = "schedule_failures:{$name}";
        $count = (int) Cache::increment($key);
        Cache::put($key, $count, 86400);

        if ($count < self::THRESHOLD) {
            return;
        }

        $alertKey = "schedule_failures_alerted:{$name}";
        if (Cache::has($alertKey)) {
            return;
        }

        $message = $event->exception
            ? 'Last error: '.substr($event->exception->getMessage(), 0, 500)
            : 'No exception details available.';

        NotificationService::notifyAppEvent(
            event: 'scheduled_task_failing',
            title: "Scheduled task failing: {$name}",
            message: "[{$name}] failed {$count} times in a row. {$message}",
            severity: 'critical',
        );

        Cache::put($alertKey, now()->toIso8601String(), self::ALERT_COOLDOWN_SECONDS);
    }

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $name = $this->taskName($event->task);
        Cache::forget("schedule_failures:{$name}");
        Cache::forget("schedule_failures_alerted:{$name}");
    }

    private function taskName(object $task): string
    {
        return $task->description
            ?? (method_exists($task, 'getSummaryForDisplay') ? $task->getSummaryForDisplay() : 'unknown');
    }
}
