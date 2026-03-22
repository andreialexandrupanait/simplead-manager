<?php

namespace App\Listeners;

use App\Events\SiteWentDown;
use App\Services\ActivityLogger;

class LogSiteDown
{
    public function handle(SiteWentDown $event): void
    {
        ActivityLogger::siteDown($event->site, $event->reason);
    }
}
