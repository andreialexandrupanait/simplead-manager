<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Site;
use App\Models\UptimeIncident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SiteRecovered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Site $site,
        public UptimeIncident $incident,
        public int $downtimeMinutes,
    ) {}
}
