<?php

namespace App\Events;

use App\Models\Site;
use App\Models\UptimeIncident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SiteWentDown
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Site $site,
        public UptimeIncident $incident,
        public string $reason,
    ) {}
}
