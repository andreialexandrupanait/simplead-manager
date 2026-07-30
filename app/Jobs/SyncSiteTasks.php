<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SiteTaskSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SPEC §11 — daily pull of per-site tasks from app.simplead.ro.
 *
 * Read-only and one-directional. With no token configured the whole thing is a
 * no-op, so the job is safe to schedule before the credential exists.
 */
class SyncSiteTasks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'sync-site-tasks';
    }

    public function handle(SiteTaskSyncService $sync): void
    {
        $result = $sync->syncAll();

        Log::info('Site task sync finished.', $result);
    }
}
