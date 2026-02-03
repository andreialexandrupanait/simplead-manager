<?php

namespace App\Jobs;

use App\Models\LinkMonitor;
use App\Models\LinkScan;
use App\Services\ActivityLogger;
use App\Services\LinkCheckerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunLinkScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;

    public function __construct(
        public LinkMonitor $monitor,
        public string $trigger = 'manual',
    ) {}

    public function handle(): void
    {
        $site = $this->monitor->site;

        $scan = LinkScan::create([
            'site_id' => $site->id,
            'link_monitor_id' => $this->monitor->id,
            'status' => 'pending',
            'trigger' => $this->trigger,
        ]);

        try {
            $service = new LinkCheckerService($this->monitor, $scan);
            $service->scan();

            // Refresh scan to get final stats
            $scan->refresh();

            ActivityLogger::linkScanCompleted($site, $scan->broken_links ?? 0, $scan->total_links ?? 0);

            // Check alerts
            if ($this->monitor->alert_on_broken && $scan->broken_links >= $this->monitor->alert_threshold) {
                NotifyBrokenLinks::dispatch($this->monitor, $scan, $scan->broken_links);
            }
        } catch (\Exception $e) {
            $scan->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => $scan->started_at ? (int) now()->diffInSeconds($scan->started_at) : null,
            ]);

            $this->monitor->update(['last_scan_status' => 'failed']);

            report($e);
        }

        // Schedule next scan
        $this->scheduleNext();
    }

    private function scheduleNext(): void
    {
        $nextScanAt = match ($this->monitor->frequency) {
            'daily' => now()->addDay(),
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            default => null, // manual
        };

        if ($nextScanAt && $this->monitor->scan_time) {
            [$hour, $minute] = explode(':', $this->monitor->scan_time);
            $nextScanAt->setTime((int) $hour, (int) $minute);
        }

        $this->monitor->update(['next_scan_at' => $nextScanAt]);
    }
}
