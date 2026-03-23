<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SiteRecovered;
use App\Jobs\ResolveStatusPageIncident;

class ResolveStatusPageIncidentOnRecovery
{
    public function handle(SiteRecovered $event): void
    {
        ResolveStatusPageIncident::dispatch($event->site);
    }
}
