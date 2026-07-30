<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\SitePresetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * SPEC §13 — apply the "Standard SimpleAD" preset package to one site (used by
 * the fleet bulk action and by the at-connection hook). Only touches connected
 * sites; the service applies the auto group everywhere and the Woo group only
 * where WooCommerce is detected, then pushes to the connector.
 */
class ApplySitePresetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Site $site,
        public readonly ?int $userId = null,
    ) {}

    public function handle(SitePresetService $presets): void
    {
        if (! $this->site->is_connected) {
            return;
        }

        $presets->applyStandardPackage($this->site);
    }
}
