<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Jobs\CheckBrokenResources;
use App\Models\SeoMonitor;
use Illuminate\Support\Facades\Log;

class BrokenResourceDispatcher
{
    public function __invoke(): void
    {
        SeoMonitor::query()
            ->where('crawl_enabled', true)
            ->where(fn ($q) => $q->whereNull('next_crawl_at')->orWhere('next_crawl_at', '<=', now()))
            ->whereHas('site', fn ($q) => $q->whereNull('deleted_at')->where('is_prospect', false))
            ->with('site')
            ->each(function (SeoMonitor $monitor) {
                $audit = $monitor->site->seoAudits()
                    ->where('status', 'completed')
                    ->latest('scanned_at')
                    ->first();

                if (! $audit) {
                    return;
                }

                CheckBrokenResources::dispatch($monitor->site, $audit);

                $monitor->update([
                    'last_crawl_at' => now(),
                    'next_crawl_at' => now()->addDays($monitor->crawl_interval_days ?? 1),
                ]);

                Log::info('Broken resource check dispatched', [
                    'site_id' => $monitor->site_id,
                    'audit_id' => $audit->id,
                ]);
            });
    }
}
