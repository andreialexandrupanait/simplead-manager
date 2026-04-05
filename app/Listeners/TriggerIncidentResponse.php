<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\IncidentTriggerType;
use App\Events\SiteWentDown;
use App\Jobs\RunIncidentResponse;

class TriggerIncidentResponse
{
    public function handle(SiteWentDown $event): void
    {
        if (! config('incident-response.enabled', false)) {
            return;
        }

        RunIncidentResponse::dispatch(
            $event->site,
            IncidentTriggerType::SiteDown,
            'SiteWentDown',
            $event->incident->id,
            ['reason' => $event->reason],
        );
    }
}
