<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\IncidentResponderService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunIncidentResponse implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * P2-56: the executor can run nested SYNCHRONOUS work in-band — createBackup()
     * calls CreateBackup::dispatchSync() (own budget: CreateBackup::$timeout, 2700s)
     * and runSafeUpdate() dispatchSyncs another backup. If this job's timeout were
     * below the nested backup budget, the worker would be SIGKILLed mid-backup,
     * corrupting the recovery point it was creating. So the incident-response job
     * timeout MUST be >= the nested CreateBackup dispatchSync budget, plus headroom
     * for the surrounding diagnosis/AI/action bookkeeping. Kept in sync with the
     * `supervisor-incident-response` supervisor timeout in config/horizon.php and
     * guarded by a config-consistency test.
     */
    public int $timeout = 3000; // >= CreateBackup::$timeout (2700) + 300s headroom

    public int $uniqueFor = 3600; // P1-07: release stale unique lock after a hard kill (> $timeout)

    public function __construct(
        public Site $site,
        public IncidentTriggerType $triggerType,
        public string $triggerSource,
        public ?int $triggerSourceId = null,
        public array $context = [],
    ) {
        $this->onQueue('incident-response');
    }

    public function uniqueId(): string
    {
        return "incident-response-{$this->site->id}-{$this->triggerType->value}";
    }

    public function handle(IncidentResponderService $service): void
    {
        $trackingId = $this->uniqueId();
        JobTracker::start($trackingId, "Running incident response: {$this->triggerType->label()}");

        try {
            $response = $service->respond(
                $this->site,
                $this->triggerType,
                $this->triggerSource,
                $this->triggerSourceId,
                $this->context,
            );

            JobTracker::complete($trackingId, "Incident response {$response->status->value}: {$response->summary}");
        } catch (\Throwable $e) {
            Log::error("RunIncidentResponse failed for site {$this->site->id}: {$e->getMessage()}");
            JobTracker::fail($trackingId, $e->getMessage());
        }
    }

    public function failed(?\Throwable $exception): void
    {
        JobTracker::fail($this->uniqueId(), 'Incident response job failed: '.($exception?->getMessage() ?? 'Unknown'));

        // A killed/timed-out worker leaves the IncidentResponse row non-terminal,
        // which silently poisons the cooldown window (audit SEC-A2-11). failed()
        // runs on a fresh instance with no model ID, so resolve by natural key.
        IncidentResponse::where('site_id', $this->site->id)
            ->where('trigger_type', $this->triggerType)
            ->whereIn('status', [
                IncidentResponseStatus::Pending,
                IncidentResponseStatus::Diagnosing,
                IncidentResponseStatus::Executing,
            ])
            ->latest()
            ->first()
            ?->markFailed('Job terminated: '.($exception?->getMessage() ?? 'worker killed or timed out'));
    }
}
