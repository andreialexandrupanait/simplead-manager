<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SiteWentDown;
use App\Jobs\NotifyIncident;

class NotifySiteDown
{
    public function handle(SiteWentDown $event): void
    {
        NotifyIncident::dispatch($event->incident, 'down');
    }
}
