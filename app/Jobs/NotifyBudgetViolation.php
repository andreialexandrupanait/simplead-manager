<?php

namespace App\Jobs;

use App\Mail\BudgetViolationMail;
use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyBudgetViolation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [30, 60, 120];

    public function __construct(
        public PerformanceMonitor $monitor,
        public array $violations,
        public PerformanceTest $test
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $site = $this->monitor->site;

        $violationLines = [];
        foreach ($this->violations as $v) {
            $violationLines[] = "{$v['key']}: {$v['actual']} (budget: {$v['budget']})";
        }

        $title = "BUDGET EXCEEDED: {$site->name}";
        $message = count($this->violations) . " performance budget(s) exceeded:\n" . implode("\n", $violationLines);

        $fields = [
            ['title' => 'Site', 'value' => $site->name, 'short' => true],
            ['title' => 'Violations', 'value' => (string) count($this->violations), 'short' => true],
        ];

        $webhookPayload = [
            'violations' => array_values($this->violations),
            'test_id' => $this->test->id,
        ];

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'budget_violation',
            title: $title,
            message: $message,
            fields: $fields,
            severity: 'warning',
            webhookPayload: $webhookPayload,
            mailableClass: BudgetViolationMail::class,
            mailableArgs: [$this->monitor, $this->violations, $this->test],
        );
    }
}
