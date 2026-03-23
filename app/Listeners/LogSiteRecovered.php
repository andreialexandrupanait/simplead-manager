<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SiteRecovered;
use App\Services\ActivityLogger;

class LogSiteRecovered
{
    public function handle(SiteRecovered $event): void
    {
        ActivityLogger::siteRecovered($event->site, $event->downtimeMinutes);
    }
}
