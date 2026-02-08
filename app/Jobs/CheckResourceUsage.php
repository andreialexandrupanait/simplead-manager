<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Notifications\NotificationService;
use App\Services\ResourceMonitorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckResourceUsage implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;
    public array $backoff = [15, 30];

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'resource-check-' . $this->site->id;
    }

    public function handle(ResourceMonitorService $service): void
    {
        $check = $service->fetchAndStore($this->site);
        $violations = $service->checkThresholds($check);

        $severityMap = [
            'disk_space_critical' => 'critical',
            'memory_critical' => 'critical',
            'disk_space_warning' => 'warning',
            'memory_warning' => 'warning',
            'cpu_warning' => 'warning',
        ];

        $titleMap = [
            'disk_space_critical' => 'Disk Space Critical',
            'disk_space_warning' => 'Disk Space Warning',
            'memory_critical' => 'Memory Critical',
            'memory_warning' => 'Memory Warning',
            'cpu_warning' => 'High CPU Usage',
        ];

        $messageMap = [
            'disk_space_critical' => "Disk usage is at {$check->disk_percentage}% on {$this->site->name}.",
            'disk_space_warning' => "Disk usage is at {$check->disk_percentage}% on {$this->site->name}.",
            'memory_critical' => "Memory usage is at {$check->memory_percentage}% on {$this->site->name}.",
            'memory_warning' => "Memory usage is at {$check->memory_percentage}% on {$this->site->name}.",
            'cpu_warning' => "CPU usage is at {$check->cpu_usage}% on {$this->site->name}.",
        ];

        foreach ($violations as $violation) {
            NotificationService::notifySiteEvent(
                $this->site,
                $violation,
                $titleMap[$violation] ?? 'Resource Alert',
                $messageMap[$violation] ?? "Resource threshold exceeded on {$this->site->name}.",
                [
                    'CPU' => $check->cpu_usage ? $check->cpu_usage . '%' : 'N/A',
                    'Memory' => $check->memory_percentage ? $check->memory_percentage . '%' : 'N/A',
                    'Disk' => $check->disk_percentage ? $check->disk_percentage . '%' : 'N/A',
                ],
                $severityMap[$violation] ?? 'warning',
            );
        }
    }
}
