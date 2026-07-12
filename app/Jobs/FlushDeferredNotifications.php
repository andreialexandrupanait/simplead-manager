<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * P1-21: releases notifications that were deferred during quiet hours. Runs every
 * minute; NotificationService::flushDeferred() is a no-op while quiet hours are
 * still active, so held (non-critical) alerts are delivered as soon as the window
 * closes rather than being silently dropped.
 */
class FlushDeferredNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        NotificationService::flushDeferred();
    }
}
