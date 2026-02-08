<?php

namespace App\Jobs;

use App\Models\RollbackPoint;
use App\Services\Notifications\NotificationService;
use App\Services\RollbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteRollback implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public array $backoff = [60, 180];

    public function __construct(
        public RollbackPoint $point,
    ) {}

    public function uniqueId(): string
    {
        return 'rollback-' . $this->point->id;
    }

    public function handle(RollbackService $rollbackService): void
    {
        $rollbackService->executeRollback($this->point);

        NotificationService::notifySiteEvent(
            $this->point->site,
            'rollback_success',
            'Rollback Successful',
            "Successfully rolled back {$this->point->slug} from {$this->point->to_version} to {$this->point->from_version}.",
            [
                'Type' => $this->point->type,
                'Slug' => $this->point->slug,
                'Restored Version' => $this->point->from_version,
            ],
            'info',
        );
    }

    public function failed(?\Throwable $exception): void
    {
        NotificationService::notifySiteEvent(
            $this->point->site,
            'rollback_failed',
            'Rollback Failed',
            "Failed to rollback {$this->point->slug}: " . ($exception?->getMessage() ?? 'Unknown error'),
            [
                'Type' => $this->point->type,
                'Slug' => $this->point->slug,
                'Target Version' => $this->point->from_version,
            ],
            'critical',
        );
    }
}
