<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SafeUpdate;
use App\Models\Site;
use App\Services\Backup\SiteOperationLock;
use App\Services\Notifications\NotificationService;
use App\Services\SafeUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunSafeUpdate implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 1800; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public function __construct(
        public SafeUpdate $safeUpdate,
        public ?int $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'safe-update-'.$this->safeUpdate->id;
    }

    public function handle(SafeUpdateService $safeUpdateService): void
    {
        /** @var Site $site */
        $site = $this->safeUpdate->site;

        // Serialize against restores / backups / other safe updates on this site.
        // Without this a safe update could run concurrently with a live restore
        // (P0-07). The token is passed down so the nested pre-update backup runs
        // under this lock rather than contending with it and silently skipping.
        $token = SiteOperationLock::acquire(
            $site->id,
            SiteOperationLock::OPERATION_SAFE_UPDATE,
            'safe-update:'.$this->safeUpdate->id,
        );

        if ($token === null) {
            $holder = SiteOperationLock::current($site->id);
            $holderLabel = $holder['operation'] ?? 'another operation';

            throw new \RuntimeException(
                "Safe update aborted: site #{$site->id} is busy with {$holderLabel}. "
                .'Refusing to update concurrently with another destructive operation.'
            );
        }

        try {
            $safeUpdateService->runSafeUpdate($this->safeUpdate, $this->userId, $token);
        } finally {
            SiteOperationLock::release($site->id, $token);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->safeUpdate->update([
            'status' => 'failed',
            'error_message' => $exception?->getMessage(),
            'completed_at' => now(),
        ]);

        /** @var Site $safeUpdateSite */
        $safeUpdateSite = $this->safeUpdate->site;
        NotificationService::notifySiteEvent(
            $safeUpdateSite,
            'safe_update_failed',
            'Safe Update Failed',
            "Safe update failed for {$this->safeUpdate->name}: ".($exception?->getMessage() ?? 'Unknown error'),
            [
                'Type' => $this->safeUpdate->type,
                'Name' => $this->safeUpdate->name,
                'Version' => "{$this->safeUpdate->from_version} â {$this->safeUpdate->to_version}",
            ],
            'critical',
        );
    }
}
