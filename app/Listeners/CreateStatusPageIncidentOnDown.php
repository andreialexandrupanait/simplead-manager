<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SiteWentDown;
use App\Jobs\CreateStatusPageIncident;

class CreateStatusPageIncidentOnDown
{
    public function handle(SiteWentDown $event): void
    {
        CreateStatusPageIncident::dispatch($event->site, $event->reason);
    }
}
