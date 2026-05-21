<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\BudgetViolationMail;
use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Models\Site;
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
        /** @var Site $site */
        $site = $this->monitor->site;

        $count = count($this->violations);

        if ($count === 1) {
            $v = array_values($this->violations)[0];
            $summary = "\xE2\x9A\xA0\xEF\xB8\x8F Budget exceeded · *{$site->name}* — {$v['key']} {$v['actual']} (budget {$v['budget']})";
        } else {
            $summary = "\xE2\x9A\xA0\xEF\xB8\x8F {$count} budgets exceeded · *{$site->name}*";
        }

        $deepLink = '<'.route('sites.performance', $site).'|Open performance →>';

        $webhookPayload = [
            'violations' => array_values($this->violations),
            'test_id' => $this->test->id,
        ];

        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'budget_violation',
            summary: $summary,
            deepLink: $deepLink,
            severity: 'warning',
            webhookPayload: $webhookPayload,
            mailableClass: BudgetViolationMail::class,
            mailableArgs: [$this->monitor, $this->violations, $this->test],
        );
    }
}
