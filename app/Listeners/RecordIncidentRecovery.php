<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\IncidentResponseStatus;
use App\Events\SiteRecovered;
use App\Models\IncidentResponse;

class RecordIncidentRecovery
{
    public function handle(SiteRecovered $event): void
    {
        IncidentResponse::where('site_id', $event->site->id)
            ->whereIn('status', [
                IncidentResponseStatus::Pending,
                IncidentResponseStatus::Diagnosing,
                IncidentResponseStatus::Executing,
            ])
            ->where('trigger_type', 'site_down')
            ->update([
                'status' => IncidentResponseStatus::Resolved,
                'resolution_method' => 'self_recovered',
                'resolved_at' => now(),
                'summary' => "Site recovered on its own after {$event->downtimeMinutes} minutes.",
            ]);
    }
}
