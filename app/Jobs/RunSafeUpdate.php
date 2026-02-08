<?php

namespace App\Jobs;

use App\Models\SafeUpdate;
use App\Services\Notifications\NotificationService;
use App\Services\SafeUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunSafeUpdate implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        public SafeUpdate $safeUpdate,
    ) {}

    public function uniqueId(): string
    {
        return 'safe-update-' . $this->safeUpdate->id;
    }

    public function handle(SafeUpdateService $safeUpdateService): void
    {
        $safeUpdateService->runSafeUpdate($this->safeUpdate);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->safeUpdate->update([
            'status' => 'failed',
            'error_message' => $exception?->getMessage(),
            'completed_at' => now(),
        ]);

        NotificationService::notifySiteEvent(
            $this->safeUpdate->site,
            'safe_update_failed',
            'Safe Update Failed',
            "Safe update failed for {$this->safeUpdate->name}: " . ($exception?->getMessage() ?? 'Unknown error'),
            [
                'Type' => $this->safeUpdate->type,
                'Name' => $this->safeUpdate->name,
                'Version' => "{$this->safeUpdate->from_version} → {$this->safeUpdate->to_version}",
            ],
            'critical',
        );
    }
}
