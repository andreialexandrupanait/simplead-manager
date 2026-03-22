<?php

namespace App\Listeners;

use App\Events\SiteRecovered;
use App\Jobs\NotifyIncident;

class NotifySiteRecovered
{
    public function handle(SiteRecovered $event): void
    {
        NotifyIncident::dispatch($event->incident->fresh(), 'recovery');
    }
}
