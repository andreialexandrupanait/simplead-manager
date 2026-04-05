<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\IncidentTriggerType;
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

    public int $timeout = 900;

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
    }
}
